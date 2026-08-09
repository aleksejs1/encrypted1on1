# Prod image — serves both the built SPA and the API from one FrankenPHP
# container (see the Phase 7d plan for why: FrankenPHP/Caddy already does
# static file serving + automatic HTTPS, no separate nginx/TLS-termination
# container needed for a self-hosted single-tenant app). Named "app", not
# "backend" like the dev image, since it now serves the frontend too.

FROM node:22-alpine AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM dunglas/frankenphp:php8.4
WORKDIR /app

RUN apt-get update && apt-get install --no-install-recommends -y unzip libsqlite3-dev sqlite3 \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_sqlite

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --no-progress --no-dev --no-scripts --no-autoloader

COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev

# Built SPA lands alongside public/index.php — the Caddyfile below decides
# which requests go to which.
COPY --from=frontend-build /app/dist/ ./public/

# Overrides the base image's default Caddyfile — confirmed via `docker inspect`
# that the base image's default CMD reads exactly this path.
COPY docker/prod/Caddyfile /etc/frankenphp/Caddyfile
