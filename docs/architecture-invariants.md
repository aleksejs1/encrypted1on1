# Architecture invariants

A short, load-bearing checklist of rules that hold across this codebase —
distinct from [`architecture.md`](architecture.md) (the narrative "how it's
built" overview) in that every rule here is either machine-enforced today or
is meant to be checked by hand on every review that touches the area it
covers. Written as part of `private/delivery-quality-improvement-proposal.md`
Phase 1, after the multi-tab unlock fix
(`docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md`) showed that a
prose-only invariant gets rediscovered by review one round at a time instead
of caught up front.

## 1. The E2EE confidentiality boundary

**Rule:** the server must never be able to derive plaintext anketa content
from what it stores or serves. The one deliberate, narrow exception is a
goal's title/description/status/target date (not its progress checkpoints)
— see CLAUDE.md's non-negotiable constraints. Nothing else gets that
exception without an explicit, discussed product decision.

**Enforcement, storage side:** `App\PHPStan\EnforceEncryptedEntityFieldsRule`
(`backend/src/PHPStan/`, wired into `composer stan`, covers both classic and
constructor-promoted properties). Every `#[ORM\Entity]` property backed by a
`string`/`text` column — the only column types that can hold arbitrary
content — must be named like ciphertext (`*Blob`, `*SealedKey`,
`encrypted*`), be the bare primary key (exactly `id`), or carry an explicit
`#[App\Entity\AllowPlaintext(reason: '...')]` attribute. A new
plaintext-shaped column with none of these fails `composer stan` — it can't
land silently. Deliberately *not* a broader identifier-shaped suffix rule
(`*Id`/`*Uuid`/`*Hash`): that would also silently exempt a genuinely
sensitive field like `nationalId` or `taxpayerId` just by name, which is
exactly the false confidence this rule exists to prevent — every real
identifier/hash column in this codebase (`authHash`, `tokenHash`,
`goalUuid`, `stripeCustomerId`, `stripeSubscriptionId`) carries its own
`#[AllowPlaintext(reason: ...)]` instead. This only covers entities; it says
nothing about whether existing plaintext exceptions are *justified*, only
that each one is a conscious, documented choice (the `reason:` string), not
an oversight — a reviewer still has to judge whether the reason holds (§4).

**Enforcement, API-exposure side:**
`backend/tests/Architecture/SerializationBoundaryTest.php` — a hand-maintained
reflection test asserting specific ciphertext-bearing properties (and
`authHash`/`encryptedPrivateKey`) carry no `#[Groups]` serialization
attribute, so they can never be exposed by an API Platform resource even if
one is added later. Update this test's data providers whenever a new
ciphertext-bearing property is added — `EnforceEncryptedEntityFieldsRule`
doesn't cover this half.

**What neither of these catches:** a *new controller* hand-rolling a
`JsonResponse` that embeds a ciphertext-bearing property's raw value
directly (bypassing the serializer entirely, so `#[Groups]` never enters the
picture). This is a real gap — no rule scans hand-written controller
response bodies for exactly which entity properties get pulled into them, and
building an AST rule sound enough not to false-positive-flag the many
legitimate places a controller reads a plaintext field or a ciphertext blob
for another documented purpose (delivering the blob for client-side
decryption) is a real research problem in itself, not a 1-day addition.
Reviewers checking a new endpoint that constructs its own response body must
manually confirm no ciphertext-bearing property is echoed back in the clear.

**`EnforceEncryptedEntityFieldsRule`'s own known, accepted blind spot:** it
only inspects the `#[ORM\Entity]` class's own AST node (classic properties
and constructor-promoted properties) — a column inherited from an
`#[ORM\MappedSuperclass]` parent is invisible to it, since that parent is a
different file/AST node the rule never walks to. Not built: no entity in
this codebase uses `MappedSuperclass` today, and walking cross-file
inheritance from a plain-AST PHPStan rule (as opposed to one backed by
`ReflectionProvider`) is real, non-trivial machinery to add speculatively
for a pattern that doesn't exist yet. Revisit if this codebase ever
introduces a mapped superclass. (The rule does correctly resolve a column's
type whether it's given as a `'string'`-style literal, a
`Types::STRING`-style class-constant reference, or omitted entirely and
inferred from the property's native PHP type — those three forms were
verified during Phase 1 review, not assumed.)

