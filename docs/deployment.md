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
| `TZ` | No (`UTC`) | Timezone for container-level tools (the `date` command, log timestamps) — display/log-only. PHP's own date handling (including the daily reminder job's "is the meeting tomorrow" check) hardcodes UTC explicitly and never reads this, confirmed directly — changing it doesn't affect when reminders fire. |

Registration mode (`invite`/`admin_only`/`domain` — see [user-flow.md](user-flow.md#getting-an-account)) and the allowed email domain are no longer env vars — they're columns on the single `Company` row every deployment has (`private/cloud-service-plan.md`, not tracked in git, Phase A of a not-yet-built multi-tenant cloud offering). They default to `invite`/unrestricted, same as before. To change them, update that row directly: `docker compose exec app php bin/console dbal:run-sql "UPDATE companies SET registrationMode = 'domain', allowedEmailDomain = 'company.com'"`.

### Frontend build-time (baked into the static bundle)

Unlike everything above — which Symfony reads at runtime, so a plain `docker compose up -d` (no rebuild) picks up a changed value — this one is compiled into the SPA's JavaScript when the image is *built*. Changing it needs `--build`, not just a restart.

| Variable | Required | What it does |
|---|---|---|
| `ARGON2ID_PROFILE` | No (`interactive`) | The argon2id cost profile the browser uses to derive a user's login/master keys from their password: `interactive` (64MiB), `moderate` (256MiB), or `sensitive` (1GiB). Passed as a Docker build arg (`docker/prod/app.Dockerfile`), which Vite bakes into the bundle as `import.meta.env.VITE_ARGON2ID_PROFILE` (see `frontend/src/crypto/argon2Profile.ts`). |
| `PRIVACY_POLICY_URL` | No (empty = no footer link) | Your own company's privacy policy/legal notice URL, shown as a link in the app's footer (`frontend/src/design/AppFooter.svelte`) — legal responsibility for how data is handled sits with whoever deploys this tool, not the software itself. No "pick once" hazard like `ARGON2ID_PROFILE` above — changing it later is just a normal rebuild. |
| `DEMO_MODE` | No (`false`) | Set to `true` to show a "Try the live demo" button on the login page, which logs a visitor straight into a fixed, publicly-known demo account — see [Demo mode](#demo-mode) below. No "pick once" hazard — changing it later is just a normal rebuild. |
| `SHOW_VERSION` | No (`false`) | Set to `true` to show the app version (and commit hash, if `GIT_SHA` is set) in the footer (`frontend/src/design/AppFooter.svelte`) — handy for confirming what's actually deployed. Off by default: not every operator wants build/version details visible to end users. |
| `GIT_SHA` | No (empty) | Short commit hash to bake into the footer's version display alongside the version from `frontend/package.json`. `docker/prod/deploy.sh` computes this automatically (`git rev-parse --short HEAD`) — you don't need to set it by hand for the documented build-from-source deploy path. Only shown if `SHOW_VERSION` is also `true`. |

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

### Using MySQL instead of SQLite

Advanced, opt-in scenario for operators who already administer their own database infrastructure — not a separate compose file (the default SQLite path this app ships is what everything else in these docs assumes), just the pieces to adapt into your own setup.

**A real, working migration path, not just a `DATABASE_URL` change.** The default `migrations/` directory is hand-written raw SQL targeting SQLite's own dialect specifically (confirmed by actually running it against real MySQL: it fails on the very first migration with a `CLOB` syntax error, and several later ones lean on SQLite's own `CREATE TEMPORARY TABLE` rebuild workaround for limited `ALTER TABLE` support — none of it is portable). A separate `migrations-mysql/` directory holds one bootstrap migration instead, generated directly from the (already platform-portable) Doctrine entity mappings against a real, empty MySQL 8.4 database — reaching the exact same schema, in genuine MySQL DDL. It's registered in its own standalone Doctrine Migrations config, `backend/migrations-mysql.php`, deliberately **not** alongside the default namespace in `config/packages/doctrine_migrations.php` — registering both together was tried first and found to genuinely break the default SQLite migration flow: Doctrine sorts a combined list of every registered namespace's migrations by fully-qualified class name when no explicit target version is given, and `App\Migrations\MySQL\...` alphabetically outranks `App\Migrations\Version...`, so a bare `doctrine:migrations:migrate` (exactly what `composer test` and the documented prod migration command already run) tried to execute the MySQL-only migration against the SQLite connection first. Keeping it in a separate, non-registered config file sidesteps this entirely — the default commands never even know it exists.

```
# .env.prod (or backend/.env for local testing)
DATABASE_URL="mysql://user:pass@host:3306/dbname?serverVersion=8.4.0"
```

```yaml
# docker-compose.override.yml — a local MySQL service to test against;
# for real production use, point DATABASE_URL at your own managed MySQL instead
services:
  mysql:
    image: mysql:8.4
    environment:
      MYSQL_DATABASE: encrypted1on1
      MYSQL_USER: encrypted1on1
      MYSQL_PASSWORD: change-me
      MYSQL_ROOT_PASSWORD: change-me-too
    volumes:
      - mysql_data:/var/lib/mysql
volumes:
  mysql_data:
```

`pdo_mysql` is already installed in both the dev and prod images (alongside `pdo_sqlite`, which stays installed unconditionally too) — no custom image build needed, just point `DATABASE_URL` at a real MySQL instance and run the MySQL-specific migration command instead of the default one. Deliberately no explicit version argument — always migrates to the latest available migration in this namespace, so this same command stays correct as more migrations get added, rather than needing an edit here every time (see below for exactly that having gone stale once already):

```
docker compose exec app php bin/console doctrine:migrations:migrate --configuration=migrations-mysql.php --no-interaction
```

**Verified for real, not assumed working**: a real MySQL 8.4 container, the migrations applied cleanly to both a genuinely fresh database and a populated one (an existing row correctly backfilled into the seeded default company, not left with a broken reference), and the actual running app exercised against it over real HTTP — account activation, a second account, a real anketa created between them (real foreign keys), a real publish (writing to a `LONGTEXT` ciphertext column), `GET /api/users` correctly scoped to one company, and — through the actual `docker-compose.cloud.yml` stack, not just a hand-built container — a real self-service company created via `CLOUD_MODE=1` all worked correctly.

**Real, ongoing cost, not a one-time thing — and it already bit once, for real, not hypothetically.** `migrations-mysql/` genuinely drifted out of sync for a while: three SQLite migrations' worth of schema (the entire multi-tenant `companies` table, `users.company_id`/`isPlatformAdmin`, and the billing columns — private/cloud-service-plan.md's Phases A/C/D, not tracked in git) shipped with no MySQL equivalent, silently, until Phase E's own cloud-infra work went looking for one and found the gap. Caught and fixed by generating a fresh `doctrine:migrations:diff` against a real MySQL 8.4 database and hand-editing the result the same way every populated-table SQLite migration in this project's history has needed — the auto-generated version added `company_id`/`isPlatformAdmin` as bare `NOT NULL` with no default, which MySQL doesn't reject the way SQLite does; it silently backfills the column type's own zero-value instead (an empty string for `company_id`, which then failed the very next statement — adding the foreign key — with a real, reproduced `SQLSTATE[23000]` error). A genuinely different, arguably worse failure mode than SQLite's loud rejection, caught only by actually testing against a populated table, not by review. There's still no tooling here that generates both `migrations/` and `migrations-mysql/` automatically — any future schema-changing feature needs the second one written by hand too.

**Not currently covered**: `docker/prod/backup.sh`/`restore.sh` are SQLite-specific (`sqlite3 ... .backup`) — running against MySQL means bringing your own backup strategy (e.g. `mysqldump`/`mysqlbinlog`, or your managed MySQL provider's own snapshotting), not these scripts.

### Dev-only (`backend/.env`, already committed with working defaults)

`APP_ENV`/`APP_DEBUG`/`DATABASE_URL` are fixed for local dev and shouldn't need touching. `MAILER_DSN` already points at the bundled Mailpit container. The dev database's single seeded `Company` row defaults to `invite`/unrestricted, same meaning as in prod — to test a different mode locally, update that row directly (`docker compose exec backend php bin/console dbal:run-sql "UPDATE companies SET registrationMode = 'domain'"`), test, then revert the same way.

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

The image has a built-in `HEALTHCHECK` (`docker ps`/`docker inspect` show it) that hits `/health` on port 80 without following redirects — deliberately: once Caddy has a real certificate, port 80 replies with a redirect to HTTPS, which `curl -f` still treats as a passing check, so this catches the failure that actually matters for a container healthcheck (FrankenPHP/Caddy crashed or hung) without flapping unhealthy during the brief window before a fresh deployment's certificate is issued. It proves Caddy itself is responsive; it doesn't specifically prove the PHP backend behind it is, once HTTPS is up and redirecting.

Data (the SQLite database) lives in a named Docker volume, so it survives image rebuilds/redeploys — back it up before any major upgrade (see "Backups" below). Caddy's own TLS certificates/ACME account state get the same treatment (`caddy_data`/`caddy_config` volumes) — without it, every redeploy would force Caddy to re-provision a brand-new Let's Encrypt certificate from scratch, risking both downtime and Let's Encrypt's rate limits on frequent redeploys.

The container itself runs as a fixed non-root user (`app`, UID/GID 10001) — `setcap` on the `frankenphp` binary is what still lets it bind ports 80/443 despite not being root.

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

### Cloud deployment (multi-tenant)

A third topology, alongside direct and reverse-proxy above — for running the self-service, multi-company cloud offering (`private/cloud-service-plan.md`, not tracked in git) instead of a single self-hosted company. **Still Phases A–E of that plan, not a finished, generally-available SaaS** — read [`docs/history.md`](history.md)'s own Phase D entry before relying on this for real: the Stripe checkout flow specifically has never been exercised against a live Stripe account, and there is no Terms of Service/Data Processing Agreement bundled with this repository (see "What this doesn't cover" below).

Like the direct/reverse-proxy split above, this topology also has two variants — `docker-compose.cloud.yml` (this app owns 80/443, Caddy provisions its own TLS certificate) and `docker-compose.cloud.reverse-proxy.yml` (an existing reverse proxy on this host — already fronting, say, a landing page and/or a self-hosted/demo instance — owns those ports instead). The reverse-proxy variant is the one to use if you're running a cloud instance *alongside* an already-deployed landing page and demo on the same host, which is a completely normal thing to do — they're independent Compose projects with independent volumes/databases, and nothing about `CLOUD_MODE` requires exclusive use of the host.

1. Copy `.env.cloud.example` to `.env.cloud` and fill in real values — same core fields as `.env.prod` (`APP_SECRET`, `SERVER_NAME`/`FRONTEND_URL`, `MAILER_DSN`/`MAILER_FROM`), plus a MySQL `DATABASE_URL` (this topology hardcodes `CLOUD_MODE=1` and requires MySQL — see "Using MySQL instead of SQLite" above; SQLite's concurrency model was never validated for many companies sharing one database, only one company's realistic write volume — [ADR 3](adr/0003-sqlite-default-database.md)) and, optionally, `STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET`/`STRIPE_PRICE_ID` (leave empty to run without checkout capability — seat limits and company suspension both still work without Stripe configured at all). If you're using the reverse-proxy variant, also fill in `APP_INTERNAL_BIND` — pick a port not already claimed by another project's own `APP_INTERNAL_BIND`/similar on this host (`.env.cloud.example`'s "Only needed with docker-compose.cloud.reverse-proxy.yml" section has the rest — `TRUSTED_PROXIES`/`CADDY_TRUSTED_PROXIES`, same defaults/reasoning as the self-hosted reverse-proxy setup above).
2. Bring the stack up. Direct, if this instance owns 80/443 on its own:
   ```
   docker compose -f docker-compose.cloud.yml --env-file .env.cloud --profile bundled-db up -d --build
   ```
   Or, behind an existing reverse proxy:
   ```
   docker compose -f docker-compose.cloud.reverse-proxy.yml --env-file .env.cloud --profile bundled-db up -d --build
   ```
   Either way, omit `--profile bundled-db` if `DATABASE_URL` already points at your own managed MySQL instead — the bundled `mysql` service simply never starts.
3. Reverse-proxy variant only: point your existing proxy at `APP_INTERNAL_BIND`, the same shape as the self-hosted reverse-proxy nginx example above — a separate `server`/`location` block (different `server_name`, e.g. `cloud.example.com`, and a different `proxy_pass` port than any other project already using this host).
4. Run the **MySQL-specific** migration command (not the default one — see "Using MySQL instead of SQLite" above), substituting whichever compose file you used:
   ```
   docker compose -f docker-compose.cloud.yml --env-file .env.cloud exec app php bin/console doctrine:migrations:migrate --configuration=migrations-mysql.php --no-interaction
   ```
5. That's it — there's no separate admin-bootstrap command for this topology. The first company (and its first admin) is created by visiting the running instance and using the real "Start a new company" self-service flow (`/create-company`, shown on the login page once `CLOUD_MODE` is on), the same path every subsequent company goes through.

Backups and the non-root-container one-time-upgrade note are the same as the direct/reverse-proxy topologies above — just substitute `-f docker-compose.cloud.yml` or `-f docker-compose.cloud.reverse-proxy.yml --env-file .env.cloud` for the compose invocation. **Backups stay whole-instance** (the existing `backup.sh`/`restore.sh` pattern, once adapted for MySQL per "Not currently covered" above) — restoring a *single* company out of a shared-database backup, without touching every other company's data in the same file, is genuinely harder in this model and isn't solved here; a real, open ops gap, not glossed over.

**Redeploying needs one extra variable, not just a substituted compose file** — `./docker/prod/deploy.sh`'s migration step defaults to the plain `doctrine:migrations:migrate` command (correct for SQLite, the self-hosted topologies' database), which is the *wrong* migration namespace against this topology's MySQL database — see "Using MySQL instead of SQLite" above for why the two migration histories are separate. Set `MIGRATION_CONFIG` too:

```sh
PROD_COMPOSE_FILE=docker-compose.cloud.reverse-proxy.yml ENV_FILE=.env.cloud MIGRATION_CONFIG=--configuration=migrations-mysql.php ./docker/prod/deploy.sh
```

**A real, already-flagged future limitation, not a current one**: [ADR 4](adr/0004-session-based-auth.md) already states plainly that scaling to more than one app instance behind a load balancer would need shared session storage, which this app doesn't have. `docker-compose.cloud.yml` runs exactly one `app` container, same as the other topologies — fine for a good while on vertical scaling alone, but a real blocker the moment horizontal scaling is ever needed, flagged now rather than discovered as a surprise outage later.

**What this doesn't cover, deliberately**: choosing and provisioning a real hosting provider/region, and drafting a real Terms of Service and Data Processing Agreement, are both explicitly *not* engineering tasks this repository can complete on its own — see `private/cloud-service-plan.md`'s own "not an engineering decision" framing. A real DPA is a genuine precondition to onboarding paying customers, not optional paperwork: this app being end-to-end encrypted means the operator can't read anketa content, but the operator is still the data processor for real metadata (who's registered, which companies exist, who's paired with whom, when) and needs a lawyer-reviewed agreement covering that, not a template generated without legal review.

### Redeploying (updating an existing instance)

```sh
./docker/prod/deploy.sh
```

Builds the new image, recreates the container, runs migrations, and clears + restarts to pick up the freshly-compiled DI container — the exact sequence explained below, automated so it can't be run out of order or partially skipped (which has happened by hand). Defaults to the reverse-proxy topology (`docker-compose.prod.reverse-proxy.yml`); set `PROD_COMPOSE_FILE=docker-compose.prod.yml` for the direct-facing one, or `PROD_COMPOSE_FILE=docker-compose.cloud.yml ENV_FILE=.env.cloud MIGRATION_CONFIG=--configuration=migrations-mysql.php` for the cloud topology (see "Cloud deployment" above — that database is MySQL, so its migration history lives in a separate namespace the default command doesn't know about) — same override convention as `backup.sh`/`restore.sh`. Assumes you've already pulled the code you want deployed — it builds and redeploys whatever's currently checked out, nothing more. Ends with a real health check (`GET /health` inside the container) and exits non-zero if it doesn't come back healthy, so a broken deploy doesn't silently look finished.

**`--build` + `up -d` alone is not enough once an instance already has data.** `data:/app/var` is a named Docker volume covering all of `var/`, including Symfony's compiled DI container cache (`var/cache/prod`) — not just `var/data.db`. On a brand-new instance that volume starts empty, so this doesn't matter. On every deploy *after* the first, the volume already has the *previous* version's compiled container sitting in it, and Symfony (`APP_DEBUG=0`) doesn't re-validate its freshness — it just serves the stale one. If the new code changed any service's constructor (a new constructor argument, a new dependency), the old compiled container still calls the old signature against the new class, and every request touching that service throws `ArgumentCountError` before it can do anything — this has actually happened, not just a theoretical risk.

So every redeploy — after `--build`/pulling new code, after migrations — needs one more step: clear the stale compiled container and restart, so FrankenPHP's already-running worker processes (which hold the *old* container in memory even if the cache files on disk are gone) pick up a freshly-compiled one matching the new code.

```
docker compose -f docker-compose.prod.yml --env-file .env.prod exec app rm -rf var/cache/prod
docker compose -f docker-compose.prod.yml --env-file .env.prod restart app
```

(`-f docker-compose.prod.reverse-proxy.yml` instead, for that topology.) The `restart` is not optional — deleting the cache directory alone doesn't affect an already-running worker's in-memory container.

**One-time step if upgrading an instance that predates the non-root container change**: the image now runs as a fixed non-root user (`app`), but that only affects a *brand-new* named volume, seeded from the image on first creation — an already-existing `data:/app/var` volume from before this change keeps whatever ownership its files already had (`root`, from the old image), and upgrading the image alone doesn't retroactively fix that. Symptom: `Permission denied` errors writing to `var/cache/prod/` (translations, the compiled container, etc.) — this actually happened on a real deployment, not a theoretical risk. Fix once, the first time you deploy past this change:

```
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -u root app chown -R app:app /app/var
docker compose -f docker-compose.prod.yml --env-file .env.prod exec -u root app rm -rf /app/var/cache/prod
docker compose -f docker-compose.prod.yml --env-file .env.prod restart app
```

(`-f docker-compose.prod.reverse-proxy.yml` instead, for that topology.) `-u root` works even though the image's *default* user is now `app` — root was never removed from the image, only stopped being the default. Every redeploy *after* this one-time fix is back to just the cache-clear step above — the volume stays correctly owned by `app` from here on.

### Backups

`docker/prod/backup.sh` takes an online, consistent snapshot of the database (via SQLite's own `.backup` command, safe to run while the app is live) and copies it out to `./backups` on the host, pruning anything older than 14 days. Run it via cron, e.g. daily at 3am:

```
0 3 * * * cd /path/to/encrypted1on1 && ./docker/prod/backup.sh >> backups/backup.log 2>&1
```

`BACKUP_DIR`/`RETENTION_DAYS`/`ENV_FILE` env vars override the defaults (`./backups`, 14, `.env.prod`) if needed; `PROD_COMPOSE_FILE` (default `docker-compose.prod.yml`) if you deployed with `docker-compose.prod.reverse-proxy.yml` instead.

To restore a backup: `./docker/prod/restore.sh backups/data-<timestamp>.db` — stops the app, swaps in the backup file, restarts it.

This only gets the data out of the volume and onto the host's disk — getting `./backups` itself somewhere durable (offsite, cloud storage) is your own infrastructure's concern, not something this app manages.

### Token cleanup

`app:cleanup-expired-tokens` deletes `ActivationToken`/`PasswordResetToken` rows whose TTL has passed (24h/2h respectively — see each entity's own `TOKEN_TTL_HOURS`), used or not. Nothing else in the app ever removes a row from either table, so without this both grow forever. Cheap to run daily via cron, alongside the backup job:

```
0 4 * * * cd /path/to/encrypted1on1 && docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T app php bin/console app:cleanup-expired-tokens >> cleanup.log 2>&1
```

(`-f docker-compose.prod.reverse-proxy.yml`/`-f docker-compose.cloud.yml` instead, for those topologies — same substitution as every other cron line above; the cloud topology also needs `--configuration=migrations-mysql.php`-style awareness only for migrations, not for this command.)

### Demo mode

Lets a visitor click "Try the live demo" on the login page instead of needing real credentials — logs them straight into a fixed, publicly-known demo account (employee side; the demo manager account exists too, as the counterpart) with a realistic 3-cycle anketa history (2 archived, 1 current — enough for the Report page and the group-view trend sparklines to actually show something). One employee/manager pair per supported UI locale (en/ru/lv/es), each with genuinely translated content, not just a translated UI shell — `?lang=ru` (etc.) on any URL both switches the displayed language and picks the demo login to match, so a link from the landing page's own locale-aware CTAs lands a visitor on the demo in their own language. Two independent pieces, both opt-in:

1. **`DEMO_MODE=true`** (see the [Frontend build-time](#frontend-build-time-baked-into-the-static-bundle) table above) — shows the button. Needs `--build`.
2. **The reset cron job** — restores every locale's demo pair and 3-cycle history to its seeded state on a schedule (deletes and recreates each pair's anketas from scratch, not an in-place update — self-heals from any vandalism, not just edited content), so a visitor editing or clearing things out self-heals rather than degrading permanently:

```
0 * * * * cd /path/to/encrypted1on1 && docker compose -f docker-compose.prod.yml exec -T app php bin/console app:reset-demo-data >> demo-reset.log 2>&1
```

(`-f docker-compose.prod.reverse-proxy.yml` instead, for that topology.) Hourly is a reasonable default — the reset itself is cheap to run often, and it bounds how long a defaced demo stays visible to the next visitor. `bin/console app:reset-demo-data` creates every locale's demo accounts and anketa history on its first run if they don't exist yet, so there's no separate one-time setup step beyond adding this cron line.

**Turning on `DEMO_MODE` without the cron job still works** — the demo account is created the first time the button is clicked (well, the first time anyone logs into it; the account itself only exists once something creates it, so run `app:reset-demo-data` once by hand right after enabling `DEMO_MODE` if you're not also setting up the cron job) — it just never resets after a visitor changes something.

The seed content itself (`backend/fixtures/demo-seed.json`) is committed, real end-to-end-encrypted ciphertext — generated once, offline, by actually driving the app's real UI with real crypto (`frontend/scripts/generate-demo-fixture.mjs`), not fabricated or hand-written. The demo account is excluded from the real `GET /api/users` counterpart-picker (`User.isDemo`, `ExcludeDeletedUsersExtension`), so it never shows up in a real user's typeahead search.
