# Post-merge review: multi-tenant SQLFilter, controller decomposition, DTO validation, JSON error handling, AccountDeleter service (GitHub issues #69–73)

## Problem

GitHub issues #69–73 were implemented and merged directly to `main` (PRs #74–78,
2026-09-13) without going through this repo's own mandated
implement→independent-review→fix→re-review loop (`CLAUDE.md`'s "Working style"
section). The five issues were substantial:

- **#69** — machine-enforced multi-tenant isolation via a Doctrine `SQLFilter`
  (`App\Doctrine\CompanyFilter`) and a denormalized `company_id` on `Anketa`.
- **#70** — decomposed the 901-line `AnketaController` into
  `AnketaRepository`/`GoalRepository`, `AnketaLifecycleService`, and
  `AnketaPresenter`.
- **#71** — replaced manual `$request->toArray()` parsing/validation with typed
  `#[MapRequestPayload]` DTOs (`src/Dto/`) and `symfony/validator` constraints.
- **#72** — `JsonExceptionListener` now formats *every* unhandled `/api/*`
  exception as JSON (previously only `HttpExceptionInterface`).
- **#73** — `AccountDeleter` became an injectable service instead of a static
  utility.

A retrospective review (`code-review` skill, high effort, multiple parallel
sub-agents plus an independent verification pass, over the full
`1bcc68e^..ee15c89` commit range — see `docs/history.md`) found several real
regressions this merge sequence introduced, none of which had been caught
before merging since no independent review ran.

## Decision

Fixed the confirmed, live/structural issues in place (same branch history,
no revert):

1. **CSRF/rate-limiter ordering (the significant one).** `#[MapRequestPayload]`
   is resolved — including the DTO's own validation — during Symfony's
   `kernel.controller_arguments` argument-resolution step, which runs *before*
   the controller method body. Every one of the ~30 mutating `/api/*`
   endpoints called `$this->csrfGuard->assertValid($request)` as the first
   line of its own method body, so after #71 that check (and whatever rate
   limiter the method consumed next) ran *after* the request payload had
   already been validated — a malformed-but-CSRF-token-less request now got
   rejected by DTO validation before CSRF was ever checked, silently
   bypassing both the CSRF gate and the rate limiter for that shape of
   request. Fixed by extracting the check into a new
   `App\EventListener\CsrfProtectionListener` on `KernelEvents::CONTROLLER`
   (fires before argument resolution), applied to every `/api/*` mutating
   route except `BillingController::webhook()` (Stripe signature
   verification instead — already the sole documented exception). Removed
   the now-redundant `CsrfGuard` constructor injection and inline
   `assertValid()` call from all 10 controllers that had it.
2. **Lost i18n in DTO validation messages.** Six DTOs
   (`CreateAnketaRequest`, `CreateGoalRequest`, `ArchiveAnketaRequest`,
   `RescheduleAnketaRequest`, `SetCompanySeatLimitRequest`,
   `UpdateGoalRequest`) built `#[Assert\Callback]` violations from hardcoded
   English literals instead of the app's own already-existing, already
   fully-translated `errors.*` keys (`messages.{en,de,fr,ru,lv,es}.yaml`) —
   those specific keys were left orphaned by the switch to DTOs. Fixed by
   pointing each `buildViolation()` call at its pre-existing `errors.*` key
   with `->setTranslationDomain('messages')` (Symfony's validator defaults
   new/`Assert\Callback` violations to the `validators` domain, not this
   app's own `messages` domain — the built-in constraints like `NotBlank`
   are unaffected, they keep using Symfony's own bundled `validators.*`
   translations). One check (`UpdateGoalRequest`'s title length cap) was
   genuinely new validation with no prior translated equivalent — added a
   new `errors.title_too_long` key across all 6 locale files rather than
   leave it English-only.
3. **`ArchiveAnketaRequest` strictness regressions.** `missed`/
   `skipNextMeeting` were non-nullable `bool`, so an explicit JSON `null`
   (the old manual-parsing code treated `null` the same as an absent key)
   now 400s instead of defaulting to `false` — made both `?bool`, coalesced
   to `false` at the one call site (`AnketaController::archive()`). Also,
   `nextMeetingDate` was date-format-validated even when
   `skipNextMeeting: true`, a field the archive flow never reads in that
   case — the old code's `if (!$skipNextMeeting && ...)` guard is now
   mirrored inside the DTO's own callback. (Verified against the actual
   frontend caller, `Anketa.svelte`'s `handleArchive()`: it never sends
   these problematic shapes today, so this wasn't a live bug — fixed anyway
   since a future/other client legitimately could.)
