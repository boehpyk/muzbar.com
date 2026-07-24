# syntax=docker/dockerfile:1

# ---------- base: shared PHP 8.4-FPM + extensions ----------
FROM php:8.4-fpm AS base

# Robust extension installer (handles system deps cleanly).
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions pdo_pgsql intl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# ---------- development: Xdebug, permissive ini, live-mounted source ----------
FROM base AS development
RUN install-php-extensions xdebug
COPY docker/php/php.dev.ini /usr/local/etc/php/conf.d/zz-app-dev.ini
# Source is bind-mounted in docker-compose.dev.yml; nothing is COPYied here.

# ---------- production: baked source, no dev deps, tuned opcache ----------
FROM base AS production
ENV APP_ENV=prod
COPY src/ /var/www/html/
# Pre-own the shared-volume mount point so a fresh named volume is seeded www-data-writable
# (Docker copies this dir's ownership onto an empty volume on first mount); the entrypoint
# then publishes public/ into it on every start so nginx serves assets matching this image.
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && mkdir -p var && chown -R www-data:www-data var \
    && mkdir -p /mnt/public && chown www-data:www-data /mnt/public
COPY docker/php/docker-entrypoint.prod.sh /usr/local/bin/docker-entrypoint
RUN chmod +x /usr/local/bin/docker-entrypoint
USER www-data
ENTRYPOINT ["docker-entrypoint"]
CMD ["php-fpm"]
