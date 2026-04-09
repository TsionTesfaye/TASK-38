#!/bin/sh
set -e

echo "=== RentOps Backend Startup ==="

# Always ensure vendor directory is fully populated.
# The named volume may be empty on first run, or stale after composer.json changes.
echo "Installing/updating PHP dependencies..."
composer install --no-interaction --optimize-autoloader 2>&1 | tail -5

# Parse DATABASE_URL into PDO DSN components.
# Format: mysql://user:pass@host:port/dbname?...
echo "Waiting for MySQL..."
MAX_RETRIES=30
RETRY=0
until php -r "
  \$url = getenv('DATABASE_URL');
  if (!preg_match('#mysql://([^:]+):([^@]+)@([^:]+):(\d+)/([^?]+)#', \$url, \$m)) {
    fwrite(STDERR, \"Invalid DATABASE_URL format\\n\");
    exit(1);
  }
  try {
    new PDO('mysql:host='.\$m[3].';port='.\$m[4].';dbname='.\$m[5], \$m[1], \$m[2]);
  } catch (Exception \$e) {
    exit(1);
  }
" 2>/dev/null; do
    RETRY=$((RETRY + 1))
    if [ "$RETRY" -ge "$MAX_RETRIES" ]; then
        echo "ERROR: MySQL not reachable after ${MAX_RETRIES} attempts. Aborting."
        exit 1
    fi
    echo "  Attempt $RETRY/$MAX_RETRIES — retrying in 2s..."
    sleep 2
done
echo "MySQL is ready."

# Ensure storage directories exist
mkdir -p /var/www/storage/pdfs /var/www/storage/exports /var/www/storage/backups \
    /var/www/storage/logs /var/www/storage/terminal_assets /var/www/storage/terminal_chunks

echo "Running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || {
    echo "WARNING: Migrations failed or skipped."
}

echo "Running startup reconciliation..."
php bin/console app:startup-reconciliation 2>/dev/null || echo "Startup reconciliation skipped."

echo "Starting PHP server on 0.0.0.0:8080..."
exec "$@"
