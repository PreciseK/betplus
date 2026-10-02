#!/usr/bin/env bash
set -e

REMOTE_BASE="/home/$USER/betplus"
RELEASE_NAME=$(ls -1 "$REMOTE_BASE/releases" | tail -n 1)
CURRENT_RELEASE="$REMOTE_BASE/releases/$RELEASE_NAME"

echo "=========================================================="
echo "Configuring release: $RELEASE_NAME"
echo "Current release path: $CURRENT_RELEASE"
echo "=========================================================="

# 1. Locate PHP 8.4 / 8.3 / 8.2 or cPanel ea-php binary
PHP_BIN=""
for candidate in \
  /usr/local/bin/ea-php84 \
  /opt/cpanel/ea-php84/root/usr/bin/php \
  /usr/local/bin/ea-php83 \
  /opt/cpanel/ea-php83/root/usr/bin/php \
  /usr/local/bin/ea-php82 \
  /opt/cpanel/ea-php82/root/usr/bin/php \
  /usr/local/bin/php \
  /usr/bin/php \
  php; do
  if command -v "$candidate" >/dev/null 2>&1; then
    VER=$("$candidate" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)
    if [ "$VER" -ge 80200 ]; then
      PHP_BIN="$candidate"
      break
    fi
  fi
done

if [ -z "$PHP_BIN" ]; then
  PHP_BIN="php"
fi

echo "Using PHP binary: $PHP_BIN ($($PHP_BIN -v 2>/dev/null | head -n 1 || echo 'unknown'))"

