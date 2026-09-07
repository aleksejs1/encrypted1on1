# Allow editing published anketa answers before archival

## Status

**Implemented.** Both open questions below were resolved by the maintainer:
no counterpart notification or visible "edited" signal of any kind, and no
restriction on *when* an edit can happen (including after the counterpart
has already read or commented on the answer).

## Problem

Users have asked for the ability to fix their own anketa answers after
publishing, as long as the anketa hasn't been archived yet (e.g. correcting a
typo or a checkbox picked in error). Today, `publish()` is a deliberate
one-way transition: `AnketaController::publish()` (and `saveDraft()`) both
409 once `Anketa::isPublished($user)` is true, and
[`docs/user-flow.md`](../user-flow.md) states outright: *"Publishing is
one-way... There's no draft-recall after that point — the app treats
'published' as a real commitment, not a checkpoint you can walk back."* This
is a considered decision, not an oversight — `docs/history.md` records it as
a phase-scoped choice ("one-way this phase"), and re-opening it should be
equally deliberate.

The end-to-end encryption model turns out not to be the obstacle: the
anketa's shared content key is `crypto_box_seal`-ed to *both* participants'
public keys at **creation** time (`Anketa` constructor,
`employeeSealedKey`/`managerSealedKey`), not at publish time. Publishing
today is a re-*encryption* event (own-master-key → shared anketa-key), not a
key-redistribution event. Both parties already hold the shared key by the
time either side publishes, so a same-key re-encrypt-and-resave for an
already-published answer needs no resealing and no new key material —
`encryptBlob`/`decryptBlob` (`frontend/src/crypto/anketaKey.ts`) are already
symmetric and reusable as-is.

What's actually missing is the scaffolding `publish()` was built without,
specifically *because* it was designed one-way:

1. No optimistic-concurrency version field on `employeeBlob`/`managerBlob`
   (unlike `commentsVersion`/`outcomesVersion`/`goalCheckpointsVersion`,
   which already use the version-counter + retry-on-409 pattern in
   `frontend/src/anketa/blobSync.ts`).
2. No guard preventing an edit from racing an in-flight `archive()` — goals
   and goal-checkpoints already 409 on `isArchived()`; an answers-edit
   endpoint needs the same treatment.
3. No signal to the counterpart that content changed after they read it.
   There's no "edited at" timestamp, no diff, and — notably — no
   notification even on the *original* publish today (`AnketaNotifier`'s
   three methods only fire on anketa creation and two meeting reminders —
   `notifyMeetingTomorrow`/`notifyNotFilledOut` — never on publish).
4. Comments are anchored to a specific answer via `Comment.targetId`
   (`frontend/src/anketa/comments.ts`) and rendered once `myPublished` is
   true. If the answer text an existing comment refers to changes after the
   comment was posted, the thread becomes stale relative to the new
   content — nothing today versions "which answer text a comment was
   actually responding to."

None of this is a cryptography problem; all of it is a workflow/trust
problem the one-way design deliberately avoided by not existing.

## Decision

Allow a user to re-save their own already-published answers, encrypted with
the same anketa key, as long as the anketa is not yet archived. Concretely:

