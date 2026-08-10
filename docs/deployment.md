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

## Production

There are two deployment topologies, depending on whether this host already has something else listening on ports 80/443.

### Direct (this app owns ports 80/443)

Use this if the host is dedicated to this app, or at least has 80/443 free.

1. Copy `.env.prod.example` to `.env.prod` and fill in real values — an `APP_SECRET` (`openssl rand -hex 32`), your real domain (`SERVER_NAME`/`FRONTEND_URL`), and a real SMTP DSN (`MAILER_DSN`/`MAILER_FROM`). Point DNS at the server first and make sure ports 80/443 are reachable — Caddy (bundled in the prod image via FrankenPHP) provisions its own HTTPS certificate automatically on first boot.
2. `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build` — builds and starts a single container serving both the built frontend and the API.
3. Run migrations (not automatic on boot, deliberately — nothing runs unattended against the database without an explicit command): `docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php bin/console doctrine:migrations:migrate --no-interaction`.
4. Bootstrap the first (admin) account: `docker compose -f docker-compose.prod.yml --env-file .env.prod exec app php bin/console app:create-activation-link <email> --admin`.

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

### Backups

`docker/prod/backup.sh` takes an online, consistent snapshot of the database (via SQLite's own `.backup` command, safe to run while the app is live) and copies it out to `./backups` on the host, pruning anything older than 14 days. Run it via cron, e.g. daily at 3am:

```
0 3 * * * cd /path/to/encrypted1on1 && ./docker/prod/backup.sh >> backups/backup.log 2>&1
```

`BACKUP_DIR`/`RETENTION_DAYS`/`ENV_FILE` env vars override the defaults (`./backups`, 14, `.env.prod`) if needed; `PROD_COMPOSE_FILE` (default `docker-compose.prod.yml`) if you deployed with `docker-compose.prod.reverse-proxy.yml` instead.

To restore a backup: `./docker/prod/restore.sh backups/data-<timestamp>.db` — stops the app, swaps in the backup file, restarts it.

This only gets the data out of the volume and onto the host's disk — getting `./backups` itself somewhere durable (offsite, cloud storage) is your own infrastructure's concern, not something this app manages.
