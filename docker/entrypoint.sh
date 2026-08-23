#!/bin/sh
set -e

# key:generate has to run before a key exists, so it skips everything below —
# otherwise the guard makes generating the first key impossible.
for arg in "$@"; do
    case "$arg" in
        key:generate) exec "$@" ;;
    esac
done

# APP_KEY is deliberately never generated here.
#
# Every connected mailbox's refresh token is encrypted with it. Generating a fresh
# key on boot would leave every stored credential unreadable — the accounts would
# look connected and fail on the first sync, with nothing in the logs to explain it.
# Failing loudly on a missing key is the only safe behaviour.
if [ -z "${APP_KEY}" ]; then
    echo "" >&2
    echo "APP_KEY is not set." >&2
    echo "" >&2
    echo "  Generate one once, on the host, and keep it in .env:" >&2
    echo "      docker compose run --rm --no-deps app php artisan key:generate --show" >&2
    echo "" >&2
    echo "  Back it up somewhere other than this machine: losing it means" >&2
    echo "  reconnecting every mailbox from scratch." >&2
    echo "" >&2
    exit 1
fi

# A volume mounted at storage/app hides whatever the image created underneath it,
# so the directories the app expects are made here, after the mount.
#
#   private/   staged attachment uploads — written by the web container when a
#              draft is composed, read by the worker when it sends. Both mount the
#              same volume for exactly this reason.
#   purifier/  HTMLPurifier's serializer cache, or every email body fails to render.
mkdir -p \
    storage/app/private \
    storage/app/purifier \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Only the web container migrates. Letting the worker and the scheduler race on
# the same migration is how you get a half-applied schema.
if [ "${RUN_MIGRATIONS}" = "true" ]; then
    echo "==> Running migrations"
    php artisan migrate --force --no-interaction
fi

# Cached at boot rather than at build time, because the config depends on runtime
# environment the image cannot know.
echo "==> Caching configuration"
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction

exec "$@"
