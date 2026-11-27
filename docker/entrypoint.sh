#!/bin/sh
# Container start-up: run as PUID/PGID, prepare /config, migrate the
# database, start the background task worker, then hand over to Apache.
set -eu

PUID="${PUID:-1000}"
PGID="${PGID:-1000}"
if [ "$(id -g www-data)" != "$PGID" ]; then groupmod -o -g "$PGID" www-data; fi
if [ "$(id -u www-data)" != "$PUID" ]; then usermod -o -u "$PUID" www-data; fi

export WANKARR_PORT="${WANKARR_PORT:-8484}"
case "$WANKARR_PORT" in
  ''|*[!0-9]*) echo "wankarr: WANKARR_PORT must be a port number, got '$WANKARR_PORT'" >&2; exit 1 ;;
esac
if [ "$WANKARR_PORT" -lt 1 ] || [ "$WANKARR_PORT" -gt 65535 ]; then
  echo "wankarr: WANKARR_PORT out of range: $WANKARR_PORT" >&2; exit 1
fi

export WANKARR_DATA="${WANKARR_DATA:-/config}"
export WANKARR_DB_DSN="${WANKARR_DB_DSN:-sqlite:$WANKARR_DATA/wankarr.db}"
export WANKARR_CONFIG="${WANKARR_CONFIG:-$WANKARR_DATA/config.php}"

mkdir -p "$WANKARR_DATA"
if [ "$(stat -c %u "$WANKARR_DATA")" != "$PUID" ]; then
  chown -R www-data:www-data "$WANKARR_DATA"
fi

if [ -n "${TZ:-}" ]; then
  echo "date.timezone = $TZ" > /usr/local/etc/php/conf.d/zzz-timezone.ini
fi

as_app() { runuser -u www-data -- "$@"; }

# The database may still be starting (e.g. a MariaDB container); keep trying.
tries=0
until as_app php /app/bin/wankarr migrate; do
  tries=$((tries + 1))
  if [ "$tries" -ge 30 ]; then
    echo "wankarr: database unavailable (WANKARR_DB_DSN=$WANKARR_DB_DSN), giving up" >&2
    exit 1
  fi
  echo "wankarr: waiting for the database…" >&2
  sleep 2
done

# Scheduled scans and metadata refreshes. Restarted if it ever exits.
if [ "${WANKARR_WORKER:-1}" = "1" ]; then
  ( while :; do as_app php /app/bin/wankarr worker || true; sleep 5; done ) &
fi

exec "$@"
