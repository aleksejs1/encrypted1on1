# An anketa is archived exactly once

## Problem

[GitHub issue #130](https://github.com/aleksejs1/encrypted1on1/issues/130): `POST /api/anketas/{id}/archive`
had no "already archived" guard. A second archive of the same anketa returned 200, overwrote
`archivedAt`/`missed`, and created a second successor. The pair then had two open anketas, each
recreating itself: the forked chain [#111](2026-09-23-one-open-anketa-chain-per-pair.md) set out to
prevent. A double click, a second tab, or both participants archiving at nearly the same time was
enough. The live-update poll only hides the Archive button on its next tick.

## Decision

**Server: the database decides who archives.** `AnketaController::archive()` returns 409 when the
loaded anketa is already archived, checked before the request's 400s, like every other mutating
anketa endpoint. That check alone can't stop two concurrent requests, because both load the row
while it is still open. So `AnketaLifecycleService::archive()` archives through
`AnketaRepository::markArchivedIfOpen()`, one conditional
`UPDATE … SET archivedAt, missed WHERE id = ? AND archivedAt IS NULL`. Only the request whose
UPDATE matched a row goes on to create the successor, in the same transaction. The other gets
`AnketaAlreadyArchivedException` and changes nothing. MySQL serializes the two UPDATEs with a row
lock, SQLite with its write lock plus `busy_timeout`. On SQLite (WAL) this relies on the UPDATE
being the transaction's first statement: a deferred transaction that has already read fails at
once with `SQLITE_BUSY` instead of waiting. The call site says so. After the UPDATE the service
`refresh()`es the entity rather than calling `Anketa::archive()`, so the in-memory entity matches
the row, and the unit of work doesn't write the same columns a second time. `Anketa::archive()`
now throws if the anketa is already archived, and is kept only for building test fixtures.

**The 409 carries what got applied.** The archive 409 body is `{error, archivedAt, missed}`, the
same idea as `updateAnswers()`'s conflict response returning the current blob. A request that lost
the race re-reads the row first. The page takes `missed` from it, so the result doesn't depend on
poll timing. It also only treats a 409 as "already archived" when `archivedAt` is present.

**Client: archived is one-way.** On a 409, `handleArchive()` moves the page to archived and shows
a notice (`anketa.alreadyArchivedElsewhere`): the anketa is archived either way, but this click's
choices (missed, skip/next meeting date) may not be the ones that got applied. That covers the
counterpart, another tab, or an earlier attempt of this one whose response was lost. The
live-state poll applies `archived` only from false to true, and `missed` only from a response that
has `archivedAt`. A server-side archive is now one-way and happens once. The demo reset deletes
and recreates anketas rather than un-archiving one. So a "not archived" response to a page that
knows otherwise is simply older. An example is a tick sent just before the archive committed that
lands after the 409; applying it would bring the Archive button back. `enterArchivedState()` is
the single transition used by the poll, both archive branches, and the answers-edit 409.

**Archiving and editing answers exclude each other.** Archiving while an unsaved edit of one's own
published answers is open would leave that edit unsaveable. So both archive buttons (the archive
section's, and the overdue card's "cancel as missed") are disabled while an answers edit is open,
each with a visible hint linked through `aria-describedby`. "Edit" is disabled while an archive
request is in flight.

## Alternatives considered

- **Only the in-memory 409 check** (the issue's stated minimum). It fixes the sequential double
  submit only. A throwaway Playwright script fired three simultaneous archive requests (employee,
  manager, employee) against the real e2e stack. With the conditional UPDATE disabled, the chain
  forked in the first round (two 200s). With it, all 8 rounds gave exactly one 200 and two 409s,
  and the stored `missed` always came from the winning request.
- **A Doctrine `#[ORM\Version]` column on `Anketa`.** It would serialize every write to the row,
  so both participants' unrelated draft/comment saves would conflict with each other.
- **A page-wide "local write" counter that drops a poll's scalars if any local write happened while
  it was in flight.** Built in review round 4, then dropped: every future local write has to
  remember to bump it, and it also discards genuinely new state (a reschedule racing a counterpart
  archive). One-way `archived` is correct by construction, now that the server guarantees it.
- **Handling an anketa switch while an archive is in flight** (`handleArchive()` snapshotting its
  inputs, per-anketa busy state). Built in review rounds 7–8, then reverted. The bug predates #130,
  and each partial fix opened a new hole. For example, snapshotting the outcomes at click time
  dropped outcomes added during key generation, which the original code carried forward. It needs
  its own design.

## Known limitations (deferred, all predating this change)

- The other mutating endpoints (publish, answers, draft, comments, outcomes, checkpoints,
  reschedule) still check `isArchived()` in memory and then write. So a write racing an archive can
  land on an anketa that was archived in between. The fix is the same kind of guarded write as
  here, but it touches every endpoint.
- Switching to another anketa in the same tab while an archive request is in flight (see above).
- A draft save still pending its 1s debounce when Archive is clicked fails afterwards with a 409.
  The last text typed isn't saved.
- Unsaved outcome, checkpoint or comment edits aren't gated the way an answers edit now is.
- Reschedule's own "already archived" 409 is shown as a plain error, rather than moving the page to
  archived.
- `load()` doesn't reset the archive form (`skipNextMeeting`, `nextMeetingDate`) on an anketa
  switch.
- A request that loses the race *and* is also missing periodicity or sealed keys gets that 400
  rather than the 409. Such a request is invalid either way.

## Verification

- PHPUnit:
  - Functional tests cover archiving twice and cancelling as missed twice. The second call gets
    409 carrying the first call's state, and exactly one successor exists.
  - The race path is simulated with a Doctrine `postLoad` listener that archives the row right
    after the controller loads it. With the controller's catch removed, this test gets a 500.
  - A repository test shows `markArchivedIfOpen()` succeeds once.
  - A unit test covers the losing request creating nothing, and another checks that the
    successor's default date uses the `archivedAt` that was written.
- e2e (`dual-actor-anketa.spec.ts`):
  - The counterpart cancels as missed while this tab's poll is held. This tab's Archive click gets
    409, shows the notice and the counterpart's `missed` from the 409 alone.
  - The held poll, answered afterwards with its stale pre-archive state, doesn't bring the Archive
    button back. Every later poll is held too, so a fresh one can't mask a regression.
  - The test fails against the old frontend and against two-way poll logic.
  - The existing mid-edit archive test asserts the Archive button is disabled while an answers
    edit is open.
- The concurrent-request script above, run against the real e2e stack, then deleted.
- 10 `code-review` rounds. The last found no correctness bugs; its remaining notes are listed
  above as deferred, or were declined as judgment calls (the `refresh()` round trip, no extra
  hint on the briefly disabled "Edit" button).