## 2. State space modeling for concurrent/async features

**Rule:** for any concurrent/async or multi-step-state feature (multiple
tabs, in-flight requests, session/cache invalidation), model the state as a
single enum/union over the actual reachable states, not independent booleans
that can combine into states nothing produces. Before broadening what a
shared invalidation/cleanup function does, grep every existing call site and
re-derive whether the new behavior is still correct for each one.

**Why this is here and not just in CLAUDE.md:** this is CLAUDE.md's own
working-style rule, restated here because it's the one Phase 1 was written
in direct response to — see
`docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md` for the
concrete incident (11 code-review rounds, the same "stuck" bug resurfacing
three times because the state was two booleans instead of one enum, plus a
broadened cleanup function breaking an unrelated admin-panel cache-bust two
rounds after the fact).

**Enforcement:** none mechanical — this is a design discipline, not
something an AST rule can check (a rule can't tell "two booleans that happen
to be independent" from "two booleans standing in for one 3-state value"
without understanding what they mean). Caught by the adversarial code-review
heuristics in §4 below and by the `code-review` skill loop CLAUDE.md already
mandates for non-trivial changes.

## 3. Tenant scoping (multi-tenant `Company` boundary)

**Rule:** every query for a tenant-scoped entity (`User`, `Anketa`, `Goal`,
`AnketaPrivateNote`) must be scoped to the requester's own `Company`, except in code that is
deliberately cross-tenant by design (`PlatformAdminController` and its
supporting services — the platform admin role exists specifically to operate
across companies).

**Mechanism:** automated at the ORM level via Doctrine `SQLFilter`
(`App\Doctrine\CompanyFilter`, GitHub issue #69). `Anketa` carries a
denormalized `company_id` foreign key relation alongside `User`,
`InviteRecord`, and `ActivationToken`, ensuring all primary tenant-scoped
entities carry a direct `company_id` column. `AnketaPrivateNote` (GitHub issue
#136) carries the same denormalized `company_id`, copied from its anketa. When a user is authenticated via
`AuthSession`, `CompanyFilterListener` (`KernelEvents::REQUEST`, priority 5)
configures and enables `company_filter` with `$currentUser->getCompany()->getId()`,
automatically appending `{$targetTableAlias}.company_id = '...'` to every
query targeting entities with a `company` association. When a request is
unauthenticated (or on logout), the filter is disabled. Manual query scoping
(`->andWhere('... = :company')` or same-company comparison) remains in place
at controller call sites as defense-in-depth.

**Deliberate cross-tenant exceptions:** in `PlatformAdminController` (which
guards all actions with `requirePlatformAdmin()`) and platform CLI commands
(`CleanupExpiredTokensCommand`, `GrantPlatformAdminCommand`, `SendRemindersCommand`),
`$entityManager->getFilters()->disable('company_filter')` is called explicitly
because cross-tenant operations are intentional by design.

**Enforcement:** machine-enforced via `CompanyFilter` across the ORM, plus
manual review heuristic in §4 as defense-in-depth.

## 4. Adversarial code-review heuristics

Beyond the general `code-review` skill loop, actively look for these
specific failure classes — each one a real bug class that has actually
occurred in this codebase's history, not a hypothetical:

- **Unhandled/floating promises** in frontend business logic — an `async`
  call whose rejection is never awaited or caught can resurrect stale state
  after logout/session death (see the multi-tab unlock incident, §2).
- **Multi-tab/multi-actor race conditions** — anything touching
  `sessionStorage`/tab-scoped state alongside a cross-tab session cookie.
- **Tenant-scope omission** — a new query against `User`/`Anketa`/`Goal`/
  `AnketaPrivateNote` with no visible company scoping and no `PlatformAdminController`-style
  justification for why it's intentionally cross-tenant (§3).
- **New plaintext-shaped entity column** — even though
  `EnforceEncryptedEntityFieldsRule` (§1) forces a conscious
  `#[AllowPlaintext(reason: ...)]` decision, a reviewer should still judge
  whether the *reason* actually holds, not just that one was supplied.
- **Migration dialect parity** — a schema change needs both the SQLite
  migration (`backend/migrations/`) and, if MySQL support is meant to stay
  real (see ADR 3), the matching `backend/migrations-mysql/` migration.
