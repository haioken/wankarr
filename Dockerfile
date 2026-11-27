# syntax=docker/dockerfile:1
#
# wankarr: PHP + Apache, with bsdtar/7-Zip for CBR/CB7/CBT.
#   /config  database (SQLite by default), caches, logs, optional config.php
#   /manga   your library
FROM php:8.4-apache-bookworm

ARG VERSION=0.0.0-dev
ARG REVISION=unknown

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libzip-dev libzip4 libarchive-tools 7zip; \
    docker-php-ext-install -j"$(nproc)" zip pdo_mysql opcache; \
    apt-mark manual libzip4; \
    apt-get purge -y --auto-remove libzip-dev; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qiE '^pdo_sqlite$'; \
    sed -ri 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf; \
    a2dissite 000-default; \
    a2enmod headers

COPY docker/apache.conf /etc/apache2/sites-enabled/wankarr.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-wankarr.ini
COPY docker/entrypoint.sh /usr/local/bin/wankarr-entrypoint

COPY --link bin /app/bin
COPY --link src /app/src
COPY --link public /app/public
COPY --link config.php VERSION /app/
RUN echo "$VERSION" > /app/VERSION && chmod +x /app/bin/wankarr /usr/local/bin/wankarr-entrypoint

ENV WANKARR_DATA=/config \
    WANKARR_LIBRARY=/manga \
    PUID=1000 \
    PGID=1000

LABEL org.opencontainers.image.title="wankarr" \
      org.opencontainers.image.description="Self-hosted manga library and page-curl reader for an *arr stack" \
      org.opencontainers.image.version="$VERSION" \
      org.opencontainers.image.revision="$REVISION"

WORKDIR /app
VOLUME ["/config"]
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/api/health.php >/dev/null || exit 1

ENTRYPOINT ["wankarr-entrypoint"]
CMD ["apache2-foreground"]
