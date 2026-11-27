# syntax=docker/dockerfile:1
#
# wankarr: Alpine + nginx + PHP-FPM (8.4), with bsdtar (libarchive) for CBR/CB7/CBT.
# tini runs as PID 1; the entrypoint supervises php-fpm, nginx and the task
# worker. GPL-3.0-or-later.
#   /config  database (SQLite by default), caches, logs, optional config.php
#   /manga   your library
FROM alpine:3.24

ARG VERSION=0.0.0-dev
ARG REVISION=unknown

RUN set -eux; \
    apk add --no-cache \
        php84 php84-fpm php84-opcache php84-pdo php84-pdo_mysql php84-pdo_sqlite \
        php84-zip php84-mbstring php84-fileinfo php84-posix \
        nginx libarchive-tools tini su-exec; \
    ln -sf /usr/bin/php84 /usr/bin/php; \
    addgroup -g 1000 wankarr; \
    adduser -D -H -h /app -u 1000 -G wankarr -s /sbin/nologin wankarr; \
    rm -f /etc/php84/php-fpm.d/*.conf /etc/nginx/http.d/*.conf; \
    rm -rf /var/cache/apk/* /usr/share/man /usr/share/doc

COPY docker/nginx.conf /etc/nginx/nginx.conf.template
COPY docker/php-fpm.conf /etc/php84/php-fpm.conf
COPY docker/php.ini /etc/php84/conf.d/zz-wankarr.ini
COPY docker/entrypoint.sh /usr/local/bin/wankarr-entrypoint

COPY --link bin /app/bin
COPY --link src /app/src
COPY --link public /app/public
COPY --link config.php VERSION /app/
RUN echo "$VERSION" > /app/VERSION && chmod +x /app/bin/wankarr /usr/local/bin/wankarr-entrypoint

ENV WANKARR_PORT=8484 \
    WANKARR_DATA=/config \
    WANKARR_LIBRARY=/manga \
    WANKARR_PHP=/usr/bin/php84 \
    PUID=1000 \
    PGID=1000

LABEL org.opencontainers.image.title="wankarr" \
      org.opencontainers.image.description="Self-hosted manga library and page-curl reader for an *arr stack" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.version="$VERSION" \
      org.opencontainers.image.revision="$REVISION"

WORKDIR /app
VOLUME ["/config"]
EXPOSE 8484
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD wget -q -O /dev/null "http://127.0.0.1:${WANKARR_PORT:-8484}/api/health.php" || exit 1

ENTRYPOINT ["/sbin/tini", "--", "wankarr-entrypoint"]
