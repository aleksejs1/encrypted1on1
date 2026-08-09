# CLAUDE.md

Guidance for Claude Code (and other AI assistants) working in this repository.

## Project

encrypted1on1 — a self-hosted, end-to-end encrypted platform for 1:1 meetings between managers and employees. See [README.md](README.md) for the overview.

## Current stage

Phases 1–5 are done — there's now a working end-to-end product: create an anketa, both sides fill it out and publish, each participant can read the other's published answers and nothing else. See `.claude/plans/virtual-gathering-minsky.md` for the phase roadmap and what's next (Phase 6: comments, "Итоги встречи"/"Цели", periodicity, reports, admin panel, i18n, typeahead/group-view — see that plan for why these are all deferred together).

- **Backend** (`backend/`, hand-composed Symfony, no `symfony/skeleton`): Doctrine ORM/migrations + API Platform wired in, defaulting to plain JSON (not JSON-LD — `config/packages/api_platform.php`; kept response shapes consistent with the hand-written controllers). `User` (`isAdmin` flag) is the only API Platform resource — `GET /api/users`, read-only. `authHash`/`encryptedPrivateKey` carry no serialization group, structurally excluded from API output. Everything else is custom controllers with real logic, not generic CRUD, on a plain Symfony session (httpOnly/SameSite=Strict cookie, CSRF-protected, not JWT):
  - `ActivationController`/`AuthController`/`CsrfTokenController` (Phase 4) — `bin/console app:create-activation-link <email> [--admin]` is still the only way to create an account; `/api/me` now also returns `publicKey`/`encryptedPrivateKey` so a page refresh can re-derive the unwrapped private key (see `identity.ts` below) without a full re-login.
  - `AnketaController` (Phase 5) — `Anketa` entity: per-side `{blob, publishedAt}` pairs (one column each, draft-vs-published is opaque to the server — only `publishedAt` distinguishes them) plus `employeeSealedKey`/`managerSealedKey` (the anketa's own symmetric key, `crypto_box_seal`-ed to each participant). Every route checks the requester is a participant. Publishing is one-way this phase (no re-edit-after-publish). `meetingDate` parsing uses the lenient `new \DateTimeImmutable($string)` constructor, not `createFromFormat(DATE_ATOM, ...)` — the latter rejects the milliseconds+`Z` that JS's `toISOString()` produces.
- **Frontend** (`frontend/`, Svelte+Vite+TypeScript): `src/crypto/` implements the spec's crypto model end to end now — argon2id → HKDF-SHA256 split (`password.ts`), the argon2id salt deterministically derived from the normalized email (`salt.ts`, BLAKE2b — no random per-user salt, no email-enumeration side channel, no extra round-trip), X25519 keypair generation/wrapping (`keypair.ts`), anketa-key generation/sealing plus the versioned `{schemaVersion, data}` blob envelope (`anketaKey.ts`), and `sessionStorage`-based master-key handling (`session.ts`). `identity.ts` holds the current session's *unwrapped* private key in a module-level variable only (never in browser storage) — `ensureUnlocked()` re-derives it from the master-key + `/api/me` and memoizes for the tab's lifetime. All covered by Vitest unit tests (`npm run test`). Library quirks worth knowing: `libsodium-wrappers-sumo`, not the plain package (the standard build doesn't expose `crypto_pwhash`/argon2id despite having its constants); HKDF deliberately uses native WebCrypto, not libsodium (neither build exposes real HKDF extract/expand, only byte-length constants) — see `password.ts`. `src/anketa/questions.ts` is the full 7+5 question schema (four field types: radio/checkboxes/text/dated-append-list) as data, per spec; `AnswerField.svelte` renders any of them (Svelte 5 `$bindable()` needs `let`, not `const`, in the destructured `$props()` — see the plan's implementation notes if this trips you up again). Routing (`router.svelte.ts`, `auth.svelte.ts`) is still hand-rolled — no library — path-matched in `App.svelte`.
- `docker-compose.dev.yml` + `Makefile` (`make up`/`make down`) run the backend and Mailpit (still unused — no email sending yet, see the Phase 5 plan for why that's fine); the frontend runs on the host via `npm run dev` and proxies `/health` + `/api` to the backend (see `vite.config.ts`).

The detailed spec currently lives outside this repo as a local working document (not tracked in git); an English version is planned to land here (likely `docs/SPEC.md`) as a separate task. Until that exists, treat the constraints below as authoritative, and ask before assuming anything not covered here.

## Non-negotiable constraints

- **End-to-end encryption is the entire point of this product.** The server must never be able to derive plaintext content from what it stores. When in doubt about whether something should be encrypted client-side, assume yes. The one deliberate, narrow exception is a goal's title/description/status/target date (not its progress checkpoints) — nothing else gets that exception without an explicit, discussed product decision.
- **Code must stay simple enough to audit.** This is a privacy tool; its credibility depends on a reasonably technical user being able to read the code and verify the privacy claims themselves. Prefer fewer files, fewer abstractions, and boring solutions over clever ones. Don't add speculative flexibility for requirements that don't exist yet.
- **Repository language is English, without exception** — code, comments, commit messages, docs, issue/PR templates. Working discussions with the maintainer may happen in other languages, but nothing non-English lands in this repo.
- **License is AGPLv3** — see [LICENSE](LICENSE). Don't introduce dependencies or vendored code under incompatible licenses without flagging it first.

## Working style

- This project explicitly favors dumb-and-simple over clever, and has a documented history of scope creep during planning — when a change grows noticeably beyond what was asked, say so before building it, rather than quietly expanding scope.
