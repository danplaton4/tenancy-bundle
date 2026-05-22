# syntax=docker/dockerfile:1.7
FROM dunglas/frankenphp:1-php8.2-bookworm AS base

# System deps: pdo_mysql needs nothing extra; git for path-repo resolution; unzip/curl for composer
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        curl \
        && rm -rf /var/lib/apt/lists/*

# PHP extensions: pdo_mysql (MariaDB driver), intl (Symfony recommended), opcache, zip
RUN install-php-extensions \
        pdo_mysql \
        intl \
        opcache \
        zip

# Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Custom OPcache dev config
COPY examples/saas/docker/php.ini /usr/local/etc/php/conf.d/zz-demo.ini

# Layout inside the image MIRRORS the host repo structure so the demo's
# composer.json `url:"../../"` resolves consistently in both contexts:
#   host:      examples/saas/  -> ../../    -> repo root
#   container: /srv/bundle/examples/saas/  -> ../../  -> /srv/bundle/
# Putting the bundle anywhere else (e.g. /srv/) silently breaks the path repo
# and Composer falls through to Packagist — see VERIFICATION 21 §"DOCKER-01"
# for the live-stack incident.
#
# Build context MUST be the repo root (set in compose.yaml build.context: ../../)
# so paths below are relative to the repo root.

# 1) Copy bundle source to /srv/bundle/ (only the dirs composer needs)
COPY src/ /srv/bundle/src/
COPY config/ /srv/bundle/config/
COPY composer.json /srv/bundle/composer.json

# composer.lock is optional (bundle may not commit it); the trailing * makes it optional with BuildKit
COPY composer.lock* /srv/bundle/

# 2) Copy the demo source to /srv/bundle/examples/saas/
WORKDIR /srv/bundle/examples/saas

# Copy composer manifest first (layer-cache friendly)
COPY examples/saas/composer.json /srv/bundle/examples/saas/composer.json
# composer.lock is optional for the demo too
COPY examples/saas/composer.lock* /srv/bundle/examples/saas/

# 3) Install demo composer deps (resolves path-repo at ../../ which is /srv/ from /srv/bundle/examples/saas/)
RUN composer install --no-interaction --no-progress --prefer-dist --no-scripts

# 4) Copy the rest of the demo source (after composer install for better layer caching)
COPY examples/saas/ /srv/bundle/examples/saas/

# 5) Ensure console + entrypoint are executable
RUN chmod +x /srv/bundle/examples/saas/bin/console /srv/bundle/examples/saas/docker/entrypoint.sh

# Environment
ENV SERVER_NAME=":80,:443"
ENV APP_ENV=dev
ENV APP_DEBUG=1

# Expose ports
EXPOSE 80 443

# Healthcheck — calls /health (Plan 02 HealthController).
# start-period=120s gives the entrypoint seed step time to complete before failures count.
HEALTHCHECK --interval=5s --timeout=3s --start-period=120s --retries=5 \
    CMD curl -fsS http://localhost/health || exit 1

ENTRYPOINT ["/srv/bundle/examples/saas/docker/entrypoint.sh"]
