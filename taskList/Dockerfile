# syntax=docker/dockerfile:1
#
# Node PSP production image. One image, two roles (web + worker) chosen by the
# entrypoint. Built on FrankenPHP (Caddy + PHP 8.4) so the web role is a real
# app server, not `artisan serve`. Same image runs locally (docker compose) and
# on Render.

# ---- Composer dependencies (no dev, optimized autoloader) --------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
COPY . .
# Runtime image has the ext-* that Horizon/PG/Redis need; skip the check here.
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    --no-interaction --prefer-dist --ignore-platform-reqs

# ---- Runtime -----------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.4 AS app

# Postgres, Redis, bcmath (money math), pcntl/posix (Horizon signals), opcache.
RUN install-php-extensions pdo_pgsql redis bcmath pcntl posix opcache

WORKDIR /app
COPY --from=vendor /app /app

# Laravel needs these writable; the image may run as root, keep it group-writable.
RUN chmod -R ug+w storage bootstrap/cache

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    OCTANE_SERVER=frankenphp

EXPOSE 8080
ENTRYPOINT ["entrypoint"]
