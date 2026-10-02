#!/usr/bin/env bash
# ==============================================================================
# Betplus — Cloudflare R2 Database Backup Runner
#
# Runs the backup:database-r2 Artisan command to generate an encrypted snapshot
# of the database and stream it to Cloudflare R2.
# Can be executed manually or scheduled via cPanel Cron:
# 0 2 * * * /home/USERNAME/betplus/current/tools/r2-backup.sh >> /home/USERNAME/betplus/shared/storage/logs/r2-backup.log 2>&1
# ==============================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PLATFORM_DIR="$PROJECT_ROOT/apps/platform"

PHP_BIN=""
for candidate in \
  /usr/local/bin/ea-php84 \
  /opt/cpanel/ea-php84/root/usr/bin/php \
  /usr/local/bin/ea-php83 \
  /opt/cpanel/ea-php83/root/usr/bin/php \
  /usr/local/bin/php \
  /usr/bin/php \
  php; do
  if command -v "$candidate" >/dev/null 2>&1; then
    PHP_BIN="$candidate"
    break
  fi
done

if [ -z "$PHP_BIN" ]; then
  echo "[$(date -u)] ERROR: PHP binary not found." >&2
  exit 1
fi

echo "[$(date -u)] Initiating Betplus database backup to Cloudflare R2..."
"$PHP_BIN" "$PLATFORM_DIR/artisan" backup:database-r2 "$@"
echo "[$(date -u)] Backup process finished successfully."
