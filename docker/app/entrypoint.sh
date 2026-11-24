#!/bin/sh
set -e

cd /app

if [ ! -f vendor/autoload.php ]; then
    echo "noongun: vendor/ is missing. Run ./setup on the host first." >&2
    exit 1
fi

if [ "${NOONGUN_ROLE:-}" = "app" ]; then
    if [ "${APP_ENV:-}" = "production" ]; then
        php artisan optimize
    fi
    if [ "${NOONGUN_AUTO_MIGRATE:-false}" = "true" ]; then
        php artisan migrate --force --no-interaction
    fi
fi

exec "$@"
