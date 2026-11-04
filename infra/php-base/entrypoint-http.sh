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

# Long-running gateway routes (recommendation chat) get their own php-fpm pool
# so they cannot exhaust the [www] workers that serve /api/login and /api/refresh.
# Opt-in per service: set GATEWAY_FPM_PORT (e.g. 9001) in compose.
render_gateway_pool() {
  local port="${GATEWAY_FPM_PORT:-}"
  local max_children="${GATEWAY_FPM_MAX_CHILDREN:-40}"
  local routes="${GATEWAY_ROUTES:-^/api/recommendations}"

  mkdir -p /etc/nginx/gateway
  rm -f /etc/nginx/gateway/*.conf /usr/local/etc/php-fpm.d/zz-gateway.conf

  if [[ -z "$port" || "$port" == "9000" ]]; then
    return 0
  fi

  export GATEWAY_FPM_PORT="$port" GATEWAY_FPM_MAX_CHILDREN="$max_children"
  envsubst '${GATEWAY_FPM_PORT} ${GATEWAY_FPM_MAX_CHILDREN}' \
    < /usr/local/etc/php-fpm.d/zz-gateway.conf.template \
    > /usr/local/etc/php-fpm.d/zz-gateway.conf

  cat > /etc/nginx/gateway/gateway.conf <<EOF
# Rendered by entrypoint-http.sh — long-running gateway routes use the [gateway] pool.
# These are Laravel routes (no .php file on disk), so always front-controller.
location ~ ${routes} {
    fastcgi_pass 127.0.0.1:${port};
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME \$realpath_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT \$realpath_root;
    fastcgi_hide_header X-Powered-By;
}
EOF
}

run_http() {
  prepare_storage
  render_nginx
  render_gateway_pool

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
