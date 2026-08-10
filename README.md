# encrypted1on1

[![CI](https://github.com/aleksejs1/encrypted1on1/actions/workflows/ci.yml/badge.svg)](https://github.com/aleksejs1/encrypted1on1/actions/workflows/ci.yml)

A self-hosted, end-to-end encrypted platform for running 1:1 meetings between managers and employees.

## Status

Feature-complete and styled: authentication and invites, end-to-end encrypted 1:1 cycles (questions, comments, shared outcomes, goals with progress checkpoints), reminder emails, an admin panel, a cross-period report view, 4-language i18n, dark mode, and a full production deployment path (including running behind an existing reverse proxy). See `CLAUDE.md`'s "Current stage" section for the exact up-to-date state of ongoing work — kept there, not duplicated here, so this file doesn't go stale the same way again.

## Core idea

- **Self-hosted.** Your company runs it, your data stays on your own infrastructure.
- **End-to-end encrypted.** 1:1 content is encrypted client-side; the server only ever stores ciphertext derived from each user's password. Not even whoever operates the server can read it.
- **Open source.** Licensed under AGPLv3, so the privacy claims above can actually be verified by reading the code, not just taken on faith.

## Documentation

- **[docs/](docs/)** — start here: [how the encryption works](docs/encryption.md), the [user flow](docs/user-flow.md) it produces, the [application architecture](docs/architecture.md), and [how to deploy it](docs/deployment.md) (dev and both production setups).
- **[CLAUDE.md](CLAUDE.md)** — development notes for anyone working on the codebase itself.

## Quick start (dev)

```
make up          # starts the backend (FrankenPHP) and Mailpit
cd frontend
npm install
npm run dev      # frontend dev server, proxies API calls to the backend
```

`make down` stops the backend/Mailpit containers. See [docs/deployment.md](docs/deployment.md) for the full picture, including production.

## License

AGPLv3 — see [LICENSE](LICENSE).

## Contributing

Not currently set up for external contributions (no issue templates, no contribution guidelines yet) — open an issue first if you're interested, rather than sending an unsolicited PR.
