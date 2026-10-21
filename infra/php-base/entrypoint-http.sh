#!/bin/sh
set -eu

NGINX_PORT="${NGINX_PORT:?NGINX_PORT must be set (e.g. 8001)}"

TEMPLATE="/etc/nginx/templates/laravel.conf.template"
TARGET="/etc/nginx/conf.d/laravel.conf"

# Drop Debian default site so only our Laravel vhost listens.
rm -f /etc/nginx/sites-enabled/default

# Bind mounts often keep host/root ownership from old `artisan serve`.
# php-fpm runs as www-data and must write logs / framework cache.
mkdir -p \
    /var/www/storage/logs \
    /var/www/storage/framework/cache \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/bootstrap/cache
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R ug+rwx /var/www/storage /var/www/bootstrap/cache

export NGINX_PORT
envsubst '${NGINX_PORT}' < "$TEMPLATE" > "$TARGET"

php-fpm -D

exec nginx -g 'daemon off;'
