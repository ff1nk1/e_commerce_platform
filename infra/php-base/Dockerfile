# syntax=docker/dockerfile:1
# Shared base for all Laravel microservices: php-fpm + nginx (+ CLI for workers).
# deb.debian.org часто таймаутится — зеркало Yandex.
FROM php:8.5-fpm-bookworm

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN set -eux; \
    if [ -f /etc/apt/sources.list.d/debian.sources ]; then \
      sed -i \
        -e 's|http://deb.debian.org/debian|http://mirror.yandex.ru/debian|g' \
        -e 's|https://deb.debian.org/debian|http://mirror.yandex.ru/debian|g' \
        -e 's|http://security.debian.org/debian-security|http://mirror.yandex.ru/debian-security|g' \
        -e 's|https://security.debian.org/debian-security|http://mirror.yandex.ru/debian-security|g' \
        /etc/apt/sources.list.d/debian.sources; \
    fi; \
    if [ -f /etc/apt/sources.list ]; then \
      sed -i \
        -e 's|deb.debian.org|mirror.yandex.ru|g' \
        -e 's|security.debian.org|mirror.yandex.ru|g' \
        /etc/apt/sources.list; \
    fi; \
    printf '%s\n' \
      'Acquire::Retries "5";' \
      'Acquire::http::Timeout "60";' \
      > /etc/apt/apt.conf.d/80-retries

# Не монтировать /tmp/pear — install-php-extensions делает rm /tmp/pear и падает с "Device or resource busy".
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    MAKEFLAGS="-j$(nproc)" install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        rdkafka \
        mongodb \
        pcntl \
        bcmath \
        zip \
        intl

RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        gettext-base \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default \
    && mkdir -p /etc/nginx/templates

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY zz-laravel-fpm.conf /usr/local/etc/php-fpm.d/zz-laravel.conf
COPY zz-laravel-php.ini /usr/local/etc/php/conf.d/zz-laravel.ini
COPY nginx-laravel.conf.template /etc/nginx/templates/laravel.conf.template
COPY entrypoint-http.sh /usr/local/bin/entrypoint-http.sh

RUN chmod +x /usr/local/bin/entrypoint-http.sh

WORKDIR /var/www