# 2. Symlink shared .env into platform and ussd
if [ -f "$REMOTE_BASE/shared/.env" ]; then
  ln -sfn "$REMOTE_BASE/shared/.env" "$CURRENT_RELEASE/apps/platform/.env"
  ln -sfn "$REMOTE_BASE/shared/.env" "$CURRENT_RELEASE/apps/ussd/.env"
  echo "Linked shared/.env into apps/platform/.env and apps/ussd/.env"
  
  # Ensure APP_KEY line exists in shared/.env
  if ! grep -q "^APP_KEY=" "$REMOTE_BASE/shared/.env"; then
    echo "APP_KEY=" >> "$REMOTE_BASE/shared/.env"
  fi

  # Generate valid base64 APP_KEY if empty
  if ! grep -qE '^APP_KEY=base64:.+' "$REMOTE_BASE/shared/.env"; then
    echo "Generating new base64 APP_KEY in shared/.env..."
    KEY=$($PHP_BIN -r 'echo "base64:" . base64_encode(random_bytes(32));')
    sed -i "s|^APP_KEY=.*|APP_KEY=$KEY|" "$REMOTE_BASE/shared/.env"
    echo "Set APP_KEY successfully in shared/.env"
  fi

  # Ensure safe defaults for database, sessions, and cache if using sqlite or missing
  if ! grep -q "^DB_CONNECTION=" "$REMOTE_BASE/shared/.env"; then
    echo "DB_CONNECTION=sqlite" >> "$REMOTE_BASE/shared/.env"
  fi
  if grep -q "^DB_CONNECTION=sqlite" "$REMOTE_BASE/shared/.env"; then
    if ! grep -q "^DB_DATABASE=" "$REMOTE_BASE/shared/.env"; then
      echo "DB_DATABASE=$REMOTE_BASE/shared/database/database.sqlite" >> "$REMOTE_BASE/shared/.env"
    else
      sed -i "s|^DB_DATABASE=.*|DB_DATABASE=$REMOTE_BASE/shared/database/database.sqlite|" "$REMOTE_BASE/shared/.env"
    fi
  fi

  if ! grep -q "^VAULT_DB_CONNECTION=" "$REMOTE_BASE/shared/.env"; then
    echo "VAULT_DB_CONNECTION=sqlite" >> "$REMOTE_BASE/shared/.env"
  fi
  if grep -q "^VAULT_DB_CONNECTION=sqlite" "$REMOTE_BASE/shared/.env"; then
    if ! grep -q "^VAULT_DB_DATABASE=" "$REMOTE_BASE/shared/.env"; then
      echo "VAULT_DB_DATABASE=$REMOTE_BASE/shared/database/vault.sqlite" >> "$REMOTE_BASE/shared/.env"
    else
      sed -i "s|^VAULT_DB_DATABASE=.*|VAULT_DB_DATABASE=$REMOTE_BASE/shared/database/vault.sqlite|" "$REMOTE_BASE/shared/.env"
    fi
  fi

  if ! grep -q "^SESSION_DRIVER=" "$REMOTE_BASE/shared/.env"; then
    echo "SESSION_DRIVER=file" >> "$REMOTE_BASE/shared/.env"
  elif grep -q "^DB_CONNECTION=sqlite" "$REMOTE_BASE/shared/.env" && grep -q "^SESSION_DRIVER=database" "$REMOTE_BASE/shared/.env"; then
    # SQLite cannot handle concurrent database session locks — switch to file driver
    sed -i 's|^SESSION_DRIVER=database|SESSION_DRIVER=file|' "$REMOTE_BASE/shared/.env"
  fi
  if ! grep -q "^CACHE_STORE=" "$REMOTE_BASE/shared/.env"; then
    echo "CACHE_STORE=file" >> "$REMOTE_BASE/shared/.env"
  elif grep -q "^DB_CONNECTION=sqlite" "$REMOTE_BASE/shared/.env" && grep -q "^CACHE_STORE=database" "$REMOTE_BASE/shared/.env"; then
    sed -i 's|^CACHE_STORE=database|CACHE_STORE=file|' "$REMOTE_BASE/shared/.env"
  fi

  # Generate BACKOFFICE_MFA_ENCRYPTION_KEY if missing or empty
  if ! grep -qE '^BACKOFFICE_MFA_ENCRYPTION_KEY=[A-Za-z0-9+/=]{40,}' "$REMOTE_BASE/shared/.env"; then
    MFA_KEY=$($PHP_BIN -r 'echo base64_encode(random_bytes(32));')
    if grep -q "^BACKOFFICE_MFA_ENCRYPTION_KEY=" "$REMOTE_BASE/shared/.env"; then
      sed -i "s|^BACKOFFICE_MFA_ENCRYPTION_KEY=.*|BACKOFFICE_MFA_ENCRYPTION_KEY=$MFA_KEY|" "$REMOTE_BASE/shared/.env"
    else
      echo "BACKOFFICE_MFA_ENCRYPTION_KEY=$MFA_KEY" >> "$REMOTE_BASE/shared/.env"
    fi
    echo "Configured BACKOFFICE_MFA_ENCRYPTION_KEY in shared/.env"
  fi

  # Generate VAULT_ENCRYPTION_KEY if missing or empty
  if ! grep -qE '^VAULT_ENCRYPTION_KEY=[A-Za-z0-9+/=]{40,}' "$REMOTE_BASE/shared/.env"; then
    V_KEY=$($PHP_BIN -r 'echo base64_encode(random_bytes(32));')
    if grep -q "^VAULT_ENCRYPTION_KEY=" "$REMOTE_BASE/shared/.env"; then
      sed -i "s|^VAULT_ENCRYPTION_KEY=.*|VAULT_ENCRYPTION_KEY=$V_KEY|" "$REMOTE_BASE/shared/.env"
    else
      echo "VAULT_ENCRYPTION_KEY=$V_KEY" >> "$REMOTE_BASE/shared/.env"
    fi
    echo "Configured VAULT_ENCRYPTION_KEY in shared/.env"
  fi

  # Generate USSD_GATEWAY_SHARED_SECRET if missing or empty
  if ! grep -qE '^USSD_GATEWAY_SHARED_SECRET=[A-Za-z0-9+/=]{30,}' "$REMOTE_BASE/shared/.env"; then
    USSD_SECRET=$($PHP_BIN -r 'echo bin2hex(random_bytes(32));')
    if grep -q "^USSD_GATEWAY_SHARED_SECRET=" "$REMOTE_BASE/shared/.env"; then
      sed -i "s|^USSD_GATEWAY_SHARED_SECRET=.*|USSD_GATEWAY_SHARED_SECRET=$USSD_SECRET|" "$REMOTE_BASE/shared/.env"
    else
      echo "USSD_GATEWAY_SHARED_SECRET=$USSD_SECRET" >> "$REMOTE_BASE/shared/.env"
    fi
    echo "Configured USSD_GATEWAY_SHARED_SECRET in shared/.env"
  fi

  # Generate USSD_SESSION_ENCRYPTION_KEY if missing or empty
  if ! grep -qE '^USSD_SESSION_ENCRYPTION_KEY=[A-Za-z0-9+/=]{40,}' "$REMOTE_BASE/shared/.env"; then
    USSD_ENC_KEY=$($PHP_BIN -r 'echo base64_encode(random_bytes(32));')
    if grep -q "^USSD_SESSION_ENCRYPTION_KEY=" "$REMOTE_BASE/shared/.env"; then
      sed -i "s|^USSD_SESSION_ENCRYPTION_KEY=.*|USSD_SESSION_ENCRYPTION_KEY=$USSD_ENC_KEY|" "$REMOTE_BASE/shared/.env"
    else
      echo "USSD_SESSION_ENCRYPTION_KEY=$USSD_ENC_KEY" >> "$REMOTE_BASE/shared/.env"
    fi
    echo "Configured USSD_SESSION_ENCRYPTION_KEY in shared/.env"
  fi

  # Ensure USSD session directory exists — 700 so only the web-server user can read session files
  mkdir -p "$REMOTE_BASE/shared/ussd-sessions"
  chmod 700 "$REMOTE_BASE/shared/ussd-sessions"
  if ! grep -q "^USSD_SESSION_STORE_DIR=" "$REMOTE_BASE/shared/.env"; then
    echo "USSD_SESSION_STORE_DIR=$REMOTE_BASE/shared/ussd-sessions" >> "$REMOTE_BASE/shared/.env"
  fi

  # Ensure Cloudflare & R2 Object Storage credentials exist in shared/.env
  if ! grep -q "^CLOUDFLARE_ACCOUNT_ID=" "$REMOTE_BASE/shared/.env"; then
    if [ -f "$CURRENT_RELEASE/apps/platform/.env" ]; then
      grep -E '^(CLOUDFLARE_|AWS_|NEXT_PUBLIC_CLOUDFLARE_)' "$CURRENT_RELEASE/apps/platform/.env" >> "$REMOTE_BASE/shared/.env" || true
      echo "Synchronized Cloudflare credentials from apps/platform/.env to shared/.env"
    fi
  fi

  # Ensure CORS includes backoffice subdomain in shared/.env
  if grep -q "^CORS_ALLOWED_ORIGINS=" "$REMOTE_BASE/shared/.env"; then
    if ! grep -q "backoffice\." "$REMOTE_BASE/shared/.env"; then
      sed -i 's|^CORS_ALLOWED_ORIGINS=.*|&,https://backoffice.betplus.com.ng|' "$REMOTE_BASE/shared/.env"
      echo "Appended backoffice subdomain to CORS_ALLOWED_ORIGINS in shared/.env"
    fi
  fi
