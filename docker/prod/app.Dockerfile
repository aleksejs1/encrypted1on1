# Prod image — serves both the built SPA and the API from one FrankenPHP
# container (see the Phase 7d plan for why: FrankenPHP/Caddy already does
# static file serving + automatic HTTPS, no separate nginx/TLS-termination
# container needed for a self-hosted single-tenant app). Named "app", not
# "backend" like the dev image, since it now serves the frontend too.

FROM node:26-alpine AS frontend-build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
# argon2id cost profile baked into the static bundle — see
# docs/deployment.md's "Frontend build-time" section. Must be picked once,
# before any real user registers, and never changed afterwards: it's what
# both the login auth key and the private-key-unwrapping master key are
# derived from, so changing it locks every existing account out.
ARG ARGON2ID_PROFILE=interactive
ENV VITE_ARGON2ID_PROFILE=$ARGON2ID_PROFILE
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

# Runs as a fixed non-root user, not root — a real reduction in blast radius
# if this app is ever compromised via an RCE. 10001 is an arbitrary,
# non-system UID/GID, chosen only to avoid colliding with anything else in
# the base image. setcap grants the frankenphp binary CAP_NET_BIND_SERVICE
# so it can still bind ports 80/443 despite not running as root — verified
# for real that this actually works, not assumed (libcap2-bin, which
# provides setcap, is already present in the base image). /app/var, /data
# (Caddy's XDG_DATA_HOME — TLS certificates/ACME account state) and /config
# (XDG_CONFIG_HOME) are chowned *before* they become volume mount points:
# Docker seeds a brand-new named volume from whatever already exists at that
# path in the image, which is what makes those volumes come up owned by
# `app` on first run without any runtime chown step.
RUN groupadd --gid 10001 app \
    && useradd --uid 10001 --gid app --no-create-home --shell /usr/sbin/nologin app \
    && setcap 'cap_net_bind_service=+ep' /usr/local/bin/frankenphp \
    && mkdir -p /app/var /data /config \
    && chown -R app:app /app /data /config
USER app
