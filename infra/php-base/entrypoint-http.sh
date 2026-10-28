#!/bin/bash
# HTTP: nginx is PID-adjacent with php-fpm. If either exits, the container exits.
# `php` (artisan workers) runs as www-data. Other commands (composer, shell) stay root
# so bind-mounted vendor can be installed.
set -euo pipefail

prepare_storage() {
  mkdir -p \
    /var/www/storage/logs \
    /var/www/storage/framework/cache \
    /var/www/storage/framework/sessions \
    /var/www/storage/framework/views \
    /var/www/bootstrap/cache \
    /var/www/database
  if [[ ! -f /var/www/database/database.sqlite ]]; then
    touch /var/www/database/database.sqlite
  fi
  chown -R www-data:www-data \
    /var/www/storage \
    /var/www/bootstrap/cache \
    /var/www/database/database.sqlite
  chmod -R ug+rwx /var/www/storage /var/www/bootstrap/cache
}

render_nginx() {
  local port="${NGINX_PORT:?NGINX_PORT must be set (e.g. 8001)}"
  rm -f /etc/nginx/sites-enabled/default
  export NGINX_PORT="$port"
  envsubst '${NGINX_PORT}' < /etc/nginx/templates/laravel.conf.template > /etc/nginx/conf.d/laravel.conf
}

run_http() {
  prepare_storage
  render_nginx

  php-fpm --nodaemonize &
  local fpm_pid=$!
  nginx -g 'daemon off;' &
  local nginx_pid=$!

  trap 'kill "$fpm_pid" "$nginx_pid" 2>/dev/null || true; wait "$fpm_pid" 2>/dev/null || true; wait "$nginx_pid" 2>/dev/null || true' EXIT

  local status=0
  if wait -n "$fpm_pid" "$nginx_pid"; then
    status=0
  else
    status=$?
  fi
  exit "$status"
}

if [[ "${1:-}" == "http" ]]; then
  run_http
fi

prepare_storage

if [[ "${1:-}" == "php" ]]; then
  exec setpriv --reuid=www-data --regid=www-data --init-groups -- "$@"
fi

exec "$@"