else
  echo "WARNING: $REMOTE_BASE/shared/.env does not exist yet. Please create it on the server."
fi

# Ensure USSD vendor autoloader exists (fallback if CI runner did not build it)
if [ ! -f "$CURRENT_RELEASE/apps/ussd/vendor/autoload.php" ]; then
  echo "Notice: apps/ussd/vendor/autoload.php missing. Attempting server-side composer install..."
  if command -v composer >/dev/null 2>&1; then
    (cd "$CURRENT_RELEASE/apps/ussd" && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader) || true
  fi
fi

# 3. Symlink shared storage
mkdir -p "$REMOTE_BASE/shared/storage/framework/sessions"
mkdir -p "$REMOTE_BASE/shared/storage/framework/views"
mkdir -p "$REMOTE_BASE/shared/storage/framework/cache"
mkdir -p "$REMOTE_BASE/shared/storage/logs"
chmod -R 750 "$REMOTE_BASE/shared/storage"
ln -sfn "$REMOTE_BASE/shared/storage" "$CURRENT_RELEASE/apps/platform/storage"

# 4. Ensure persistent database files exist across releases
mkdir -p "$REMOTE_BASE/shared/database"
touch "$REMOTE_BASE/shared/database/database.sqlite"
touch "$REMOTE_BASE/shared/database/vault.sqlite"
chmod 700 "$REMOTE_BASE/shared/database"
chmod 600 "$REMOTE_BASE/shared/database/database.sqlite"
chmod 600 "$REMOTE_BASE/shared/database/vault.sqlite"
mkdir -p "$CURRENT_RELEASE/apps/platform/database"
ln -sfn "$REMOTE_BASE/shared/database/database.sqlite" "$CURRENT_RELEASE/apps/platform/database/database.sqlite"
ln -sfn "$REMOTE_BASE/shared/database/vault.sqlite" "$CURRENT_RELEASE/apps/platform/database/vault.sqlite"

