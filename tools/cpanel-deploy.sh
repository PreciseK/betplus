#!/usr/bin/env bash
set -e

REMOTE_BASE="/home/$USER/betplus"
RELEASE_NAME=$(ls -1 "$REMOTE_BASE/releases" | tail -n 1)
CURRENT_RELEASE="$REMOTE_BASE/releases/$RELEASE_NAME"

echo "Configuring release at $CURRENT_RELEASE"

# Locate PHP 8.4 / 8.3 / 8.2 or cPanel ea-php binary
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

# Symlink shared .env into platform
if [ -f "$REMOTE_BASE/shared/.env" ]; then
  ln -sfn "$REMOTE_BASE/shared/.env" "$CURRENT_RELEASE/apps/platform/.env"
else
  echo "WARNING: $REMOTE_BASE/shared/.env does not exist yet. Please create it on the server."
fi

# Symlink shared storage
ln -sfn "$REMOTE_BASE/shared/storage" "$CURRENT_RELEASE/apps/platform/storage"

# Ensure vault database exists and persists across releases
mkdir -p "$REMOTE_BASE/shared/database"
touch "$REMOTE_BASE/shared/database/vault.sqlite"
mkdir -p "$CURRENT_RELEASE/apps/platform/database"
ln -sfn "$REMOTE_BASE/shared/database/vault.sqlite" "$CURRENT_RELEASE/apps/platform/database/vault.sqlite"

cd "$CURRENT_RELEASE/apps/platform"

# Run database migrations
$PHP_BIN artisan migrate --force || echo "Migrations skipped or failed, check DB connection in shared/.env"

# Warm caches
$PHP_BIN artisan config:cache || true
$PHP_BIN artisan route:cache || true
$PHP_BIN artisan view:cache || true

# ATOMIC SWITCH: Point current to new release
ln -sfn "$CURRENT_RELEASE" "$REMOTE_BASE/current"

# Restart queue workers gracefully
$PHP_BIN artisan queue:restart || true

# Clean up old releases (keep latest 5)
cd "$REMOTE_BASE/releases"
ls -1t | tail -n +6 | xargs -r rm -rf

echo "Deployment completed successfully!"
