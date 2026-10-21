# syntax=docker/dockerfile:1
# vendor не ставим при билде: сеть к GitHub часто таймаутится,
# а compose монтирует .:/var/www (используй локальный vendor).
FROM php-project-php-base:8.5

WORKDIR /var/www

COPY --chown=www-data:www-data . .

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV NGINX_PORT=8001
EXPOSE 8001

CMD ["/usr/local/bin/entrypoint-http.sh"]
