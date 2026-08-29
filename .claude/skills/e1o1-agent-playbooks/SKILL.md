---
name: e1o1-agent-playbooks
description: Three short checklists for working on encrypted1on1 — pre-flight planning before writing code, a pre-review self-check before calling a change done, and adversarial code-review heuristics for reviewing someone else's (or your own past) change. Use before planning a non-trivial change, before opening/requesting review on one, or when performing a code-review pass yourself.
---

# Agent playbooks

Three checklists, one per moment in a change's life. Written as part of
`private/delivery-quality-improvement-proposal.md` Phase 1 — the goal is to
front-load the questions that `docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md`
shows getting discovered reactively, one `code-review` round at a time,
instead of up front. None of this replaces `docs/architecture-invariants.md`
(the actual rules) or CLAUDE.md's working-style section (the standing
process) — it's the checklist form of both, meant to be worked through
quickly, not re-read in full each time.

## 1. Pre-flight planning checklist

Before writing code for a non-trivial change:

- [ ] **Touches concurrent/async or multi-step state** (multiple tabs,
      in-flight requests, session/cache invalidation)? Model the full state
      space as a single enum/union first — see
      `docs/architecture-invariants.md` §2. Write down the reachable states
      and the transitions between them before writing the implementation.
- [ ] **Broadens what a shared invalidation/cleanup function does?** Grep
      every existing call site now, not after review finds the regression.
      List each call site and what the new behavior means for it.
- [ ] **Adds or changes an `#[ORM\Entity]` column?** Decide up front whether
      it's ciphertext (name it `*Blob`/`*SealedKey`/`encrypted*`), a bare
      identifier, or a deliberate plaintext exception (add
      `#[AllowPlaintext(reason: '...')]` with a real reason) — see
      `docs/architecture-invariants.md` §1. `composer stan` enforces this
      but deciding it during planning is cheaper than discovering it there.
- [ ] **Queries `User`/`Anketa`/`Goal`?** Confirm the query is scoped to the
      requester's company, or that it's deliberately cross-tenant
      (platform-admin-only) — see `docs/architecture-invariants.md` §3.
- [ ] **Changes an entity's schema?** Plan both the SQLite migration
      (`backend/migrations/`) and, if the change should stay MySQL-real, the
      matching `backend/migrations-mysql/` migration (ADR 3) — generate the
      MySQL one against a real MySQL database, not by hand-editing SQL.

## 2. Pre-review self-checklist

Before calling a change done and requesting the `code-review` skill loop:

- [ ] Ran the real verification standard for anything touching anketa
      keys/sealing/password-derived keys — see the `e1o1-verify-with-real-crypto`
      skill, not opaque placeholder strings.
- [ ] Ran `make test && make lint` (or the isolated variants) locally and
      they're clean — the same checks CI and `pre-push` run.
- [ ] Updated `docs/architecture-invariants.md`/CLAUDE.md/an ADR or decision
      record if this change establishes or revises a load-bearing rule, not
      just a decision that stays implicit in the diff.
- [ ] For a new mutation/query function: does it have the same
      success/other-items-untouched/wrong-author-throws/wrong-id-throws
      coverage its sibling functions already have?

## 3. Adversarial code-review heuristics

When performing a `code-review` pass (on someone else's change, or your own
after stepping away from it), actively search for these — see
`docs/architecture-invariants.md` §4 for the full list and the real incidents
behind each one:

- Unhandled/floating promises in frontend business logic.
- Multi-tab/multi-actor race conditions around `sessionStorage` + the
  cross-tab session cookie.
- A new query against `User`/`Anketa`/`Goal` with no visible tenant scoping
  and no platform-admin-style justification.
- A new `#[AllowPlaintext(reason: ...)]` whose reason doesn't actually hold
  up — the PHPStan rule only forces a decision to be made, not that it's a
  *good* one.
- A schema change with a SQLite migration but no matching MySQL one (or vice
  versa).
