#!/bin/sh
# Runs on every start, so each step is safe to repeat.
set -eu

# This app shares a Postgres database with other apps and keeps its tables in its own schema.
php -r '$u = parse_url(getenv("DB_URL")); $db = new PDO("pgsql:host={$u["host"]};port=".($u["port"] ?? 5432).";dbname=".ltrim($u["path"], "/"), urldecode($u["user"]), urldecode($u["pass"])); $db->exec("CREATE SCHEMA IF NOT EXISTS ".getenv("DB_SEARCH_PATH"));'

php artisan migrate --force

# The first admin comes from ADMIN_EMAIL and ADMIN_PASSWORD, set in the Render dashboard; once it exists this does nothing.
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    php artisan make:filament-user --name=Admin --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --no-interaction || true
fi

php artisan config:cache
php artisan view:cache

exec frankenphp php-server --listen=":${PORT:-10000}" --root=/app/public
