# Deployment

How to run this app, in development and in production. For what's actually running and why, see [architecture.md](architecture.md).

## Development

```
make up          # starts the backend (FrankenPHP) and Mailpit, via docker-compose.dev.yml
cd frontend
npm install
npm run dev      # frontend dev server on Vite, proxies /health and /api to the backend
```

`make down` stops the backend/Mailpit containers.

What's actually running:
- **`backend`** — `docker/dev/backend.Dockerfile`, source bind-mounted (edits apply without a rebuild), listening on `localhost:8000`.
- **`mailpit`** — an SMTP catcher standing in for real email in dev; `MAILER_DSN` already points at it. View anything the app sends at `http://localhost:8025`.
- **frontend** — runs on the host, not in Docker, via plain `npm run dev`; `vite.config.ts` proxies `/health` and `/api` requests to `localhost:8000` so the browser only ever talks to one origin.

Useful commands while developing:
```
# backend, from inside the container
docker compose -f docker-compose.dev.yml exec backend composer test   # PHPUnit
docker compose -f docker-compose.dev.yml exec backend composer stan   # PHPStan

# frontend, from the frontend/ directory
npm run check   # svelte-check + tsc
npm run test    # Vitest
npm run build   # production build

# manually trigger the daily reminder-email job (nothing runs it automatically in dev)
docker compose -f docker-compose.dev.yml exec backend php bin/console app:send-reminders
```

