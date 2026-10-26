# syntax=docker/dockerfile:1
FROM composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc AS composer
FROM php:8.3-fpm-bookworm@sha256:6b4641a875dd3e0f7551b2751e1f5909084856d18166af7fbb522ac230feb702 AS extensions
RUN --mount=type=secret,id=cloud_ca,required=false \
    if [ -s /run/secrets/cloud_ca ]; then cp /run/secrets/cloud_ca /usr/local/share/ca-certificates/cloud-proxy.crt; update-ca-certificates; fi
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev libonig-dev unzip \
    && docker-php-ext-install -j2 pdo_pgsql pcntl bcmath intl mbstring zip opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
FROM scratch AS archive_cache
COPY apps/api/composer.lock /placeholder
FROM extensions AS dependencies
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache --mount=from=archive_cache,target=/seed,readonly \
    if [ -d /seed/files ]; then cp -a /seed/files /tmp/composer-cache/; fi; \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction
COPY apps/api/ ./
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && rm -rf tests .env* storage/logs/* bootstrap/cache/*.php \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
FROM php:8.3-fpm-bookworm@sha256:6b4641a875dd3e0f7551b2751e1f5909084856d18166af7fbb522ac230feb702 AS runtime
# Runtime libraries only; build tools, Composer and the builder-only public CA stay out.
RUN apt-get update && apt-get install -y --no-install-recommends libpq5 libzip4 libicu72 libonig5 \
    && apt-get purge -y --no-auto-remove $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*
COPY --from=extensions /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=extensions /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
RUN php -r 'foreach (["pdo_pgsql","pcntl","bcmath","intl","mbstring","zip","sodium","xmlreader","Zend OPcache"] as $e) { if (!extension_loaded($e)) exit(1); }'
ARG RELEASE_SHA
LABEL org.opencontainers.image.revision=$RELEASE_SHA
RUN groupadd -g 10001 cts && useradd -u 10001 -g cts -M -s /usr/sbin/nologin cts
WORKDIR /app
COPY --from=dependencies --chown=10001:10001 /app /app
COPY --chmod=0444 infrastructure/deployment/php-fpm.conf /usr/local/etc/php-fpm.d/zz-cts.conf
COPY --chmod=0444 infrastructure/docker/bulk.ini /usr/local/etc/php/conf.d/bulk.ini
COPY --chmod=0444 infrastructure/deployment/php.ini /usr/local/etc/php/conf.d/release.ini
USER 10001:10001
EXPOSE 9000
CMD ["php-fpm", "-F"]
