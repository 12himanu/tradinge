# Development image for Laravel (API server, Horizon, scheduler).
# Application code is bind-mounted; this image only provides PHP and extensions.
FROM php:8.4-cli-bookworm

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl git unzip \
    && install-php-extensions pdo_pgsql pgsql pcntl intl bcmath gd zip opcache redis \
    && rm -rf /var/lib/apt/lists/*

COPY php.ini /usr/local/etc/php/conf.d/zz-stock-ai.ini

WORKDIR /var/www/backend
EXPOSE 8000