- Add a version counter to the published answer blobs (mirroring
  `commentsVersion`), and a new versioned save method/endpoint — **not** a
  relaxed `publish()`. `Anketa::publish()` unconditionally sets
  `employee`/`managerPublishedAt` to now; reusing it for edits would bump
  that timestamp on every edit, which both contradicts "no edited signal"
  below (the timestamp *is* a signal) and corrupts a fact the
  already-shipped Overview report is built on (`OverviewAggregator.php`
  counts a side as responded purely by `*PublishedAt` being non-null —
  see `private/company-admin-reporting-proposal.md` line 31, "did this
  side fill in their form"). Cadence (Phase 3) is *not* affected — its
  query only reads `employee`/`manager`/`meetingDate`/`archivedAt`
  (`company-admin-reporting-proposal.md:184-190`), no `publishedAt` at
  all — but Overview alone is reason enough. The new method must touch
  only the blob, never `*PublishedAt`. It must also never fall back to
  `saveDraft()`'s master-key-encrypted path — the entity docblock's
  invariant ("Master-key-encrypted (draft) or anketa-key-encrypted
  (published) — the server can't tell which; only `publishedAt`
  distinguishes them," `Anketa.php:76-80`) requires that once a side is
  published, its blob is *always* anketa-key-encrypted, edit or not.
  409s on version mismatch (reusing `blobSync.ts`'s retry pattern
  client-side) and 409s if `isArchived()`.
- `Anketa::resetForDemo()` (`Anketa.php:426-453`) already takes explicit
  `commentsVersion`/`outcomesVersion`/`goalCheckpointsVersion` parameters
  to bypass the normal version-guarded mutators for the scheduled demo
  reset, wired from a fixture file via `ResetDemoDataCommand.php:93-103`.
  Adding a version field to `employeeBlob`/`managerBlob` needs the same
  treatment: a new parameter on `resetForDemo()` and a matching field in
  the demo fixture format, or the demo reset will leave that column at a
  stale version and desync from the blob it's paired with.
- No interaction with the password-reset key-reshare path
  (`Anketa::resealKeyFor()` / `AnketaController::reshareKey()`): editing
  needs the same already-distributed anketa key that viewing already
  needs, so a stale sealed key pending re-share blocks editing exactly the
  way it already blocks viewing today. Not a new failure mode.
- No "edited at" timestamp, no diff, no badge, and no notification of any
  kind — the counterpart has no way to tell a published answer changed
  after they first saw it, by explicit maintainer decision. `AnketaNotifier`
  is untouched by this feature.
- No restriction on when an edit can happen: an already-published answer
  stays editable right up until `archive()`, with no time window and no
  "locked once the counterpart has responded" rule. The only guard is
  `isArchived()`.
- Frontend: turn `AnswerField`'s `readonly={myPublished}` gate in
  `Anketa.svelte` into an explicit edit mode (not published ⇒ always
  editable, as today) rather than removing the lock outright, and reuse
  `handlePublish()`'s `encryptBlob(answers, anketaKey)` path for the save.
- Comment staleness is an accepted, permanent consequence of the above, not
  a v1-only gap: a comment can end up anchored (via `Comment.targetId`) to
  answer text that no longer matches what's displayed, with nothing in the
  UI to flag it. Not solving this was a direct implication of "no visible
  edited signal," not a separate deferred decision.
- Update `docs/user-flow.md`'s "publishing is one-way" language to reflect
  the new rule ("editable until archived, silently," not "locked forever").

## Alternatives considered

- **Unpublish back to draft, then re-publish.** Reintroduces a third
  visible state (published → draft → published) and would need its own
  guard against unpublishing after the counterpart has already
  commented — more UI/state complexity than the problem warrants for what
  is, per the request, a "fix a mistake" use case, not a "reconsider my
  answer" workflow. Rejected.
- **Time-boxed edit window (e.g. 24h after publish).** Adds a real
  requirement (expiring editability) with no evidence it's needed yet.
  Rejected for now as speculative; straightforward to layer on top of the
  version-field approach later if abuse becomes a real problem.
- **New anketa / full answer version history instead of overwrite-in-place.**
  Would give a real audit trail but is a materially bigger feature
  (snapshot storage, a history UI) for a narrow request. Rejected as
  overkill given the maintainer's "no notification, no signal" decision
  above leaves no product need for history — revisit only if that stance
  changes.

## Consequences

- Reverses the explicit "one-way, no walk-back" statement in
  `docs/user-flow.md` — that document needs a corresponding update once this
  ships.
- No cryptographic or key-distribution changes: same anketa key, same two
  parties who already had access. The E2E confidentiality boundary
  (`docs/architecture-invariants.md` §1) is unaffected.
- Adds one more place (`employeeBlob`/`managerBlob`) that needs the
  version-counter + 409-retry treatment already established for
  comments/outcomes/goal-checkpoints — mechanical, low-risk, consistent with
  existing patterns.
- Leaves a real, documented gap (comment staleness, no change-notification)
  that a future request could reasonably come back to; not addressing it now
  is a scope choice, not an omission to be quiet about.

## Verification

**Backend:** `Anketa::updateAnswers()` unit-tested for success + version
increment, version-mismatch leaving state unchanged, and touching only the
caller's own side (`AnketaTest.php`); `AnketaController::updateAnswers()`
functionally tested for success without touching `*PublishedAt`,
409-before-first-publish, 409-once-archived, and a version conflict
returning the current blob/version (`AnketaControllerTest.php`, 8 new
tests). New SQLite migration (`Version20260907034801`) generated via
`doctrine:migrations:diff` against the real dev container, applied, and
confirmed to leave no *new* schema drift beyond the same pre-existing
cosmetic mismatch already documented in the anketa-form-versioning
decision. Matching hand-written MySQL migration
(`migrations-mysql/Version20260907034936`, explicit `DEFAULT 0` rather than
an auto-generated no-default `ADD COLUMN`, per the MySQL silent-backfill
lesson from the cloud-service migration work) verified in both directions
against a real, throwaway MySQL 8.4 container. `composer test` (324
tests), `composer stan` (both configs), `composer cs`, and `composer md`
all clean.

**Frontend:** `svelte-check`/`tsc`, `eslint`, `prettier`, and `knip` all
clean; the existing 181 Vitest unit tests are unaffected (this feature has
no dedicated page-level unit tests — `Anketa.svelte` is verified through
Playwright, matching how the rest of that page's behavior is tested).
`frontend/e2e/dual-actor-anketa.spec.ts` was extended with a real scenario,
run against the actual e2e Docker stack: the employee publishes, edits
their published answer through the real Edit/Save UI (genuine WASM crypto
re-encrypting the blob under the same anketa key, not a direct API call),
and a separate manager session reloads and sees the *edited* content, not
the original marker — confirming the edit genuinely round-tripped through
the server rather than only updating local state. Full e2e suite (5 tests)
green; one unrelated pre-existing activation-navigation timing flake in
`password-reset.spec.ts` was hit once and confirmed passing on retry,
unrelated to and untouched by this change.

**A correction found only by actually implementing this, not by review:**
an earlier review round of this ADR flagged `Anketa::resetForDemo()` as
needing a new parameter for the answer-blob versions, reasoning by analogy
to `commentsVersion`/`outcomesVersion`/`goalCheckpointsVersion`, which are
explicit `resetForDemo()` parameters. Implementing it exposed why that
analogy doesn't hold: `ResetDemoDataCommand` deletes and reconstructs every
demo anketa from scratch on each reset (`resetForDemo()` is never called on
a surviving row), so a freshly-constructed `Anketa`'s
`employeeBlobVersion`/`managerBlobVersion` already default to `0` — exactly
the value a never-edited demo answer should have. No `resetForDemo()` or
fixture change was made. Left as a documented correction rather than
silently dropped, since the earlier finding was reported with confidence.
