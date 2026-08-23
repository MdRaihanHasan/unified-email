# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Assets
# ---------------------------------------------------------------------------
# The compiled frontend is gitignored, so it has to be built here. Without this
# stage the container serves the app with no CSS and no JavaScript at all.
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources

# laravel-vite-plugin fetches the Instrument Sans files during the build, so this
# stage needs network access. The result is self-contained afterwards.
RUN npm run build

# ---------------------------------------------------------------------------
# Runtime
# ---------------------------------------------------------------------------
# FrankenPHP is Caddy and PHP in one process, serving /app/public. Classic mode,
# not Octane — Octane is not installed, and a mail client's request pattern gets
# little from a resident worker.
#
# One image, three roles (web, queue worker, scheduler); the compose file only
# varies the command.
FROM dunglas/frankenphp:1-php8.4 AS runtime

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        opcache \
        pcntl \
        sockets \
        redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so editing a controller does not reinstall the world.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY . .
COPY --from=assets /app/public/build ./public/build
COPY docker/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/99-unified-mail.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && composer dump-autoload --optimize --no-dev \
    && mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/app/attachments \
        storage/app/purifier \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# No curl dependency: PHP is already here and cannot be missing.
# Only the web role gets a check — a queue worker holds no HTTP port, and a
# restart triggered by a failing probe would interrupt a running backfill.
HEALTHCHECK --interval=30s --timeout=5s --start-period=25s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1/up') === false ? 1 : 0);"

EXPOSE 80

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
