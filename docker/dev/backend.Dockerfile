# Dev-only image for the backend (see plan Phase 1).
# Prod gets its own lean multi-stage Dockerfile in a later phase.
FROM dunglas/frankenphp:php8.5

WORKDIR /app

# Set to 0 by docker-compose.e2e.yml's build args — pcov is only ever needed for
# `composer test-coverage` (docker-compose.dev.yml/test.yml both keep the default),
# and the e2e stack never collects coverage. Real reason to bother, not
# speculative: pcov crashed the running FrankenPHP process outright (a segfault
# inside pcov.so, confirmed via a real GitHub Actions e2e run's failure pattern —
# a test hanging until its 60s timeout, then the next one failing with "service
# backend is not running") merely by being loaded and handling ordinary requests,
# with no coverage collection even in progress. Simply not loading it for e2e
# removes the crash risk at its source instead of working around the symptom.
ARG ENABLE_PCOV=1

RUN apt-get update && apt-get install --no-install-recommends -y unzip libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite \
    && docker-php-ext-install pdo_mysql

# Its own layer, not folded into the apt-get/pdo one above: Docker's build cache key for
# a RUN layer includes every ARG it references, so switching ENABLE_PCOV between building
# the dev/test image (1) and the e2e image (0) would otherwise bust the entire preceding
# layer — including the apt-get/pdo installs that don't actually depend on this value —
# forcing them to redo from scratch on every switch between the two stacks.
RUN if [ "$ENABLE_PCOV" = "1" ]; then pecl install pcov && docker-php-ext-enable pcov; fi

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
