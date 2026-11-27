#!/bin/sh
# Container start-up (runs under tini): map PUID/PGID, prepare /config and
# nginx, migrate the database, then supervise php-fpm, nginx and the task
# worker. If php-fpm or nginx stops, the container exits so the restart
# policy can bring it back.
#
# Arguments, if given, run as the app user instead, e.g.
#   docker run --rm -v …:/config IMAGE php /app/bin/wankarr status
set -eu

die() { echo "wankarr: $*" >&2; exit 1; }
is_num() { case "$1" in ''|*[!0-9]*) return 1 ;; esac; }

PUID="${PUID:-1000}"
PGID="${PGID:-1000}"
is_num "$PUID" && is_num "$PGID" || die "PUID and PGID must be numbers"
if [ "$(id -u wankarr)" != "$PUID" ] || [ "$(id -g wankarr)" != "$PGID" ]; then
  sed -i "s/^wankarr:x:[0-9]*:[0-9]*:/wankarr:x:$PUID:$PGID:/" /etc/passwd
  sed -i "s/^wankarr:x:[0-9]*:/wankarr:x:$PGID:/" /etc/group
fi

export WANKARR_PORT="${WANKARR_PORT:-8484}"
is_num "$WANKARR_PORT" || die "WANKARR_PORT must be a port number, got '$WANKARR_PORT'"
{ [ "$WANKARR_PORT" -ge 1 ] && [ "$WANKARR_PORT" -le 65535 ]; } || die "WANKARR_PORT out of range: $WANKARR_PORT"

export WANKARR_DATA="${WANKARR_DATA:-/config}"
export WANKARR_DB_DSN="${WANKARR_DB_DSN:-sqlite:$WANKARR_DATA/wankarr.db}"
export WANKARR_CONFIG="${WANKARR_CONFIG:-$WANKARR_DATA/config.php}"

mkdir -p "$WANKARR_DATA" /tmp/nginx /run/nginx
[ "$(stat -c %u "$WANKARR_DATA")" = "$PUID" ] || chown -R wankarr:wankarr "$WANKARR_DATA"
chown wankarr:wankarr /tmp/nginx

if [ -n "${TZ:-}" ]; then
  echo "date.timezone = $TZ" > /etc/php84/conf.d/zzz-timezone.ini
fi
sed "s/__PORT__/$WANKARR_PORT/" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

as_app() { su-exec wankarr "$@"; }

if [ $# -gt 0 ]; then
  exec su-exec wankarr "$@"
fi

# The database may still be starting (e.g. a MariaDB container); keep trying.
tries=0
until as_app php /app/bin/wankarr migrate; do
  tries=$((tries + 1))
  [ "$tries" -lt 30 ] || die "database unavailable (WANKARR_DB_DSN=$WANKARR_DB_DSN), giving up"
  echo "wankarr: waiting for the database…" >&2
  sleep 2
done

php-fpm84 -F &
fpm=$!
nginx -g 'daemon off;' &
web=$!
worker=""
if [ "${WANKARR_WORKER:-1}" = "1" ]; then
  # Scheduled scans and metadata refreshes; restarted if it ever exits.
  ( while :; do as_app php /app/bin/wankarr worker || true; sleep 5; done ) &
  worker=$!
fi

sleeper=""
stop() {
  kill -TERM "$fpm" "$web" $worker $sleeper 2>/dev/null || true
  pkill -TERM -f 'bin/wankarr (worker|tasks)' 2>/dev/null || true
  wait 2>/dev/null || true
}
trap 'stop; exit 0' TERM INT

while kill -0 "$fpm" 2>/dev/null && kill -0 "$web" 2>/dev/null; do
  sleep 5 & sleeper=$!
  wait "$sleeper" # wait is interruptible, so signals are handled at once
done
echo "wankarr: php-fpm or nginx stopped; exiting" >&2
stop
exit 1
