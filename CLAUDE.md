# CLAUDE.md

Guidance for Claude Code (and other AI assistants) working in this repository.

## Project

encrypted1on1 — a self-hosted, end-to-end encrypted platform for 1:1 meetings between managers and employees. See [README.md](README.md) for the overview.

## Current stage

Phases 1–4 are done — there's a working auth flow now, real account creation included. See `.claude/plans/virtual-gathering-minsky.md` for the phase roadmap and what's next (Phase 5: anketa core loop).

- **Backend** (`backend/`, hand-composed Symfony, no `symfony/skeleton`): Doctrine ORM/migrations + API Platform wired in. `User` entity (`src/Entity/User.php`, `isAdmin` flag) is the only API Platform resource — `GET /api/users`, read-only, no write operations there (account creation has real logic, so it's a custom controller, not generic CRUD). `authHash`/`encryptedPrivateKey` carry no serialization group, so they're structurally excluded from API output. Auth is entirely custom controllers + a plain Symfony session (httpOnly/SameSite=Strict cookie, `cookie_secure: auto`, not JWT) with CSRF protection: `ActivationController` (token lookup + complete — the *only* way accounts get created right now, via `bin/console app:create-activation-link <email> [--admin]`; no email sending, no `REGISTRATION_MODE` yet, see the plan for why), `AuthController` (`/api/login`, `/api/logout`, `/api/me`), `CsrfTokenController`. Login does a constant-time `hash_equals` comparison, against a dummy hash even for nonexistent emails, to avoid a timing-based user-enumeration channel.
- **Frontend** (`frontend/`, Svelte+Vite+TypeScript): `src/crypto/` implements the spec's crypto model — argon2id → HKDF-SHA256 split into `auth`/`master-key` (`password.ts`), the argon2id salt deterministically derived from the normalized email (`salt.ts`, BLAKE2b — chosen over a random per-user stored salt specifically to avoid an email-enumeration side channel and an extra round-trip), X25519 keypair generation and private-key wrapping (`keypair.ts` — `packWrappedPrivateKey`/`unpackWrappedPrivateKey` combine nonce+ciphertext into the one `encryptedPrivateKey` field that exists both in the DB and over the API), and `sessionStorage`-based master-key handling (`session.ts`). Covered by Vitest unit tests (`npm run test`). Two library quirks worth knowing before touching this module: it uses `libsodium-wrappers-sumo`, not the plain `libsodium-wrappers` package, because the standard build doesn't actually expose `crypto_pwhash` (argon2id) despite having its constants; and the HKDF step deliberately uses native WebCrypto (`crypto.subtle`) rather than libsodium, because libsodium-wrappers (either build) exposes HKDF's byte-length constants but not the actual extract/expand functions — see the comment in `password.ts`. `src/api/client.ts` is a thin fetch wrapper handling the CSRF token. `src/pages/{Activate,Login}.svelte` are the two views so far, branched on `window.location.pathname` in `App.svelte` — no router library yet, deliberately (see the plan).
- `docker-compose.dev.yml` + `Makefile` (`make up`/`make down`) run the backend and Mailpit (unused so far — no email sending yet); the frontend runs on the host via `npm run dev` and proxies `/health` + `/api` to the backend (see `vite.config.ts`).

The detailed spec currently lives outside this repo as a local working document (not tracked in git); an English version is planned to land here (likely `docs/SPEC.md`) as a separate task. Until that exists, treat the constraints below as authoritative, and ask before assuming anything not covered here.

## Non-negotiable constraints

- **End-to-end encryption is the entire point of this product.** The server must never be able to derive plaintext content from what it stores. When in doubt about whether something should be encrypted client-side, assume yes. The one deliberate, narrow exception is a goal's title/description/status/target date (not its progress checkpoints) — nothing else gets that exception without an explicit, discussed product decision.
- **Code must stay simple enough to audit.** This is a privacy tool; its credibility depends on a reasonably technical user being able to read the code and verify the privacy claims themselves. Prefer fewer files, fewer abstractions, and boring solutions over clever ones. Don't add speculative flexibility for requirements that don't exist yet.
- **Repository language is English, without exception** — code, comments, commit messages, docs, issue/PR templates. Working discussions with the maintainer may happen in other languages, but nothing non-English lands in this repo.
- **License is AGPLv3** — see [LICENSE](LICENSE). Don't introduce dependencies or vendored code under incompatible licenses without flagging it first.

## Working style

- This project explicitly favors dumb-and-simple over clever, and has a documented history of scope creep during planning — when a change grows noticeably beyond what was asked, say so before building it, rather than quietly expanding scope.