# 5. Database Migrations & Caches
cd "$CURRENT_RELEASE/apps/platform"
echo "Clearing and warming production caches..."
$PHP_BIN artisan optimize:clear || true

echo "Running database migrations..."
$PHP_BIN artisan migrate --force --verbose || echo "Notice: Migrations completed or skipped (check DB config in shared/.env)"

echo "Database Migration Status:"
$PHP_BIN artisan migrate:status || true

echo "Seeding initial game data..."
$PHP_BIN artisan db:seed --force || echo "Notice: Seeding completed or skipped"

echo "Ensuring Back-Office Admin user exists..."
# Generate a one-time random password — printed once here in the deploy log (accessible
# only to repository admins via GitHub Actions). Change it immediately after first login.
ADMIN_PASS=$($PHP_BIN -r 'echo bin2hex(random_bytes(12));')
$PHP_BIN -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\Contracts\Console\Kernel::class);
\$kernel->bootstrap();
try {
    if (!\App\Models\InstitutionUser::where('email', 'admin@betplus.com.ng')->exists()) {
        \$cipher = \$app->make(\App\Domain\BackOffice\MfaSecretCipher::class);
        \$totp = \$app->make(\App\Domain\BackOffice\TotpService::class);
        \$secret = \$totp->generateSecret();
        \App\Models\InstitutionUser::create([
            'email' => 'admin@betplus.com.ng',
            'displayName' => 'System Administrator',
            'passwordHash' => password_hash('$ADMIN_PASS', PASSWORD_BCRYPT),
            'role' => 'system_admin',
            'status' => 'active',
            'mfaSecretEncrypted' => \$cipher->encrypt(\$secret),
        ]);
        echo \"\n>>> INITIAL ADMIN CREATED — CHANGE PASSWORD IMMEDIATELY <<<\n\";
        echo \"Email: admin@betplus.com.ng\n\";
        echo \"Password: $ADMIN_PASS\n\";
        echo \"TOTP Secret: \" . \$secret . \"\n\";
        echo \">>> ============================================== <<<\n\n\";
    } else {
        echo \"Admin user admin@betplus.com.ng is already configured.\n\";
    }
} catch (\Throwable \$e) {
    echo \"Notice: Admin seeding skipped: \" . \$e->getMessage() . \"\n\";
}
" || true

echo "Warming production caches..."
$PHP_BIN artisan config:cache || true
$PHP_BIN artisan route:cache || true
$PHP_BIN artisan view:cache || true

# 6. ATOMIC SWITCH: Point current to new release
ln -sfn "$CURRENT_RELEASE" "$REMOTE_BASE/current"
echo "Atomic switch: $REMOTE_BASE/current -> $CURRENT_RELEASE"

# Restart queue workers gracefully
$PHP_BIN artisan queue:restart || true

# Clean up old releases (keep latest 5)
cd "$REMOTE_BASE/releases"
ls -1t | tail -n +6 | xargs -r rm -rf

# ==========================================================
# 7. WEB SERVER INTEGRATION & SUBDOMAINS SETUP
# ==========================================================
echo "=========================================================="
echo "Configuring Web Server & Subdomains..."
echo "=========================================================="

MAIN_DOMAIN="betplus.com.ng"
if command -v uapi >/dev/null 2>&1; then
  DETECTED_MAIN=$(uapi DomainInfo list_domains 2>/dev/null | grep -E '^\s*main_domain:' | head -n 1 | awk '{print $2}' || true)
  if [ -n "$DETECTED_MAIN" ]; then
    MAIN_DOMAIN="$DETECTED_MAIN"
  fi
fi
echo "Target Main Domain: $MAIN_DOMAIN"

