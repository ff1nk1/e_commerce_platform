# syntax=docker/dockerfile:1
FROM php-project-php-base:8.5

WORKDIR /var/www

COPY packages/messaging /var/packages/messaging
COPY notification_service/composer.json notification_service/composer.lock /var/www/

RUN --mount=type=cache,target=/root/.composer/cache \
    COMPOSER_ALLOW_SUPERUSER=1 \
    composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-autoloader

COPY --chown=www-data:www-data notification_service/ /var/www/

RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
        database \
    && touch database/database.sqlite \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload --optimize --no-dev --no-interaction \
    && APP_ENV=local \
       APP_KEY=base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE= \
       DB_CONNECTION=sqlite \
       DB_DATABASE=/var/www/database/database.sqlite \
       php artisan package:discover --ansi --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache vendor database

ENV NGINX_PORT=8003
EXPOSE 8003

CMD ["http"]
