# syntax=docker/dockerfile:1

# Elifba's one production image (docs/architecture.md §10): PHP 8.4 FPM and
# nginx from serversideup/php, the app without dev packages and its built
# assets. docker-compose.yml runs it as `app` (nginx + PHP-FPM, the Laravel
# automations), `reverb` and `scheduler`.
#
# The browser's Reverb settings are built into the assets (VITE_REVERB_*,
# resources/js/game/socket.ts), so docker-compose.yml passes them as build
# arguments from .env; changing the domain, scheme, port or REVERB_APP_KEY
# needs `docker compose up -d --build`.

FROM node:22.23.4-bookworm-slim AS node

# PHP 8.4 with intl (composer.json needs ext-intl; pdo_pgsql and pcntl are
# already in the image).
FROM serversideup/php:8.4-fpm-nginx-bookworm-v5.1.1 AS base

USER root
RUN install-php-extensions intl
USER www-data

# nginx keeps REMOTE_ADDR as the peer (Caddy). The image's default,
# `cloudflare`, would take it from a CF-Connecting-IP header any browser can
# send through Caddy; Laravel reads X-Forwarded-* itself (TRUSTED_PROXIES).
# Laravel's /up is the web container's health check.
ENV TRUSTED_PROXY=off \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1

# Composer packages without dev dependencies, then the source (.dockerignore
# keeps out .env, node_modules, vendor, built and generated files, symlinks
# into the host, storage and bootstrap/cache).
FROM base AS source

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist \
        --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN mkdir -p bootstrap/cache storage/app/private storage/app/public \
        storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --no-interaction

# Built assets. `vp build` runs the Wayfinder plugin, which calls
# `php artisan wayfinder:generate`, so this stage has PHP with intl, the
# vendor tree and Node 22. Only public/build leaves it.
FROM base AS assets

USER root
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

COPY package.json package-lock.json .npmrc ./
RUN npm ci --no-audit --no-fund

COPY --from=source /var/www/html /var/www/html

ARG VITE_APP_NAME=Elifba
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

RUN if [ -z "$VITE_REVERB_APP_KEY" ] || [ -z "$VITE_REVERB_HOST" ]; then \
        echo "VITE_REVERB_APP_KEY and VITE_REVERB_HOST must be set: fill REVERB_APP_KEY and ELIFBA_DOMAIN in .env (scripts/docker-env.sh)." >&2; \
        exit 1; \
    fi \
    && npm run build

FROM base AS app

COPY --from=source --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /var/www/html/public/build /var/www/html/public/build
