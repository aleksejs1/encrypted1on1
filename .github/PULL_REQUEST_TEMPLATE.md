<!--
See CLAUDE.md's "Working style" section and .claude/skills/e1o1-agent-playbooks for the
full checklists this is a condensed version of — this template exists so the same
questions get asked before a PR is even opened, not just during review.
-->

## Summary

<!-- What changed and why. -->

## Delivery Quality Checklist

- [ ] **E2EE boundary**: Any new/changed `#[ORM\Entity]` column is either ciphertext-named (`*Blob`/`*SealedKey`/`encrypted*`), a bare identifier, or carries a real `#[AllowPlaintext(reason: ...)]` — enforced by `composer stan`'s `EnforceEncryptedEntityFieldsRule`, but worth deciding up front (`docs/architecture-invariants.md` §1).
- [ ] **State transitions**: If this touches auth/session/unlock state, does it stay inside the existing `unlockStatus` enum (`docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md`) rather than adding a new boolean flag, and is it covered in `frontend/src/auth.test.ts`?
- [ ] **Tenant scoping**: Any new query against `User`/`Anketa`/`Goal` is scoped to the requester's own company, or is deliberately cross-tenant and platform-admin-only (`docs/architecture-invariants.md` §3).
- [ ] **Database schema**: If entities changed, were both `backend/migrations/` (SQLite) and `backend/migrations-mysql/` updated — `php bin/console app:make-dual-migration --mysql-url=<...>` catches a missing MySQL side (`docs/deployment.md`'s "Using MySQL instead of SQLite")?
- [ ] **Caller audit**: If this broadens what a shared invalidation/cleanup function does, were all of its existing call sites re-checked against the new behavior, not just the one that prompted the change?
- [ ] **Verification**: Ran `make verify-pr-ready` locally (or the equivalent CI checks are green).
