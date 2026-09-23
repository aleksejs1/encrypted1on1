# A hand-created anketa next to an open one is a one-off

## Problem

[GitHub issue #111](https://github.com/aleksejs1/encrypted1on1/issues/111): nothing stopped a pair from
having two open (non-archived) anketas, and when it happened the pair's chain forked. Archiving an
anketa auto-creates the next one. If someone then created a second anketa for the same pair by hand
(any template), it got the same carry-forward from the last archived anketa: fresh `Goal` rows with
the same `goalUuid`, and the same unfinished outcomes. Those copies then drifted apart once edited.
Archiving the hand-created one with the form's defaults auto-created *its own* successor, leaving
two parallel chains, each recreating itself forever.

The meeting-templates work made this routine. For a pair that already meets, an ad-hoc template
(`'career_growth'`, `'onboarding'`) can *only* be created next to the auto-created regular anketa.
See [the career-growth decision](2026-09-23-career-growth-template-does-not-recur.md).

## Decision

A new persisted `Anketa::$oneOff` flag (`anketas.oneOff`, `DEFAULT 0`, migrations for both SQLite
and MySQL) is set once at creation and never changes. `AnketaController::create()` sets it when the
pair already has an open *chain* anketa, via `AnketaRepository::findOpenForPair()`. The pair is
matched as an unordered pair. Both repository lookups skip one-offs. `findOpenForPair()` skips
them so a pair whose regular chain ended can restart it while a one-off is still open. The
carry-forward source, `findMostRecentArchivedForPair()`, skips them so an archived one-off, even a
more recent one, never replaces the chain's own open goals and outcomes.

- **A one-off gets no carry-forward.** No goals are copied, and a client-sent `outcomesBlob` is
  dropped. `AnketaLifecycleService::createWithCarryForward()` enforces this itself, so no caller
  can bring the duplicates back. The already-open anketa has the carry-forward, and one copy is the point (maintainer
  decision on the issue's open question, 2026-09-23). Periodicity is inherited from the previous
  archived anketa, or failing that from the open one, so a pair whose first anketa is still open
  can't set a second, different periodicity. The server decides all of this.
  `CreateAnketa.svelte`'s anketa list could be stale by the time it submits.
- **A one-off never auto-recreates.** `AnketaLifecycleService::shouldCreateNext()` returns false
  for it, forced the same way as a blocked participant. The overdue card's "cancel as missed" goes
  through the same `archive()`, so it's covered too. An auto-created successor is never a one-off,
  so the regular chain carries on untouched.
- **UI:** `oneOff` is in the list and detail payloads. Like `templateKey`, it's left out of the
  live-state poll, since it never changes. For a one-off, the
  archive form shows a short explanation instead of the "don't create the next meeting" checkbox
  and date, and sends an explicit skip without generating a next key. `CreateAnketa.svelte` shows a one-off note
  and skips the outcomes re-encryption. It asks the periodicity question only when the server has
  nothing to inherit, matching `create()`'s fallback chain. So a legacy anketa from before
  periodicity existed (`periodicityDays` NULL) still gets asked, rather than hitting the
  "periodicity required" 400 with no way to answer it. It mirrors the
  server's one-off exclusion using `oneOff` from the list summary. If its list is stale, and the
  open anketa got archived before submit, the server treats the new anketa as a chain anketa and
  carries goals from the just-archived one but receives no outcomes. Computing outcomes from the
  client's `previousAnketa` wouldn't help, because that would be an *older* anketa, and completed
  outcomes would come back. This is a narrow race, accepted.

Pre-existing anketas all default to `oneOff = false`, so pairs that had already forked before this
change keep both chains until someone ticks "don't create the next meeting" on one of them.

## Alternatives considered

- **The issue's proposed dynamic rule** ("`archive()` creates no successor while the pair has
  another open anketa"): built first, then replaced after independent review found two problems.
  First, archiving the regular anketa *before* the ad-hoc one handed the chain to the empty ad-hoc
  anketa, silently dropping the regular chain's carried goals and outcomes. Second, two concurrent
  archives of a pair's two open anketas each saw the other as open, so the chain ended with no open
  anketa. It also needed the check at archive time in both the controller and the service, and a
  "no next meeting" UI hint that could go stale. A flag fixed at creation has none of these
  problems. Switching to it was a maintainer decision, 2026-09-23.
- **Block a second open anketa outright (409):** this would make ad-hoc templates impossible for an
  established pair, which is exactly what #105 wanted to allow.
- **Keep carrying forward into the second anketa:** this keeps the duplicated, diverging goals and
  outcomes the issue describes.
- **Default the archive form's "skip next meeting" on per template:** already tried and reverted
  during #105 (see the career-growth decision). It keys off the wrong signal.

## Known limitations (accepted)

`create()` reads, then inserts, with no lock. A create racing another create for the same pair,
or racing an archive that's auto-creating the successor, can come out as a second chain anketa.
Two concurrent creates already duplicated carry-forward before this change, so this isn't new.
Closing it needs a lock or a DB constraint over an unordered pair, which was judged out of
proportion to a same-moment race. The reverse also happens: if `CreateAnketa.svelte`'s list is
stale and misses a newly opened chain anketa, the form shows the normal copy, and the server
creates a one-off anyway. The anketa's archive form then explains it.

Goals and outcomes created *inside* a one-off never reach the pair's chain, because the next
regular cycle carries forward only from the regular anketa it follows. Ticking "don't create the
next meeting" already had this effect, so it isn't new, but this makes it the default path for an
ad-hoc anketa. Server-side goal merging into the other open anketa is possible (goals are
plaintext) but was left out of scope. Outcomes would stay behind regardless, since they're
encrypted under a different anketa key (maintainer decision, 2026-09-23). A side effect: a goal
left `in_progress` in an archived one-off keeps its last snapshot `in_progress` for good, so the
company-admin Goals/Overview reports (`GoalMetrics`) keep counting it, eventually as overdue.

A pair whose auto-created chain anketa is left open and overdue, while the pair meets through a
hand-created anketa instead, gets that anketa as a one-off. `CreateAnketa.svelte`'s note says so.
The fix is to archive or cancel the stale one *before* creating the new one. A one-off stays a
one-off even if it later becomes the pair's only open anketa, for example once the chain anketa
is archived with "don't create the next meeting". Archiving it then ends the pair's cadence, and
the next hand-created anketa restarts the chain. That's the cost of fixing the flag at creation
rather than re-deriving it at archive time (see the alternatives). Pairs that forked before this change keep
both chains, as noted above; no data migration heals them.

## Verification

- `AnketaControllerTest::testAHandCreatedAnketaNextToAnOpenOneIsAOneOff` runs the issue's full
  scenario. It archives, which auto-creates a non-one-off successor with the carried goal, then
  creates a `career_growth` anketa by hand with a client-sent `outcomesBlob` and periodicity. The
  test checks that the new anketa is a one-off, with no goals or outcomes and the inherited
  periodicity. Archiving it with the form's defaults (keys sent) creates no successor.
- `::testArchivingTheRegularAnketaWhileAOneOffIsOpenStillContinuesTheChain` covers the review
  finding above.
- `::testASecondAnketaForANewPairIsAOneOffWithRolesSwapped` covers the unordered-pair match,
  inherited periodicity when the pair's first anketa is still open, and that another pair's open
  anketa doesn't count.
- `::testAPairWhoseChainEndedCanRestartItWhileAOneOffIsOpen` and
  `::testCarryForwardSkipsAnArchivedOneOff` cover the second review round's findings: an open or
  archived one-off is not treated as a chain anketa.
- `AnketaRepositoryTest::testFindOpenForPairIgnoresOneOffs` and
  `::testFindMostRecentArchivedForPairIgnoresOneOffs` check the same at the query level, including
  reversed pair order.
- `AnketaLifecycleServiceTest::testArchiveOfAOneOffAnketaDoesNotCreateNext`,
  `::testShouldCreateNextIsFalseForAOneOffAnketa`, `::testCreateWithCarryForwardPassesOneOffThrough`
  and `::testCreateWithCarryForwardIgnoresCarryForwardForAOneOff`
  cover the service rule.
- Both migrations were generated with `doctrine:migrations:diff` (the MySQL one against a real,
  throwaway MySQL 8.4 container) and trimmed to the `oneOff` column. Each was run up, down and up
  again. The MySQL one was run against a populated `anketas` table, and the existing row backfilled
  to `0`.
- A throwaway Playwright spec against the real e2e stack, with real browser crypto, deleted
  afterwards. It archives an anketa, which auto-creates the next one, and hand-creates a
  `career_growth` anketa next to it, checking that the one-off note shows. It then archives the
  one-off: the archive form shows the explanation instead of the next-meeting controls, no error,
  and the pair is left with exactly the auto-created anketa open. That anketa still shows the
  normal controls.
- `frontend/src/anketa/pairChain.test.ts` covers the client-side mirror of `create()`'s lookups
  (`pairChainState()`, used by `CreateAnketa.svelte`): skipping one-offs, choosing the earliest
  open chain anketa like the server does, and the periodicity fallback, including legacy NULL rows.
- `docs/encryption.md`'s threat-model "Visible" list now names the new plaintext `oneOff`
  classifier. It also names `templateKey`, which the templates work had left out of that list.
- Unrelated to the fix itself, but in the paragraph this change extends: `docs/user-flow.md`
  said "cancel as missed" archives *without* auto-creating a next anketa. The code
  (`handleArchive()`, `Anketa::$missed`'s docblock) has always auto-created one unless "don't
  create the next meeting" is checked, so the doc was corrected to match.
- Nine independent `code-review` rounds. The first led to replacing the dynamic rule. The second
  led to the one-off exclusions above. The third corrected an outcome-carry change from the
  second, separated "one-off anketa" from "non-recurring template" in the wording, and produced
  the limitations above. The fourth led to the periodicity-question fix and the threat-model
  update. The fifth led to extracting `pairChainState()` with tests, and to the one-off wording
  above. The sixth found no crash or data-loss bugs. It led to the UI copy saying up front that a
  one-off's own goals and outcomes don't carry into the pair's regular meetings. The seventh moved
  the no-carry rule into the service and added the repository test for the archived-one-off
  exclusion. The eighth added a creation-order tie-break to the "earliest open" choice on both
  server and client, the matching tests, and `AnketaPresenterTest` coverage for `oneOff` at the
  same depth as `templateKey`. The ninth found no bugs beyond the same tie-break missing from the
  previous-archived lookup, now added on both sides. The rest of the notes from rounds six to nine were already
  accepted above, or were judgment calls.
