#!/bin/sh
# Runs on every start, so each step is safe to repeat.
set -eu

# This app shares a Postgres database with other apps and keeps its tables in its own schema.
if [ -z "${DB_URL:-}" ]; then
    echo "DB_URL is not set: add the database's Internal Database URL in the Render dashboard." >&2
    exit 1
fi
php artisan tinker --execute='DB::statement("CREATE SCHEMA IF NOT EXISTS ".config("database.connections.pgsql.search_path"));'

php artisan migrate --force

# The first admin comes from ADMIN_EMAIL and ADMIN_PASSWORD, set in the Render dashboard; once it exists this does nothing.
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    php artisan make:filament-user --name=Admin --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --no-interaction || true
fi

php artisan config:cache
php artisan view:cache

exec frankenphp php-server --listen=":${PORT:-10000}" --root=/app/public
