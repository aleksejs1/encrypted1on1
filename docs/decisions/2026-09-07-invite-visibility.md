# Admin visibility into sent invites (status, sender, expiry)

## Problem

People often don't respond to registration invites, and invites expire (24h
TTL). Neither a company admin nor a platform admin had any way to see how
many invites were sent, which were pending/accepted/expired, or who sent
each one — see [GitHub issue #24](https://github.com/aleksejs1/encrypted1on1/issues/24)
for the original request and the product/technical review that shaped this.
Worse: the underlying `ActivationToken` rows are hard-deleted daily by
`app:cleanup-expired-tokens`, used or not, so this data didn't persist long
enough to be queried even directly against the database.

## Decision

Added a new entity, `InviteRecord`, written alongside the `ActivationToken`
at issue time and sharing its id (so `ActivationController::complete()` can
stamp `acceptedAt` on the matching row without a separate lookup table).
Deliberately **not** merged into `ActivationToken` itself or given the same
24h retention — that table's cleanup is a security-driven token-minimization
concern, unrelated to how long admin-facing invite history should stay
readable. `InviteRecord::RETENTION_DAYS` (90, not yet configurable) is its
own, independent retention window, pruned by a new branch in
`CleanupExpiredTokensCommand`.

New endpoints: `GET /api/admin/invites` (company-scoped, reuses
`RequiresCompanyAdmin`) and `GET /api/platform-admin/invites`
(cross-company, includes `companyName`). New frontend tab ("Invites",
`AdminInvites.svelte`) alongside Users/Reports in `AdminPanel.svelte`, and a
matching section in `PlatformAdminPanel.svelte`.

Four product/technical questions the original issue left open, resolved here:

1. **Scope — admin-sent invites and open self-registration, not CLI
   bootstrap or cloud company-creation.** `SignupController::signup()`
   (`domain` mode) now also writes an `InviteRecord` with `invitedBy = null`
   (rendered as "Self-registered") — it's the identical expiring-link
   problem this issue exists to fix, just without a human sender. The CLI
   bootstrap (`CreateActivationLinkCommand`) and cloud self-service company
   creation (`CompanyController`) deliberately do not write one: both are
   one-time bootstrap actions before any company-side invite pipeline
   exists, not part of the ongoing "did my invite land" workflow.
2. **Retention: 90 days**, a proposed default with no company-level
   override yet. This is new PII retention (invited, never-onboarded
   people's real email addresses) that didn't exist anywhere else in this
   product's design before — worth revisiting if it ever needs a Privacy
   Policy line (the Sentry sub-processor disclosure is the precedent for
   what that looks like).
3. **Platform admins can see who personally invited whom at another
   company** — a new, narrow addition to `PlatformAdminController`'s
   existing cross-tenant visibility (account/company facts only, until now).
4. **`ActivationController::complete()` always attempts an `InviteRecord`
   lookup** by the completing token's shared id, treating "not found" (CLI
   bootstrap, cloud company-creation) as the expected common case rather
   than gating the lookup on `registrationMode`. Simpler, and the lookup
   itself is a single indexed `find()`.

Two correctness details worth recording because they're easy to get wrong
if this is ever touched again:

- **`AccountDeleter` scrubs `InviteRecord.email` on account deletion**
  (self-service or admin-triggered), matching `User::delete()`'s own
  in-place anonymization — without this, a deleted user's real address
  would keep sitting in this new table for the rest of its retention
  window. The scrub is scoped to **the deleted user's own company** (unlike
  `users.email`, `invite_records.email` carries no cross-company uniqueness
  constraint, so an unrelated invite at a different company can coincidentally
  share the same address) and **excludes still-pending rows** (a duplicate
  re-invite to the same address, issued before the first is accepted, has a
  genuinely still-completable `ActivationToken` — scrubbing its
  `InviteRecord` would corrupt a live, independent invite). Only rows that
  are already `accepted` or already `expired` are scrubbed.
- **Both list endpoints fetch-join `invitedBy` (and, for the platform-admin
  one, `company`)** rather than lazy-loading per row — the same "batch, not
  per-row" discipline `AnketaController::bulk()` and
  `PlatformAdminController::listCompanies()`'s own batched user-count query
  already established.

## Alternatives considered

- **Extend `ActivationToken` directly** (add `invitedBy`, drop its 24h
  cleanup) instead of a new entity — simpler in file count, but couples a
  security-driven, short-lived table to a longer-lived, admin-facing
  reporting concern with a different retention need. Rejected: the two
  concerns would end up sharing one cleanup job and one retention decision
  for no real benefit, and a future change to one would risk silently
  affecting the other.
- **Excluding self-registration entirely** (admin-sent invites only) — the
  original draft's first pass. Rejected after an adversarial review pass
  pointed out this excludes exactly the companies most likely to have the
  reported problem (domain-mode self-registration goes through the same
  expiring-link flow).

## Verification

Backend: `InviteRecordTest` (unit, status derivation/scrub), functional
tests on both list endpoints (401/403/company isolation/cross-company
visibility), `ActivationControllerTest` (acceptance stamping, and the
"no matching record" no-op case), `AuthControllerTest` (the account-deletion
scrub, both the same-company-still-pending-survives and
cross-company-untouched cases), `CleanupExpiredTokensCommandTest` (retention
pruning). Full backend suite (343 tests) and lint (PHPStan, PHP-CS-Fixer,
PhpMetrics, Doctrine mapping) green; migrations verified against both SQLite
(the dev container) and a real throwaway MySQL 8.4 container via
`app:make-dual-migration`.

Manually verified end-to-end against the real dev stack per the
`e1o1-verify-with-real-crypto` skill: a real admin account (real activation
crypto in a real Chromium tab via Playwright) sent a real invite through the
existing "Invite a colleague" form, and the new Invites tab correctly showed
it as pending with sender/dates; a real domain-mode self-registration
against the isolated e2e stack confirmed `invitedBy` is correctly null for
that path (unreachable in PHPUnit — same documented gap
`SignupControllerTest`'s own docblock already carries for this mode); the
platform-admin invites section was confirmed with a real cross-company
screenshot. All throwaway accounts/companies/invites created during
verification were cleaned up afterward.
