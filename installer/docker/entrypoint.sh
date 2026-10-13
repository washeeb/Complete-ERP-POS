#!/bin/sh
# First start and every start: keys, database structure, cached settings. Safe to run again (migrations and seeders
# only add what is missing).
set -eu
cd /var/www/server

# Laravel reads settings from the environment (env_file); an empty .env keeps artisan quiet.
[ -f .env ] || touch .env && chown www-data:www-data .env

if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="$(php artisan key:generate --show --no-interaction)"
    export APP_KEY
    echo "=================================================================="
    echo "No APP_KEY was set. Generated one — copy it into installer/docker/.env NOW"
    echo "and keep it (it encrypts stored secrets; it must never change):"
    echo "  APP_KEY=${APP_KEY}"
    echo "=================================================================="
fi
if [ -z "${ERP_AUDIT_KEY:-}" ]; then
    ERP_AUDIT_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    export ERP_AUDIT_KEY
    echo "No ERP_AUDIT_KEY was set. Copy it into installer/docker/.env NOW:  ERP_AUDIT_KEY=${ERP_AUDIT_KEY}"
fi
if [ -z "${BACKUP_PASSPHRASE:-}" ]; then
    echo "WARNING: BACKUP_PASSPHRASE is empty — backups cannot run until you set one in installer/docker/.env (12+ characters, written down)."
fi

until php artisan db:show --no-interaction >/dev/null 2>&1; do echo "Waiting for the database…"; sleep 2; done
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
php artisan storage:link --no-interaction >/dev/null 2>&1 || true
php artisan optimize --no-interaction
php artisan filament:optimize --no-interaction
chown -R www-data:www-data storage bootstrap/cache

# supervisord and its programs (php-fpm keeps the environment: clear_env = no) inherit everything exported here.
exec "$@"
