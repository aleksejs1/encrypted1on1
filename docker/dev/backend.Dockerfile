# Dev-only image for the backend (see plan Phase 1).
# Prod gets its own lean multi-stage Dockerfile in a later phase.
FROM dunglas/frankenphp:php8.4

WORKDIR /app

RUN apt-get update && apt-get install --no-install-recommends -y unzip libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite \
    && docker-php-ext-install pdo_mysql \
    && pecl install pcov \
    && docker-php-ext-enable pcov

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize
