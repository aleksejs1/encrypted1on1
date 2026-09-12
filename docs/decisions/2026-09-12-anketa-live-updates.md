# Live updates on the anketa page during a meeting

## Status

Implemented.

## Problem

Two participants often have the same anketa open during a live meeting and add
comments/edit answers as they talk, but neither side saw the other's changes without
a manual page reload — `Anketa.svelte` had no polling or push mechanism at all.
See [GitHub issue #59](https://github.com/aleksejs1/encrypted1on1/issues/59).

## Decision

Short-interval polling (4s, paused via the Page Visibility API while the tab is
hidden) of a new, cheap `GET /api/anketas/{id}/live-state` endpoint — scalars and the
five existing blob version counters only, no blobs, no goals query. On any diff
against the last-applied values, the frontend does one full `GET /api/anketas/{id}`
and applies whichever sections actually changed: comments, outcomes, goal
checkpoints, the viewer's own post-publish answer edits, and the counterpart's
answers/publish state.

**SSE, Mercure, and WebSocket were all considered and rejected** in favor of plain
polling — see `private/live-updates-proposal.md` (not tracked in git; the full
design doc, independently reviewed twice before this issue was filed) for the
detailed reasoning. In short: this app has no long-lived-connection precedent
anywhere (every endpoint is stateless request/response), a 1:1 meeting tolerates a
few seconds of latency comfortably (this isn't collaborative per-keystroke editing),
and plain polling needs zero new dependencies, Caddy config, or CSP directives —
consistent with [ADR 5](../adr/0005-no-message-queue.md) (no message
queue/background worker) and [ADR 6](../adr/0006-single-frankenphp-container.md)
(no new moving parts).

**Goals are explicitly out of scope** — `Goal` rows have no version counter, and
goal editing has no isolated "editing" boundary the way `editingMyAnswers` gives
post-publish answer edits (title/description/target-date are permanently-editable
inline inputs with a manual Save button, no edit-mode toggle). Live-refreshing them
could silently discard an unsaved, actively-typed edit — this was caught by
independent review during proposal drafting (a round-1 draft did include a new
`goalsVersion` column; both a product review and a technical review independently
flagged it as scope creep and a real correctness gap respectively, and it was
dropped before implementation started). A real follow-up once goal editing gets its
own edit-mode boundary, not an oversight.

**New comments get a brief visual highlight; everything else stays silent** — a
maintainer decision made during proposal review. Unlike the async
editable-published-answers precedent (silent by design, no urgency), a comment
landing silently during a live, spoken conversation could go unnoticed for the rest
of the meeting if the reader isn't looking at that exact part of the page.

## State-space model

Every shared list/blob (comments, outcomes, goal checkpoints, the viewer's own
answers) is applied wholesale-or-skipped — never merged — gated on "does the user
have a local draft/in-flight action open for this section," reusing/extending each
section's own existing busy signal (`editingMyAnswers`, outcomes'
`anotherOutcomeActionOpen`, a new `commentThreadsBusy` lifted from each
`CommentThread` instance via the same `bind:`/`$effect` pattern `AnswerField`
already uses for `hasOpenEntryEdit`). A skipped section simply shows up as "changed"
again on the next tick — self-healing, never a lost or silently-discarded update.
Scalar/banner fields (archived, missed, meeting date, publish state) always apply
immediately, since nothing on this page edits them inline.

Two invariants, established for other reasons before this feature existed, needed to
be extended to a proactively-updating world rather than a purely reactive one:

- **"Editing is never offered once archived"** (already stated for the post-publish
  case in `editingMyAnswers`'s own state-model docblock) now also has to hold when
  `archived` flips true via the live poll mid-session, not just when discovered
  reactively via a 409. A shared `exitAnswersEditSession()` helper is now called from
  both paths.
- **"Archived is terminal for saving"** turned out to have a real, pre-existing gap
  for the *pre-publish* draft side: `saveDraft()`/`publish()` only ever checked
  `isPublished()`, never `isArchived()`, so a side that never published could keep
  autosaving and even successfully publish onto an already-archived anketa. This
  predates this feature (reachable via a stale, un-reloaded tab even before
  polling existed) but polling turns "a tab nobody reloaded" from an edge case into
  the routine state this feature is explicitly built around, so it was fixed here:
  both endpoints now reject with 409 once archived, and the frontend shows a
  distinct "archived" tag ahead of the draft UI instead of an editable, publishable
  form.

## Alternatives considered

See `private/live-updates-proposal.md` §4 for the full SSE/Mercure/WebSocket
analysis. Also considered and rejected during implementation:

- **A `Anketa::goalsVersion` column for goal live-refresh** — dropped for the reasons
  above (§ Decision).
- **Per-field granular polling endpoints** instead of one full-detail re-fetch on any
  diff — rejected as premature: the full-detail endpoint already exists
  (`get()`/`serializeDetail()`), reusing it is simpler than building narrower
  granular endpoints for a self-hosted, low-concurrency deployment target.
- **Forking `findAccessible()` into eager/lazy variants** so only `get()`/
  `liveState()` pay for the employee/manager eager join added to fix a per-poll-tick
  N+1 — rejected: a single indexed-FK join on SQLite for a single-row lookup is cheap
  enough that one unified code path was judged simpler and more valuable than the
  added complexity of two query-building paths for an unmeasured, marginal cost.

## Consequences

- No schema change, no new dependency, no new deployment topology.
- A live comment/edit now appears on an already-open tab within one poll interval
  (default 4s) instead of requiring a manual reload — the original ask.
- `saveDraft()`/`publish()` gained a real behavior change: both now 409 once
  archived, where they previously silently succeeded. Two new backend tests cover
  this (`testSaveDraftRejectsOnceArchivedEvenIfNeverPublished`,
  `testPublishRejectsOnceArchivedEvenIfNeverPublished`).
- `handleArchive()`'s outcome carry-forward now re-encrypts from the current
  in-memory `allOutcomes` (kept fresh by both self-saves and the live poll) instead
  of a load-time snapshot that never updated — fixes a real, if narrow, silent
  outcome-drop bug that predates this feature but that long-lived polling tabs make
  meaningfully more likely to hit.
- Comments' busy-gating (and, extended here, outcomes'/checkpoints') is effectively
  page-wide, not per-item — one open reply/edit box anywhere pauses live-refresh for
  that whole section until it closes. An accepted, bounded, self-healing trade-off
  matching the precedent already set for comments' own shared-blob design.
- `commentThreadsBusy` remains one flat record spanning four id namespaces
  (question-field, outcome, goal, checkpoint ids) rather than being namespaced
  structurally — mitigated by explicit pruning at every current removal path plus a
  `CommentThread`-level effect cleanup that self-clears on unmount as defense in
  depth, but a genuinely new removal path (e.g. goal deletion, not implemented today)
  will need to add its own prune call or self-heal only via the effect cleanup.

## Verification

Real round trips against the isolated e2e stack (`make e2e-up`), not mocks: two
independent Playwright browser contexts, real WASM crypto, no manual reload on the
tab that has to observe the live update. Three dedicated new scenarios added to
`frontend/e2e/dual-actor-anketa.spec.ts`: a published answer edit and a new comment
(with its highlight) both appearing on an already-open tab; a counterpart archiving
mid-edit correctly exiting the edit session; a counterpart archiving an anketa the
other side never published correctly disabling the draft. Full suite (9 specs) green,
plus the two new backend functional tests. `composer stan`/`cs`/`test` (354 tests) and
`npm run check`/`lint`/`format`/`test`/`knip` all clean.

**A real CI-only failure, and a wrong first diagnosis, corrected before merge.**
Both new e2e tests were green locally but the `e2e` CI job failed twice in a row on
the same test's own account activation — looked exactly like this project's
documented argon2id-under-load flakiness (`docs/history.md`'s own precedent for
this class of issue), so the first fix applied that playbook: closed
`dual-actor-anketa.spec.ts`'s never-closed browser contexts (a real, if unrelated,
resource leak worth fixing regardless) and gave the two new tests a longer
per-test timeout. CI still failed, at the same spot, for the *full* extended
timeout — inconsistent with "just needs more headroom." Downloading the actual
Playwright trace artifact from the failing CI run showed the real cause: the
account-activation POST itself returned a genuine `429 Too Many Requests`. The full
suite makes 21 real activation completions from one IP; the default
`ACTIVATION_COMPLETE_RATE_LIMIT` (10/minute, sized for production) was already
tight before this PR added two more activation-heavy tests, which tipped it over
into failing (near-)deterministically instead of occasionally. Fixed at the actual
root: `backend/.env.e2e` now overrides `ACTIVATION_COMPLETE_RATE_LIMIT=100`, using
the exact env-var override mechanism `config/packages/rate_limiter.php` already
provides for this class of problem (originally added for a production operator's
invite-sending throughput, per that file's own header comment). The over-long
per-test timeouts were reverted once the real fix was confirmed (two clean, fast
full-suite runs) — they addressed a diagnosis that turned out to be wrong, and
leaving them in would have hidden a real future regression under a bigger timeout
budget than the fix actually needs. The context-closing fix was kept as a real,
independently-justified hygiene improvement, unrelated to the actual failure.

Built via a 13-round `code-review` loop (unusually long, matching this project's own
documented precedent for concurrent-state changes — see
`docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md`). Real bugs found and
fixed along the way, in rough order of severity: a stale-snapshot outcome-carry-forward
bug on archive; a `commentThreadsBusy` cross-namespace clobbering bug in an earlier
version of its pruning helper; `myBlobChanged` never self-healing without a
`myPublished` gate; the pre-publish `saveDraft()`/`publish()` archived gap described
above; a same-tick ordering bug where resetting `editingMyAnswers` on archive discovery
made a concurrent own-blob update apply anyway (fixed by capturing
`wasEditingMyAnswers` before any mutation); and a final pass extending every busy gate
to be re-checked immediately before its commit, not just before starting its decrypt,
since a user can start a local edit during the fetch/decrypt window as easily as
finish one.
