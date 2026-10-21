# syntax=docker/dockerfile:1
FROM php-project-php-base:8.5

WORKDIR /var/www

COPY --chown=www-data:www-data . .

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV NGINX_PORT=8004
EXPOSE 8004

CMD ["/usr/local/bin/entrypoint-http.sh"]
