#!/bin/sh
# One image, several roles:
#   (default)          -> web server (FrankenPHP on $PORT)
#   horizon            -> queue worker supervisor
#   artisan <args...>  -> run any artisan command (migrate, tinker, etc.)
set -e

case "$1" in
  horizon)
    exec php artisan horizon
    ;;
  artisan)
    shift
    exec php artisan "$@"
    ;;
esac

# Opt-in migrate on boot (handy for local/compose; on Render use preDeployCommand).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force || true
fi

# Render (and most PaaS) inject $PORT; FrankenPHP binds it via $SERVER_NAME.
export SERVER_NAME=":${PORT:-8080}"
exec frankenphp run --config /etc/caddy/Caddyfile
