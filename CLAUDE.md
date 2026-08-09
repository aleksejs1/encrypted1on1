# CLAUDE.md

Guidance for Claude Code (and other AI assistants) working in this repository.

## Project

encrypted1on1 — a self-hosted, end-to-end encrypted platform for 1:1 meetings between managers and employees. See [README.md](README.md) for the overview.

## Current stage

Phases 1–3 are done. `backend/` is a hand-composed Symfony app — Doctrine ORM/migrations + API Platform wired in, with a `User` entity (`src/Entity/User.php`) as the only resource so far: `GET /api/users` (read-only, no write operations — those need real logic, not generic CRUD). `authHash`/`encryptedPrivateKey` deliberately carry no serialization group, so they're structurally excluded from API output. `frontend/` is a Svelte+Vite+TypeScript app with a placeholder page calling `/health`, plus a `src/crypto/` module (not wired into any UI yet) implementing the spec's crypto model: argon2id → HKDF-SHA256 split into `auth`/`master-key` (`password.ts`), X25519 keypair generation and private-key wrapping (`keypair.ts`), and `sessionStorage`-based master-key handling (`session.ts`) — covered by Vitest unit tests (`npm run test`). Two things worth knowing if you touch this module: it uses `libsodium-wrappers-sumo`, not the plain `libsodium-wrappers` package — the standard build doesn't actually expose `crypto_pwhash` (argon2id) despite having its constants; and the HKDF step deliberately uses native WebCrypto (`crypto.subtle`) rather than libsodium, because libsodium-wrappers (either build) exposes HKDF's byte-length constants but not the actual extract/expand functions — see the comment in `password.ts`. `docker-compose.dev.yml` + `Makefile` (`make up`/`make down`) run the backend and Mailpit; the frontend runs on the host via `npm run dev` and proxies to the backend (see `vite.config.ts`). No auth flow or anketa data yet — see the roadmap in `.claude/plans/virtual-gathering-minsky.md` for what's next (Phase 4: auth flow).

The detailed spec currently lives outside this repo as a local working document (not tracked in git); an English version is planned to land here (likely `docs/SPEC.md`) as a separate task. Until that exists, treat the constraints below as authoritative, and ask before assuming anything not covered here.

## Non-negotiable constraints

- **End-to-end encryption is the entire point of this product.** The server must never be able to derive plaintext content from what it stores. When in doubt about whether something should be encrypted client-side, assume yes. The one deliberate, narrow exception is a goal's title/description/status/target date (not its progress checkpoints) — nothing else gets that exception without an explicit, discussed product decision.
- **Code must stay simple enough to audit.** This is a privacy tool; its credibility depends on a reasonably technical user being able to read the code and verify the privacy claims themselves. Prefer fewer files, fewer abstractions, and boring solutions over clever ones. Don't add speculative flexibility for requirements that don't exist yet.
- **Repository language is English, without exception** — code, comments, commit messages, docs, issue/PR templates. Working discussions with the maintainer may happen in other languages, but nothing non-English lands in this repo.
- **License is AGPLv3** — see [LICENSE](LICENSE). Don't introduce dependencies or vendored code under incompatible licenses without flagging it first.

## Working style

- This project explicitly favors dumb-and-simple over clever, and has a documented history of scope creep during planning — when a change grows noticeably beyond what was asked, say so before building it, rather than quietly expanding scope.
