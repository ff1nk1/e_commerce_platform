#!/usr/bin/env sh
# Optional Packagist mirror. Set COMPOSER_MIRROR to a composer repository URL.
# Example: COMPOSER_MIRROR=https://mirrors.aliyun.com/composer/ ../infra/composer-mirror.sh
# Run from a service root.
set -e
if [ -n "${COMPOSER_MIRROR:-}" ]; then
  composer config -g repos.packagist composer "$COMPOSER_MIRROR"
  composer config -g github-protocols https
fi
export COMPOSER_PROCESS_TIMEOUT=600
composer install --no-interaction --prefer-dist "$@"
echo "composer install done"
