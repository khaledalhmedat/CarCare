#!/bin/sh

echo "Setting up Laravel environment..."

mkdir -p bootstrap/cache storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/public

chmod -R 775 bootstrap/cache storage

# Shared storage symlink - REQUIRED on EVERY container (app-1, app-2, ...).
# The load balancer serves /storage files from any instance, so every
# instance must have public/storage -> storage/app/public (shared volume).
# Runs before the role check so all roles create it. --force is idempotent.
php artisan storage:link --force || true

if [ "$CONTAINER_ROLE" = "app" ] || [ -z "$CONTAINER_ROLE" ]; then
    if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "SomeRandomString" ]; then
        echo "Generating application key..."
        php artisan key:generate --force || true
    fi
    php artisan optimize:clear || true

    echo "Checking database connection..."
    MAX_RETRIES=5
    RETRY_COUNT=0

    while [ $RETRY_COUNT -lt $MAX_RETRIES ]; do
        if php artisan db:show >/dev/null 2>&1; then
            echo "Database is ready!"
            break
        fi
        echo "Database is unavailable - retrying ($((RETRY_COUNT + 1))/$MAX_RETRIES)"
        sleep 2
        RETRY_COUNT=$((RETRY_COUNT + 1))
    done

    if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
        echo "WARNING: DB not available, continuing anyway..."
    fi

    echo "Running migrations..."
    php artisan migrate --force || echo "Migrations failed - continuing"

    echo "Optimizing application..."
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan view:cache || echo "View cache failed - skipping"
else
    echo "Skipping migrations and caching for role: $CONTAINER_ROLE"
fi

echo "Starting application..."

exec "$@" || true
