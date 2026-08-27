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

# Off by default in PHP — with it off, every exception's stack trace frames carry the
# live argument values each function was called with. Sentry's SDK reads that straight
# off Throwable::getTrace() (config/packages/sentry.php, SentryBeforeSendFilter's own
# docblock), so a 500 anywhere in the call chain of a controller action that takes a
# secret as a plain argument — a password-reset token, an auth key — would otherwise
# ship that value to Sentry. A php.ini setting, not something an application-level
# before_send hook can retroactively scrub per call site.
RUN echo 'zend.exception_ignore_args=On' > /usr/local/etc/php/conf.d/no-exception-args.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-interaction --no-progress --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize
