# encrypted1on1

[![CI](https://github.com/aleksejs1/encrypted1on1/actions/workflows/ci.yml/badge.svg)](https://github.com/aleksejs1/encrypted1on1/actions/workflows/ci.yml)

A self-hosted, end-to-end encrypted platform for running 1:1 meetings between managers and employees.

## Status

Early stage. Implementation has just started: a minimal skeleton (backend boots, frontend boots, they talk to each other) exists, but no real functionality — auth, encryption, the 1:1 flow itself — has landed yet.

## Core idea

- **Self-hosted.** Your company runs it, your data stays on your own infrastructure.
- **End-to-end encrypted.** 1:1 content is encrypted client-side; the server only ever stores ciphertext derived from each user's password. Not even whoever operates the server can read it.
- **Open source.** Licensed under AGPLv3, so the privacy claims above can actually be verified by reading the code, not just taken on faith.

## Quick start (dev)

```
make up          # starts the backend (FrankenPHP) and Mailpit
cd frontend
npm install
npm run dev      # frontend dev server, proxies API calls to the backend
```

`make down` stops the backend/Mailpit containers.

## Production

1. Copy `.env.prod.example` to `.env.prod` and fill in real values — an `APP_SECRET` (`openssl rand -hex 32`), your real domain (`SERVER_NAME`/`FRONTEND_URL`), and a real SMTP DSN (`MAILER_DSN`/`MAILER_FROM`). Point DNS at the server first and make sure ports 80/443 are reachable — Caddy (bundled in the prod image via FrankenPHP) provisions its own HTTPS certificate automatically on first boot.
2. `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --build` — builds and starts a single container serving both the built frontend and the API.
3. Run migrations (not automatic on boot, deliberately — see `CLAUDE.md`): `docker compose -f docker-compose.prod.yml exec app php bin/console doctrine:migrations:migrate --no-interaction`.
4. Bootstrap the first (admin) account: `docker compose -f docker-compose.prod.yml exec app php bin/console app:create-activation-link <email> --admin`.

Data (the SQLite database) lives in a named Docker volume, so it survives image rebuilds/redeploys — back it up before any major upgrade (a documented backup procedure is a later phase).

## License

AGPLv3 — see [LICENSE](LICENSE).

## Contributing

Not open for contributions yet — the project is still being scoped. This section will be updated once there's a codebase to contribute to.