Bootstrapping the very first account (needed once, on a fresh database — see [user-flow.md](user-flow.md#getting-an-account) for why this is CLI-only):
```
docker compose -f docker-compose.dev.yml exec backend php bin/console app:create-activation-link <email> --admin
```

## Configuration

Everything the app reads from the environment. `backend/.env` (committed, dev-only placeholder values) and `.env.prod.example` (copy to `.env.prod`, gitignored, real values) carry the same information as inline comments — this is the narrative version, in one place.

### Core (every production deployment)

| Variable | Required | What it does |
|---|---|---|
| `APP_SECRET` | Yes | Random, unique to this deployment (`openssl rand -hex 32`). Never reuse the dev placeholder. |
| `SERVER_NAME` | Yes | The real public domain this instance is reachable at. With `docker-compose.prod.yml`, Caddy uses this to provision an automatic HTTPS certificate — ports 80/443 must be open and DNS must already point here. With the reverse-proxy setup, it's just used for routing/links; your existing proxy handles TLS. Never `localhost` or a bare IP. |
| `FRONTEND_URL` | Yes | Same domain as `SERVER_NAME`, with scheme (`https://...`) — used to build the links inside notification/activation/reset emails. |
| `MAILER_DSN` | Yes | A real SMTP DSN ([Symfony Mailer's DSN format](https://symfony.com/doc/current/mailer.html#using-built-in-transports)). The dev/test `smtp://mailpit:1025` placeholder only works against the dev Mailpit container. |
| `MAILER_FROM` | Yes | The `From:` address on every outbound email. |
| `REGISTRATION_MODE` | No (`invite`) | `invite` (any authenticated user can invite), `admin_only` (only admins can invite), or `domain` (open self-registration, double opt-in — see [user-flow.md](user-flow.md#getting-an-account)). |
| `ALLOWED_EMAIL_DOMAIN` | No (empty = unrestricted) | Restricts which email domain can be invited *or* self-registered, e.g. `company.com`. Applies regardless of `REGISTRATION_MODE`. |

### Frontend build-time (baked into the static bundle)

Unlike everything above — which Symfony reads at runtime, so a plain `docker compose up -d` (no rebuild) picks up a changed value — this one is compiled into the SPA's JavaScript when the image is *built*. Changing it needs `--build`, not just a restart.

| Variable | Required | What it does |
|---|---|---|
| `ARGON2ID_PROFILE` | No (`interactive`) | The argon2id cost profile the browser uses to derive a user's login/master keys from their password: `interactive` (64MiB), `moderate` (256MiB), or `sensitive` (1GiB). Passed as a Docker build arg (`docker/prod/app.Dockerfile`), which Vite bakes into the bundle as `import.meta.env.VITE_ARGON2ID_PROFILE` (see `frontend/src/crypto/argon2Profile.ts`). |

**Pick this once, before any real user registers, and never change it on a running instance afterwards.** The password never reaches the server — both the login auth key *and* the master key that unwraps a user's stored private key are derived entirely client-side from this profile. Changing it makes every subsequent login recompute different keys: the auth key no longer matches what the server has on file (login fails outright), and even if it somehow did, the master key would no longer unwrap the stored encrypted private key. This locks out every existing account irrecoverably — not something the password-reset flow cleanly fixes either, since a reset still needs the account to log in first to prove ownership before issuing a fresh keypair.

### Reverse-proxy mode only (`docker-compose.prod.reverse-proxy.yml`)

| Variable | Required | What it does |
|---|---|---|
| `APP_INTERNAL_BIND` | Yes | Where Caddy listens for the reverse proxy to connect (e.g. `127.0.0.1:8090`) — bind to loopback, not `0.0.0.0`, since this is an internal implementation detail, not meant to be reachable directly. Must match the proxy's `proxy_pass`/upstream target. |
| `TRUSTED_PROXIES` | No (`127.0.0.1,::1`) | Told to Symfony (`backend/config/packages/framework.php`) — which upstream IPs to trust `X-Forwarded-*` headers from. Without this, the session cookie silently loses its `Secure` flag even on real HTTPS traffic, since Caddy only ever sees the internal plain-HTTP hop from the proxy. Widen if the proxy runs elsewhere (a different container, a separate host). |
| `CADDY_TRUSTED_PROXIES` | No (`127.0.0.1 ::1`) | The same trust decision, told to Caddy itself — space-separated (Caddyfile syntax, not comma-separated like `TRUSTED_PROXIES`). Both need to agree independently; setting only one is a real, documented FrankenPHP footgun. |

The direct-facing `docker-compose.prod.yml` also accepts `TRUSTED_PROXIES` (defaults to empty, since its own Caddy terminates TLS directly) — only relevant if that directly-facing Caddy is itself behind something like Cloudflare.

### Backup/restore scripts

Not `.env.prod` values — plain shell environment variables read by `docker/prod/backup.sh`/`restore.sh` themselves, e.g. set inline on the cron line:

| Variable | Default | What it does |
|---|---|---|
| `ENV_FILE` | `.env.prod` | Passed to `docker compose --env-file` — every invocation against a prod compose file needs this, including `backup.sh`'s own. |
| `PROD_COMPOSE_FILE` | `docker-compose.prod.yml` | Set to `docker-compose.prod.reverse-proxy.yml` if that's how you deployed. |
| `BACKUP_DIR` | `./backups` | Where snapshots land on the host. |
| `RETENTION_DAYS` | `14` | Snapshots older than this are pruned on every `backup.sh` run. |

### Dev-only (`backend/.env`, already committed with working defaults)

`APP_ENV`/`APP_DEBUG`/`DATABASE_URL` are fixed for local dev and shouldn't need touching. `MAILER_DSN` already points at the bundled Mailpit container. `REGISTRATION_MODE`/`ALLOWED_EMAIL_DOMAIN` default to `invite`/unrestricted, same meaning as in prod — edit this file directly to test a different mode locally (see the "real domain-mode verification" pattern `CLAUDE.md` documents: edit, `docker compose up -d --force-recreate backend`, test, then revert).

## Production

There are two deployment topologies, depending on whether this host already has something else listening on ports 80/443.

### Direct (this app owns ports 80/443)

Use this if the host is dedicated to this app, or at least has 80/443 free.

1. Copy `.env.prod.example` to `.env.prod` and fill in real values — an `APP_SECRET` (`openssl rand -hex 32`), your real domain (`SERVER_NAME`/`FRONTEND_URL`), and a real SMTP DSN (`MAILER_DSN`/`MAILER_FROM`). Point DNS at the server first and make sure ports 80/443 are reachable — Caddy (bundled in the prod image via FrankenPHP) provisions its own HTTPS certificate automatically on first boot.
2. `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build` — builds and starts a single container serving both the built frontend and the API.
3. Run migrations (not automatic on boot, deliberately — nothing runs unattended against the database without an explicit command): `docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php bin/console doctrine:migrations:migrate --no-interaction`.
4. Bootstrap the first (admin) account: `docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php bin/console app:create-activation-link <email> --admin`.

Steps 1–4 are first-time setup only. **Updating an existing instance to newer code is not just steps 2–3 again** — see "Redeploying" below, or the app will keep serving a stale compiled container and throw `ArgumentCountError`/similar on the first request that touches whatever changed.

`--env-file .env.prod` is needed on *every* invocation against this file, not just `up` — including `exec` — since Compose re-parses the file's required-variable interpolation (`${VAR:?message}`) every time it's called, the same reason `backup.sh`/`restore.sh` need it explicitly rather than relying on already-exported shell variables.

Data (the SQLite database) lives in a named Docker volume, so it survives image rebuilds/redeploys — back it up before any major upgrade (see "Backups" below).

### Behind an existing reverse proxy

Use this if this host already runs its own nginx (or similar) that owns ports 80/443 and reverse-proxies to several other, unrelated projects — `docker-compose.prod.yml` won't work as-is, since Caddy would fail to bind those already-taken ports.

`docker-compose.prod.reverse-proxy.yml` is a standalone alternate (same image, not layered on top of the file above): Caddy binds to one internal-only port and never attempts its own HTTPS certificate; your existing proxy terminates TLS and forwards plain HTTP to that port.

1. In `.env.prod`, fill in `APP_INTERNAL_BIND` (e.g. `127.0.0.1:8090`) plus the usual `APP_SECRET`/`SERVER_NAME`/etc. — see the "Only needed with docker-compose.prod.reverse-proxy.yml" section in `.env.prod.example` for `TRUSTED_PROXIES`/`CADDY_TRUSTED_PROXIES` (default to trusting loopback, correct if your reverse proxy runs on the same host).
2. `docker compose -f docker-compose.prod.reverse-proxy.yml --env-file .env.prod up -d --build`.
3. Point your existing reverse proxy at it. An nginx `server` block, terminating TLS as it already does for your other sites:

   ```nginx
   server {
       listen 443 ssl;
       server_name 1on1.example.com;

       # ... your existing TLS cert config ...

       location / {
           proxy_pass http://127.0.0.1:8090;
           proxy_set_header Host $host;
           proxy_set_header X-Forwarded-Proto $scheme;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Host $host;
       }
   }
   ```

   `X-Forwarded-Proto` specifically is the one that matters most — without it, the app can't tell the connection was actually HTTPS, and the session cookie silently loses its `Secure` flag even though the browser is on a real HTTPS connection.
4. Migrations/admin bootstrap are the same commands as the direct setup above, just with `-f docker-compose.prod.reverse-proxy.yml` instead. Same for `backup.sh`/`restore.sh` — export `PROD_COMPOSE_FILE=docker-compose.prod.reverse-proxy.yml` first (see "Backups" below).

**HSTS is already handled — no extra config needed on your reverse proxy.** `docker/prod/Caddyfile.reverse-proxy` sends `Strict-Transport-Security` on its own internal plain-HTTP response; as long as your reverse proxy relays response headers through unmodified (the default for a plain nginx `proxy_pass` block like the one above, and for most reverse proxies generally), it reaches the browser correctly over the real external HTTPS connection. Only worth checking if your proxy config explicitly strips response headers (`proxy_hide_header`/equivalent).

### Redeploying (updating an existing instance)

**`--build` + `up -d` alone is not enough once an instance already has data.** `data:/app/var` is a named Docker volume covering all of `var/`, including Symfony's compiled DI container cache (`var/cache/prod`) — not just `var/data.db`. On a brand-new instance that volume starts empty, so this doesn't matter. On every deploy *after* the first, the volume already has the *previous* version's compiled container sitting in it, and Symfony (`APP_DEBUG=0`) doesn't re-validate its freshness — it just serves the stale one. If the new code changed any service's constructor (a new constructor argument, a new dependency), the old compiled container still calls the old signature against the new class, and every request touching that service throws `ArgumentCountError` before it can do anything — this has actually happened, not just a theoretical risk.

So every redeploy — after `--build`/pulling new code, after migrations — needs one more step: clear the stale compiled container and restart, so FrankenPHP's already-running worker processes (which hold the *old* container in memory even if the cache files on disk are gone) pick up a freshly-compiled one matching the new code.

```
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app rm -rf var/cache/prod
docker compose -f docker-compose.prod.yml --env-file .env.prod restart app
```

(`-f docker-compose.prod.reverse-proxy.yml` instead, for that topology.) The `restart` is not optional — deleting the cache directory alone doesn't affect an already-running worker's in-memory container.

### Backups

`docker/prod/backup.sh` takes an online, consistent snapshot of the database (via SQLite's own `.backup` command, safe to run while the app is live) and copies it out to `./backups` on the host, pruning anything older than 14 days. Run it via cron, e.g. daily at 3am:

```
0 3 * * * cd /path/to/encrypted1on1 && ./docker/prod/backup.sh >> backups/backup.log 2>&1
```

`BACKUP_DIR`/`RETENTION_DAYS`/`ENV_FILE` env vars override the defaults (`./backups`, 14, `.env.prod`) if needed; `PROD_COMPOSE_FILE` (default `docker-compose.prod.yml`) if you deployed with `docker-compose.prod.reverse-proxy.yml` instead.

To restore a backup: `./docker/prod/restore.sh backups/data-<timestamp>.db` — stops the app, swaps in the backup file, restarts it.

This only gets the data out of the volume and onto the host's disk — getting `./backups` itself somewhere durable (offsite, cloud storage) is your own infrastructure's concern, not something this app manages.
