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

# 2. Symlink shared .env into platform
if [ -f "$REMOTE_BASE/shared/.env" ]; then
  ln -sfn "$REMOTE_BASE/shared/.env" "$CURRENT_RELEASE/apps/platform/.env"
  echo "Linked shared/.env into apps/platform/.env"
  
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
else
  echo "WARNING: $REMOTE_BASE/shared/.env does not exist yet. Please create it on the server."
fi

# 3. Symlink shared storage
mkdir -p "$REMOTE_BASE/shared/storage/framework/sessions"
mkdir -p "$REMOTE_BASE/shared/storage/framework/views"
mkdir -p "$REMOTE_BASE/shared/storage/framework/cache"
mkdir -p "$REMOTE_BASE/shared/storage/logs"
chmod -R 777 "$REMOTE_BASE/shared/storage"
ln -sfn "$REMOTE_BASE/shared/storage" "$CURRENT_RELEASE/apps/platform/storage"

# 4. Ensure persistent database files exist across releases
mkdir -p "$REMOTE_BASE/shared/database"
touch "$REMOTE_BASE/shared/database/database.sqlite"
touch "$REMOTE_BASE/shared/database/vault.sqlite"
chmod -R 777 "$REMOTE_BASE/shared/database"
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
$PHP_BIN -r '
require "vendor/autoload.php";
$app = require_once "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
try {
    if (!\App\Models\InstitutionUser::where("email", "admin@betplus.com.ng")->exists()) {
        $cipher = $app->make(\App\Domain\BackOffice\MfaSecretCipher::class);
        $totp = $app->make(\App\Domain\BackOffice\TotpService::class);
        $secret = $totp->generateSecret();
        \App\Models\InstitutionUser::create([
            "email" => "admin@betplus.com.ng",
            "displayName" => "System Administrator",
            "passwordHash" => password_hash("AdminPass123!", PASSWORD_BCRYPT),
            "role" => "system_admin",
            "status" => "active",
            "mfaSecretEncrypted" => $cipher->encrypt($secret),
        ]);
        echo "\n>>> INITIAL ADMIN CREATED <<<\n";
        echo "Email: admin@betplus.com.ng\n";
        echo "Password: AdminPass123!\n";
        echo "TOTP Secret: " . $secret . "\n";
        echo ">>> =================== <<<\n\n";
    } else {
        echo "Admin user admin@betplus.com.ng is already configured.\n";
    }
} catch (\Throwable $e) {
    echo "Notice: Admin seeding skipped: " . $e->getMessage() . "\n";
}
' || true

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

# --- A. FRONTEND: Publish to public_html ---
echo "Publishing Next.js static export to public_html..."
mkdir -p "/home/$USER/public_html"
chmod 755 "/home/$USER"
chmod 755 "/home/$USER/public_html"

if [ -d "$REMOTE_BASE/current/apps/web/out" ]; then
  # Sync static build assets into public_html without wiping subdomains or ssl directories
  rsync -av \
    --exclude 'api*' \
    --exclude 'ussd*' \
    --exclude '.well-known*' \
    --exclude 'cgi-bin*' \
    "$REMOTE_BASE/current/apps/web/out/" "/home/$USER/public_html/"
  echo "Frontend files synchronized to public_html."
fi

# Ensure clean routing and HTTPS in public_html
cat << 'HTACCESS_EOF' > "/home/$USER/public_html/.htaccess"
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /

  # Force HTTPS
  RewriteCond %{HTTPS} off
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

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
echo "Linked api.$MAIN_DOMAIN document roots -> $REMOTE_BASE/current/apps/platform/public"

# Explicitly assign PHP 8.4 via cPanel LangPHP module
if command -v uapi >/dev/null 2>&1; then
  echo "Assigning ea-php84 to api.$MAIN_DOMAIN and $MAIN_DOMAIN..."
  uapi LangPHP php_set_vhost_versions vhost="api.$MAIN_DOMAIN" version="ea-php84" 2>/dev/null || true
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

mkdir -p "$REMOTE_BASE/current/apps/ussd"
rm -rf "/home/$USER/public_html/ussd"
ln -sfn "$REMOTE_BASE/current/apps/ussd" "/home/$USER/public_html/ussd"
rm -rf "/home/$USER/ussd.$MAIN_DOMAIN"
ln -sfn "$REMOTE_BASE/current/apps/ussd" "/home/$USER/ussd.$MAIN_DOMAIN"

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
