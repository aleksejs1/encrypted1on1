# 6. Single FrankenPHP container for API and SPA

## Status

Accepted.

## Context

The production deployment needs to serve a built Svelte SPA's static files, run the PHP backend, and terminate TLS. The conventional shape for this is two or three containers: a static-file/TLS-terminating reverse proxy (nginx or Caddy) in front of a separate PHP-FPM application container.

## Decision

[FrankenPHP](https://frankenphp.dev/) (built on Caddy) does all three jobs in one process/container: static file serving, PHP execution, and automatic HTTPS (Let's Encrypt, via `SERVER_NAME`). `docker/prod/Caddyfile` routes `/api/*` and `/health` to `php_server`; everything else tries the literal static asset and falls back to `index.html` so the SPA's own client-side router handles it. One image (`docker/prod/app.Dockerfile`), one deployable unit.

A standalone alternate, `docker-compose.prod.reverse-proxy.yml` + `Caddyfile.reverse-proxy`, exists for hosts where an existing reverse proxy already owns ports 80/443 — Caddy there binds one internal port only and disables `auto_https`, trusting the outer proxy for TLS instead. It's a complete second file, not a Compose overlay, since Compose's list-key merge semantics (e.g. `ports`) are easy to get subtly wrong across files.

## Consequences

- Fewer containers to run, network, and reason about for a single-tenant self-hosted app — no separate nginx/TLS container whose config has to stay in sync with the app container's routes.
- Automatic HTTPS is genuinely automatic in the direct-facing mode — no separate certbot/ACME tooling to operate.
- Ties the app to FrankenPHP/Caddy's own request-routing model — the Caddyfile's static-vs-API-vs-SPA-fallback logic has to be understood and kept correct as routes change, rather than being someone else's already-solved nginx config.
- The two deployment modes (direct vs. reverse-proxy) are genuinely different files with genuinely different trust assumptions (`TRUSTED_PROXIES`/`CADDY_TRUSTED_PROXIES`) — an operator must pick the one matching their actual topology; picking wrong silently breaks HTTPS/client-IP detection (see `docs/deployment.md`).
