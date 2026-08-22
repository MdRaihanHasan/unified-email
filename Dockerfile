# FrankenPHP: Caddy + PHP in one process, serving /app/public directly.
# One image, four roles (web, queue worker, scheduler, IMAP IDLE daemon) — the
# compose file just varies the command.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        opcache \
        pcntl \
        sockets \
        redis

# Composer, from the official image rather than a curl|php bootstrap.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependencies first, so a source-only change does not reinstall the world.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --optimize-autoloader

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# IDLE daemons and queue workers hold connections open, so they must not be
# reaped by a healthcheck-driven restart. Only the web role gets one.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s \
    CMD curl -fsS http://localhost/up || exit 1

EXPOSE 80
