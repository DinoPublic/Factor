#!/bin/sh
set -e

# Con ./app montado como volumen puede no haber vendor/ en el host.
[ -f vendor/autoload.php ] || composer install --no-interaction --prefer-dist

[ -f .env ] || cp .env.example .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

php artisan migrate --force

exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
