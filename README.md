# encrypted1on1

[![CI](https://github.com/aleksejs1/encrypted1on1/actions/workflows/ci.yml/badge.svg)](https://github.com/aleksejs1/encrypted1on1/actions/workflows/ci.yml)

A self-hosted, end-to-end encrypted platform for running 1:1 meetings between managers and employees.

<img src="docs/screenshots/anketa.png" alt="An anketa page, showing a manager's published side with feedback and achievements alongside their encrypted private notes panel" width="700">

More: [screenshots](docs/screenshots/) — login, the anketa list, an empty vs filled-in anketa with private notes, the report view, dark mode, 6 supported languages, and a look at what the server's own API response actually contains.

## Status

Production-ready and feature-complete (v1.4.0):
- **End-to-end encrypted 1:1 cycles:** role-specific questions for employees and managers, Markdown-formatted answers with strict sanitization, private drafts, silent in-place editing, threaded comments, shared checklist outcomes with author ownership, and goal tracking with encrypted progress checkpoints.
- **Private meeting notes:** an author-scoped encrypted notepad on every anketa (sticky side-column on desktop, card on mobile) that neither counterparts, server operators, nor admins can read. Includes background autosave, multi-tab conflict resolution, "Hide notes" for screen-sharing privacy, and inclusion in user data exports.
- **Meeting templates & one-offs:** 4 built-in templates (Regular check-in, First 1:1 / Onboarding, Career growth, Support & workload check-in) with distinct question sets; ad-hoc one-off meetings that don't fork recurring chains or duplicate carry-forward items; automatic safe recurrence fallback to regular check-ins.
- **Live in-meeting collaboration:** real-time background sync during meetings so counterpart answer edits, new comments (with ARIA live announcements), outcomes, and checkpoints appear automatically without page reloads.
- **Streamlined read-only view:** clean display for published and archived anketas showing only answered questions and selected choices rather than long lists of empty fields or disabled controls.
- **Analytics & reports:** grouped anketa list by date or counterpart, mood/workload sparklines, upcoming meeting countdown badges, client-side cross-period growth reports, and company admin adoption reports.
- **Security & resilience:** zero-knowledge ciphertext storage, drafts encrypted with keys derived from private keys (surviving password changes), forgotten-password recovery with keypair regeneration and counterpart re-sharing, atomic archiving (no duplicate successors), rate limiting, CSP+SRI hardening with explicit HSTS, and automated privacy test gates.
- **Accounts & administration:** configurable registration (invite-only, admin-only, or email-domain self-registration), account settings (in-app password change, notification toggles, JSON data export, account deletion), admin user management and invite auditing, and automated reminder emails.
- **Accessibility & internationalization:** full keyboard navigation with focus retention across actions, 6 UI languages (English, German, French, Spanish, Russian, Latvian) with matching localized emails, dark mode, and Web App Manifest (PWA).
- **Architecture & backend:** hand-composed Symfony backend decomposed into dedicated repositories and domain lifecycle services, typed DTO request payloads with Symfony validation, Doctrine multi-tenant isolation filters, and JSON-standardized API error formatting.
- **Deployment & operations:** single-container FrankenPHP stack serving the API and SPA, automated database migrations (SQLite or MySQL), automated backup/restore scripts, and options for standalone Let's Encrypt HTTPS or running behind an existing reverse proxy.

See [`CLAUDE.md`](CLAUDE.md)'s "Current stage" section and [`docs/history.md`](docs/history.md) for the detailed development log and architectural decisions.

## Core idea

- **Self-hosted.** Your company runs it, your data stays on your own infrastructure.
- **End-to-end encrypted.** 1:1 content is encrypted client-side; the server only ever stores ciphertext derived from each user's password. Not even whoever operates the server can read it.
- **Open source.** Licensed under AGPLv3, so the privacy claims above can actually be verified by reading the code, not just taken on faith.

## Documentation

- **[docs/](docs/)** — start here: [how the encryption works](docs/encryption.md), the [1:1 methodology](docs/methodology.md) behind it, the [user flow](docs/user-flow.md) it produces, the [application architecture](docs/architecture.md), and [how to deploy it](docs/deployment.md) (dev, both production setups, and a full [configuration reference](docs/deployment.md#configuration)).
- **[CLAUDE.md](CLAUDE.md)** — development notes for anyone working on the codebase itself.

## Quick start (dev)

```
make up          # starts the backend (FrankenPHP) and Mailpit
cd frontend
npm install
npm run dev      # frontend dev server, proxies API calls to the backend
```

`make down` stops the backend/Mailpit containers; `make test`/`make lint`/`make coverage` run the backend+frontend test suites against the running dev stack, `make test-backend-isolated` (plus `lint-`/`coverage-backend-isolated`) run the backend suite in a fully separate, one-shot stack with its own database instead — no dev stack required — and `make e2e` runs the dual-actor Playwright suite against its own genuinely isolated stack (`make e2e-down` to tear it down afterward) (see [docs/architecture.md](docs/architecture.md#testing-and-ci)). See [docs/deployment.md](docs/deployment.md) for the full picture, including production.

## Git hooks

```
git config core.hooksPath .githooks
```

One-time, per clone (not committed by git itself). `.githooks/pre-commit` autofixes and re-stages formatting on whatever's actually staged (`php-cs-fixer`/Prettier — skipped with a warning, not blocked, if the dev stack/`node_modules` aren't ready) plus a whitespace/conflict-marker check; `.githooks/pre-push` runs `composer schema-validate`/`composer stan` (Doctrine mapping check plus PHPStan) on the backend and a typecheck (`npm run check`) on the frontend, scoped to whichever of `backend/`/`frontend/` actually changed since the push target. Both stay fast on purpose — CI and `make test`/`make lint`/`make coverage` own the exhaustive checks, the hooks just catch the cheap stuff before it leaves your machine.

## License

AGPLv3 — see [LICENSE](LICENSE).

## Security

Found a vulnerability? See [SECURITY.md](SECURITY.md) for how to report it privately.

## Contributing

Not currently set up for external contributions (no issue templates, no contribution guidelines yet) — open an issue first if you're interested, rather than sending an unsolicited PR.
