# Decisions

Short, individually-linkable records of non-trivial decisions made *after* the
[`CLAUDE.md`](../../CLAUDE.md)/[`docs/history.md`](../history.md) split
(2026-08-22). Each is a single short file, not a paragraph appended to a
growing log — the point is that a later session can read the 5–10 lines
relevant to one decision instead of scanning a single ever-growing file.

Use this for something more substantial than a one-line change but not
foundational/hard-to-reverse enough for [`docs/adr/`](../adr/) (which is
reserved for the ~8 genuinely foundational architecture decisions). A rough
guide: if it changed behavior, closed a real gap, or reversed an earlier
choice, and a future session would benefit from knowing *why* without reading
the diff — it's a decisions entry. A pure bug fix with an obvious one-line
cause usually isn't; the commit message covers it.

## Format

One file per decision: `YYYY-MM-DD-short-topic.md`, structured as:

- **Problem** — what was missing or wrong, and why it mattered.
- **Decision** — what was actually built/changed.
- **Alternatives considered** — what else was on the table and why it lost,
  if anything genuinely was (skip this section if there was only one
  reasonable approach).
- **Verification** — how it was actually confirmed to work, not just that it
  compiles/passes existing tests.

Add a one-line index entry below when you add a file — newest first.

## Index

- [2026-08-25 — Show names instead of raw email/uuid, via a new plaintext displayName field](2026-08-25-plaintext-display-name.md) — a deliberate, narrow exception to "assume encrypted" (same sensitivity as the email already sitting next to it in every listing); bidi-override/zero-width characters stripped server-side since the value is echoed verbatim into the counterpart-picker.
- [2026-08-24 — Let users edit/delete their own comments, everywhere](2026-08-24-comment-edit-delete.md) — `addComment`-only became `edit`/`delete` too, allowed on archived anketas (matching `addComment`'s existing behavior), ownership enforced client-side only since the server never inspects the encrypted comments blob.
- [2026-08-22 — Catch npm lockfile drift in the pre-push hook, not just in CI](2026-08-22-npm-lockfile-drift-pre-push-check.md) — an incremental `npm install` left `frontend/package-lock.json` missing entries `npm ci` needed, breaking CI; `pre-push` now runs `npm ci`/`composer validate` when a manifest/lockfile changed.