apply_origin_lockdown() {
  local target_file="$1"
  if [ -f "$REMOTE_BASE/shared/.env" ]; then
    local lockdown
    lockdown=$(grep -E '^\s*CLOUDFLARE_ORIGIN_LOCKDOWN=' "$REMOTE_BASE/shared/.env" | cut -d'=' -f2- | tr -d '"'\'' ' || true)
    if [ "$lockdown" = "true" ] || [ "$lockdown" = "1" ]; then
      echo "Injecting Cloudflare Origin Lockdown rules into $target_file..."
      cat << 'CF_LOCK_EOF' >> "$target_file"

# === Cloudflare Origin Lockdown (Blocks Direct Origin IP Access) ===
<IfModule mod_authz_core.c>
  <RequireAny>
    Require ip 127.0.0.1
    Require ip ::1
    Require ip 216.120.200.40
    # Cloudflare Edge IPv4
    Require ip 173.245.48.0/20
    Require ip 103.21.244.0/22
    Require ip 103.22.200.0/22
    Require ip 103.31.4.0/22
    Require ip 141.101.64.0/18
    Require ip 108.162.192.0/18
    Require ip 190.93.240.0/20
    Require ip 188.114.96.0/20
    Require ip 197.234.240.0/22
    Require ip 198.41.128.0/17
    Require ip 162.158.0.0/15
    Require ip 104.16.0.0/13
    Require ip 104.24.0.0/14
    Require ip 172.64.0.0/13
    Require ip 131.0.72.0/22
    # Cloudflare Edge IPv6
    Require ip 2400:cb00::/32
    Require ip 2606:4700::/32
    Require ip 2803:f800::/32
    Require ip 2405:b500::/32
    Require ip 2405:8100::/32
    Require ip 2a06:98c0::/29
    Require ip 2c0f:f248::/32
  </RequireAny>
</IfModule>
CF_LOCK_EOF
    fi
  fi
}

# --- A. FRONTEND: Publish to public_html ---
echo "Publishing Next.js static export to public_html..."
mkdir -p "/home/$USER/public_html"
chmod 755 "/home/$USER"
chmod 755 "/home/$USER/public_html"

if [ -d "$REMOTE_BASE/current/apps/web/out" ]; then
  # Sync static build assets into public_html without wiping subdomains or ssl directories,
  # excluding back-office (isolated to dedicated backoffice subdomain)
  rsync -av \
    --exclude 'api*' \
    --exclude 'ussd*' \
    --exclude 'backoffice*' \
    --exclude 'back-office*' \
    --exclude '.well-known*' \
    --exclude 'cgi-bin*' \
    "$REMOTE_BASE/current/apps/web/out/" "/home/$USER/public_html/"
  echo "Frontend files synchronized to public_html."
fi

# Ensure clean routing, HTTPS and back-office isolation in public_html
cat << 'HTACCESS_EOF' > "/home/$USER/public_html/.htaccess"
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /

  # Force HTTPS
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

  # Back office isolation — redirect any back-office requests to dedicated protected subdomain
  RewriteRule ^back-office/?(.*)$ https://backoffice.%{HTTP_HOST}/$1 [L,R=301]

  # Serve HTML file if exists (e.g. /games -> /games.html)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{DOCUMENT_ROOT}/$1.html -f
  RewriteRule ^(.*)$ $1.html [L]

  # SPA Fallback to index.html for dynamic player routes
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>
HTACCESS_EOF
apply_origin_lockdown "/home/$USER/public_html/.htaccess"
chmod 644 "/home/$USER/public_html/.htaccess"

# --- B. BACKEND: Create & Configure api.$MAIN_DOMAIN ---
echo "Configuring api.$MAIN_DOMAIN..."
if command -v uapi >/dev/null 2>&1; then
  if ! uapi DomainInfo list_domains 2>/dev/null | grep -q "api\.$MAIN_DOMAIN"; then
    echo "Adding subdomain api.$MAIN_DOMAIN via cPanel UAPI..."
    uapi SubDomain addsubdomain domain=api rootdomain="$MAIN_DOMAIN" dir="public_html/api" || true
  else
    echo "Subdomain api.$MAIN_DOMAIN is already registered in cPanel."
  fi
fi

# Link both potential document roots directly to Laravel public directory
# (covers both public_html/api and /home/$USER/api.$MAIN_DOMAIN)
rm -rf "/home/$USER/public_html/api"
ln -sfn "$REMOTE_BASE/current/apps/platform/public" "/home/$USER/public_html/api"

rm -rf "/home/$USER/api.$MAIN_DOMAIN"
ln -sfn "$REMOTE_BASE/current/apps/platform/public" "/home/$USER/api.$MAIN_DOMAIN"
apply_origin_lockdown "$REMOTE_BASE/current/apps/platform/public/.htaccess"
echo "Linked api.$MAIN_DOMAIN document roots -> $REMOTE_BASE/current/apps/platform/public"

# Explicitly assign PHP 8.4 via cPanel LangPHP module
if command -v uapi >/dev/null 2>&1; then
  echo "Assigning ea-php84 to api.$MAIN_DOMAIN, ussd.$MAIN_DOMAIN, backoffice.$MAIN_DOMAIN and $MAIN_DOMAIN..."
  uapi LangPHP php_set_vhost_versions vhost="api.$MAIN_DOMAIN" version="ea-php84" 2>/dev/null || true
  uapi LangPHP php_set_vhost_versions vhost="ussd.$MAIN_DOMAIN" version="ea-php84" 2>/dev/null || true
  uapi LangPHP php_set_vhost_versions vhost="backoffice.$MAIN_DOMAIN" version="ea-php84" 2>/dev/null || true
  uapi LangPHP php_set_vhost_versions vhost="$MAIN_DOMAIN" version="ea-php84" 2>/dev/null || true
fi

# --- C. USSD: Create & Configure ussd.$MAIN_DOMAIN ---
echo "Configuring ussd.$MAIN_DOMAIN..."
if command -v uapi >/dev/null 2>&1; then
  if ! uapi DomainInfo list_domains 2>/dev/null | grep -q "ussd\.$MAIN_DOMAIN"; then
    echo "Adding subdomain ussd.$MAIN_DOMAIN via cPanel UAPI..."
    uapi SubDomain addsubdomain domain=ussd rootdomain="$MAIN_DOMAIN" dir="public_html/ussd" || true
  else
    echo "Subdomain ussd.$MAIN_DOMAIN is already registered in cPanel."
  fi
fi

mkdir -p "$REMOTE_BASE/current/apps/ussd/public"
rm -rf "/home/$USER/public_html/ussd"
ln -sfn "$REMOTE_BASE/current/apps/ussd/public" "/home/$USER/public_html/ussd"
rm -rf "/home/$USER/ussd.$MAIN_DOMAIN"
ln -sfn "$REMOTE_BASE/current/apps/ussd/public" "/home/$USER/ussd.$MAIN_DOMAIN"
echo "Linked ussd.$MAIN_DOMAIN document roots -> $REMOTE_BASE/current/apps/ussd/public"

# --- D. BACKOFFICE: Create & Configure backoffice.$MAIN_DOMAIN ---
echo "Configuring backoffice.$MAIN_DOMAIN..."
if command -v uapi >/dev/null 2>&1; then
  if ! uapi DomainInfo list_domains 2>/dev/null | grep -q "backoffice\.$MAIN_DOMAIN"; then
    echo "Adding subdomain backoffice.$MAIN_DOMAIN via cPanel UAPI..."
    uapi SubDomain addsubdomain domain=backoffice rootdomain="$MAIN_DOMAIN" dir="backoffice.$MAIN_DOMAIN" || true
  else
    echo "Subdomain backoffice.$MAIN_DOMAIN is already registered in cPanel."
  fi
fi

BACKOFFICE_DOCROOT="/home/$USER/backoffice.$MAIN_DOMAIN"
mkdir -p "$BACKOFFICE_DOCROOT"
chmod 755 "$BACKOFFICE_DOCROOT"

if [ -d "$REMOTE_BASE/current/apps/web/out/back-office" ]; then
  # Option A: sync back-office static export into dedicated document root
  rsync -av \
    --delete \
    "$REMOTE_BASE/current/apps/web/out/back-office/" "$BACKOFFICE_DOCROOT/"

  # Ensure static Next.js assets (_next, assets) and favicon are accessible on backoffice subdomain
  if [ -d "$REMOTE_BASE/current/apps/web/out/_next" ]; then
    rm -rf "$BACKOFFICE_DOCROOT/_next"
    ln -sfn "$REMOTE_BASE/current/apps/web/out/_next" "$BACKOFFICE_DOCROOT/_next"
  fi
  if [ -d "$REMOTE_BASE/current/apps/web/out/assets" ]; then
    rm -rf "$BACKOFFICE_DOCROOT/assets"
    ln -sfn "$REMOTE_BASE/current/apps/web/out/assets" "$BACKOFFICE_DOCROOT/assets"
  fi
  if [ -f "$REMOTE_BASE/current/apps/web/out/favicon.ico" ]; then
    cp -f "$REMOTE_BASE/current/apps/web/out/favicon.ico" "$BACKOFFICE_DOCROOT/favicon.ico"
  fi
  echo "Backoffice files synchronized to $BACKOFFICE_DOCROOT."
fi

# Link public_html/backoffice in case cPanel virtual host references public_html path
rm -rf "/home/$USER/public_html/backoffice"
ln -sfn "$BACKOFFICE_DOCROOT" "/home/$USER/public_html/backoffice"

# Deploy hardened .htaccess for backoffice with clean root routing, security headers & optional IP allowlist
cat << 'BO_HTACCESS_EOF' > "$BACKOFFICE_DOCROOT/.htaccess"
# Betplus Back Office — Hardened Security & Clean Root Routing (Option A)
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /

  # 1. Force HTTPS
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

  # 2. Option A clean routing: normalize legacy /back-office/* URLs to clean root paths
  RewriteRule ^back-office/?$ / [L,R=301]
  RewriteRule ^back-office/(.*)$ /$1 [L,R=301]

  # 3. Serve directory index.html if exists (e.g. /overview -> /overview/index.html)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{DOCUMENT_ROOT}/$1/index.html -f
  RewriteRule ^(.*)/?$ $1/index.html [L]

  # 4. Serve HTML file if exists (e.g. /overview -> /overview.html)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteCond %{DOCUMENT_ROOT}/$1.html -f
  RewriteRule ^(.*)$ $1.html [L]

  # 5. SPA Fallback to root index.html (Operator Sign-In)
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.html [L]
</IfModule>

# Security Headers: anti-framing, strict MIME type sniffing protection, strict referrer
<IfModule mod_headers.c>
  Header always set X-Frame-Options "DENY"
  Header always set X-Content-Type-Options "nosniff"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
  Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>

# Block access to hidden, environment, or configuration files
<FilesMatch "^\.|\.(env|log|sqlite|json|md|sh)$">
  Require all denied
</FilesMatch>
BO_HTACCESS_EOF

# Optional IP allowlist injection if BACKOFFICE_ALLOWED_IPS is configured in shared/.env
if [ -f "$REMOTE_BASE/shared/.env" ]; then
  BO_IPS=$(grep -E '^\s*BACKOFFICE_ALLOWED_IPS=' "$REMOTE_BASE/shared/.env" | cut -d'=' -f2- | tr -d '"'\''')
  if [ -n "$BO_IPS" ]; then
    echo "Injecting IP allowlist into backoffice .htaccess: $BO_IPS"
    cat << ALLOW_EOF >> "$BACKOFFICE_DOCROOT/.htaccess"

# Strict IP Allowlist Protection (from shared/.env BACKOFFICE_ALLOWED_IPS)
<RequireAny>
$(for ip in $(echo "$BO_IPS" | tr ',' ' '); do echo "  Require ip $ip"; done)
</RequireAny>
ALLOW_EOF
  fi
fi
apply_origin_lockdown "$BACKOFFICE_DOCROOT/.htaccess"
chmod 644 "$BACKOFFICE_DOCROOT/.htaccess"
echo "Linked backoffice.$MAIN_DOMAIN document roots -> $BACKOFFICE_DOCROOT"


echo "=========================================================="
echo "cPanel Domains and Subdomains Summary:"
if command -v uapi >/dev/null 2>&1; then
  uapi DomainInfo list_domains 2>/dev/null | grep -E '^\s*(main_domain|sub_domains|documentroot):' || true
fi
echo "=========================================================="
if [ -f "$REMOTE_BASE/shared/storage/logs/laravel.log" ]; then
  echo "=== Recent errors from laravel.log ==="
  tail -n 30 "$REMOTE_BASE/shared/storage/logs/laravel.log" || true
fi
echo "Deployment and web server configuration completed successfully!"
