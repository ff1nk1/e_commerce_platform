#!/usr/bin/env sh
set -e
cd "$(dirname "$0")/.." || exit

DOCKER_BUILDKIT=1 docker build \
  --no-cache \
  -t php-project-php-base:8.5 \
  -f infra/php-base/Dockerfile \
  infra/php-base

echo "Built php-project-php-base:8.5 (php-fpm + nginx, bookworm)"
