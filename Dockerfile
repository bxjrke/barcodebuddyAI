FROM golang:1.20 AS build_supervisor
WORKDIR /build
COPY supervisor/ ./supervisor/
RUN cd supervisor && CGO_ENABLED=0 go build -o /build/supervisor-bin .

FROM alpine:3.20

RUN apk add --no-cache \
    apache2-utils \
    bash \
    ca-certificates \
    coreutils \
    curl \
    evtest \
    nano \
    nginx \
    openssl \
    php83 \
    php83-curl \
    php83-fileinfo \
    php83-fpm \
    php83-gettext \
    php83-mbstring \
    php83-openssl \
    php83-pdo \
    php83-pdo_sqlite \
    php83-redis \
    php83-session \
    php83-simplexml \
    php83-sockets \
    php83-sqlite3 \
    php83-xml \
    php83-xmlwriter \
    php83-zlib \
    procps \
    redis \
    screen \
    shadow \
    sudo \
    tzdata

RUN mkdir -p /app /app/bbuddy /config /defaults \
    && touch /app/.isdocker \
    && useradd -d /config -s /bin/false barcodebuddy \
    && [ -e /usr/bin/php ] || ln -s /usr/bin/php83 /usr/bin/php \
    && [ -e /usr/bin/php8 ] || ln -s /usr/bin/php83 /usr/bin/php8 \
    && ln -s /config /data \
    && chown redis:redis /etc/redis.conf \
    && chmod 755 /etc

COPY root/ /
COPY . /app/bbuddy
COPY --from=build_supervisor /build/supervisor-bin /app/supervisor

RUN rm -rf /app/bbuddy/.git /app/bbuddy/.github /app/bbuddy/.dev \
    && sed -i 's/[[:blank:]]*const[[:blank:]]*IS_DOCKER[[:blank:]]*=[[:blank:]]*false;/const IS_DOCKER = true;/g' /app/bbuddy/config-dist.php \
    && sed -i 's/SCRIPT_LOCATION=.*/SCRIPT_LOCATION="\/app\/bbuddy\/index.php"/g' /app/bbuddy/example/grabInput.sh \
    && sed -i 's/WWW_USER=.*/WWW_USER="barcodebuddy"/g' /app/bbuddy/example/grabInput.sh \
    && sed -i 's/IS_DOCKER=.*/IS_DOCKER=true/g' /app/bbuddy/example/grabInput.sh \
    && sed -i 's/pm.max_children = 5/pm.max_children = 20/g' /etc/php83/php-fpm.d/www.conf \
    && sed -i 's/const DEFAULT_USE_REDIS =.*/const DEFAULT_USE_REDIS = "1";/g' /app/bbuddy/incl/db.inc.php \
    && echo 'fastcgi_param  SCRIPT_FILENAME $document_root$fastcgi_script_name;' >> /etc/nginx/fastcgi_params \
    && rm -f /etc/nginx/conf.d/default.conf \
    && echo "Set disable_coredump false" > /etc/sudo.conf \
    && chown -R barcodebuddy:barcodebuddy /var/log/php83 /app/bbuddy

EXPOSE 80 443
VOLUME ["/config"]

CMD ["/app/supervisor"]