4. **`PlatformAdminController` never re-enabled the tenant filter.**
   `requirePlatformAdmin()` disables `CompanyFilter` for its action's
   deliberately cross-tenant queries, but nothing re-enabled it afterward —
   relying solely on `CompanyFilterListener` resetting it at the *next*
   request's `KernelEvents::REQUEST`. Went through two attempts (see
   "Alternatives considered" for the first two rejected ones): the fix that
   stuck is a request attribute
   (`CompanyFilterListener::RESTORE_FOR_COMPANY_ID_ATTRIBUTE`) set once by
   `requirePlatformAdmin()`, read by a new `CompanyFilterListener::onResponse()`
   on `KernelEvents::RESPONSE` that re-enables the filter if the attribute is
   present — fires exactly once per request regardless of which of an
   action's returns (or thrown exceptions, including `findCompany()`/
   `findUser()`'s `NotFoundHttpException`) actually produced the response,
   and touches no other request since only `requirePlatformAdmin()` ever sets
   the attribute. `enable()`+`setParameter()` now lives in one shared private
   `CompanyFilterListener::enableFor()`, used by both the normal per-request
   path and this restore path, rather than being duplicated.
5. **Two re-validation checks in `AnketaLifecycleService`** (`archive()`'s
   sealed-keys check, `createNextAnketa()`'s periodicity check) threw bare
   `\InvalidArgumentException`, which `JsonExceptionListener` has no special
   handling for — it would format as an opaque translated 500, not the
   intended 400. Unreachable today (`AnketaController` always pre-validates
   first), but a latent trap for any future caller. Changed both to
   `BadRequestHttpException`.
6. **`Anketa`'s constructor `?Company $company = null` parameter was
   provably redundant** — the constructor already throws unless it equals
   `$employee->getCompany()`, so every call site could only ever pass
   exactly that value or trigger the guard. Removed the parameter (and its
   pass-through in `AnketaLifecycleService::createWithCarryForward()`/
   `createAnketa()`); the entity now always derives `$this->company` from
   `$employee` directly. Removed the two unit tests that specifically
   exercised the now-impossible-to-reach "explicit company" paths.

A **second review round**, on this fix commit's own diff, caught that item 4's
first fix (a private `restoreCompanyFilter()` called before every `return` in
the 7 platform-admin actions) was itself buggy — `findCompany()`/`findUser()`
throwing `NotFoundHttpException` (a routine typo'd id, not an edge case) exited
before that call ever ran, leaving the filter disabled on every not-found path.
That review also flagged the fix as structurally the wrong shape for the same
reason the CSRF fix wasn't done per-call-site: a shared/cleanup concern spread
across every call site, one race away from the next person forgetting it at a
new return. Replaced with the request-attribute design described in item 4
above. The same round also caught: `CsrfProtectionListener` hand-rolled a
`SAFE_METHODS` list (`GET`/`HEAD`/`OPTIONS`) duplicating `Request::isMethodSafe()`
— switched to the built-in; and the 6 DTOs' `->setTranslationDomain('messages')`
chain was repeated at 11 call sites with no shared helper — extracted into
`App\Dto\DtoViolation::add()`, cutting each call site to one line.

## Alternatives considered

For the CSRF fix, patching each of the ~30 individual call sites to move
earlier was rejected — the actual constraint (Symfony resolves
`#[MapRequestPayload]` during argument resolution, unconditionally, before
any controller code runs) makes "earlier in the method body" structurally
impossible to fix per-call-site; only a `kernel.controller`-or-earlier
listener can run before argument resolution.

For the `PlatformAdminController` filter gap, two prior attempts were tried
and rejected before the request-attribute design: broadening
`CompanyFilterListener` itself onto `KernelEvents::FINISH_REQUEST`
unconditionally — reset the filter after every request, not just
platform-admin ones, breaking `CompanyFilterFunctionalTest`'s existing,
intentional assertion that the filter stays inspectable on the
request-serving `EntityManager` right after a normal request completes; and a
private `restoreCompanyFilter()` manually called before each action's own
`return` — missed every thrown-exception exit path (see above), the exact
one-race-at-a-time failure mode `CLAUDE.md`'s own working-style section warns
about for shared-state cleanup.

## Verification

Full backend suite green after all fixes, including both review rounds:
`composer test` (433 tests, 2799 assertions — up from 426/2789 after the
first round, from new/adjusted tests for the CSRF-ordering fix and the
platform-admin filter-restore-on-exception fix), `composer stan` (PHPStan max
level, `src/` and `tests/`, zero errors), `composer cs-fix` (zero changes
needed), `composer schema-validate`, and `composer md` (PhpMetrics allowlist
re-verified against a fresh run — see `bin/check-phpmetrics.php`'s updated
per-entry reasoning and `docs/architecture.md`). No frontend files were
touched by this pass.
