# syntax=docker/dockerfile:1
FROM composer:2 AS composer
FROM php:8.3-cli-bookworm AS development
RUN --mount=type=secret,id=cloud_ca,required=false \
    if [ -s /run/secrets/cloud_ca ]; then cp /run/secrets/cloud_ca /usr/local/share/ca-certificates/cloud-proxy.crt; update-ca-certificates; fi
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libpq-dev libzip-dev libicu-dev libonig-dev \
    && docker-php-ext-install -j2 pdo_pgsql pcntl bcmath intl mbstring zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
RUN groupadd -g 1000 app && useradd -u 1000 -g app -m app
ENV COMPOSER_HOME=/tmp/composer
COPY infrastructure/docker/bulk.ini /usr/local/etc/php/conf.d/bulk.ini
WORKDIR /app
USER app
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
