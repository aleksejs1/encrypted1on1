# Decompose Anketa.svelte into subcomponents

## Problem

`frontend/src/pages/Anketa.svelte` had grown to 2,522 lines managing anketa metadata/rescheduling, key
unsealing/blob encryption, draft backup, live-state polling, form rendering, outcomes, goals/checkpoints,
and archiving, all in one component scope with 35+ independent `$state` variables. [GitHub issue #64](https://github.com/aleksejs1/encrypted1on1/issues/64) proposed splitting it into
`AnketaHeader.svelte`/`AnketaOutcomes.svelte`/`AnketaGoals.svelte`/`AnketaArchiveModal.svelte`, out of
scope for any visual/encryption/API/live-poll-payload change — a pure decomposition.

## Decision

Extracted `AnketaHeader.svelte`, `AnketaOutcomes.svelte`, `AnketaGoals.svelte`, and
`AnketaArchiveSection.svelte` (not `...Modal.svelte` — the archive/missed UI is two inline cards, not a
modal dialog; named for what it actually renders, not the issue's literal suggested name), plus
`LockIcon.svelte`, `commentThreadsBusy.ts`, and `authorLabel.ts` for a few pieces that would otherwise have
been duplicated three ways. `Anketa.svelte` (2,522 → ~1,200 lines) keeps everything genuinely
cross-cutting: crypto/blob-sync, the live-update poll, and the answer-editing state machine.

Two design points worth recording, since they came up repeatedly across review rounds:

- **`commentThreadsBusy` is declared `$bindable` in `AnketaGoals.svelte` even though nothing there
  reassigns it wholesale today** (only `AnketaOutcomes.svelte`'s delete handler does). `AnketaGoals.svelte`'s
  own docblock already anticipates a future goal/checkpoint-delete path needing the same
  `pruneStaleBusyEntries()` call `AnketaOutcomes.svelte` makes — a plain (non-bindable) prop would let that
  future call compile while silently only rebinding the local copy, never reaching `Anketa.svelte`'s real
  state. Reviewed back and forth three times (rounds 1, 3, 6/8) before settling here: this isn't
  speculative flexibility for a hypothetical, it's closing a landmine for a change the code's own comment
  already commits to describing as needed.
- **`isOverdue()` (`frontend/src/anketa/isOverdue.ts`) had its signature widened** from
  `{ archivedAt: string | null; meetingDate }` to `{ archived: boolean; meetingDate }` so
  `AnketaHeader.svelte` — which only has the page's live-corrected `archived` boolean, not
  `detail.archivedAt` (never written back to after load) — could call the shared, tested helper instead of
  reimplementing the same two-line formula a third time (`AnketaList.svelte` already has its own copy, for
  an unrelated reason: it needs the same `daysUntilMeeting()` call's numeric result for its "N days" label
  right next to it, per `isOverdue()`'s own docblock on why two independent day-comparisons must never
  happen). This project has already been burned once by exactly this class of drift
  ([GitHub issue #28](https://github.com/aleksejs1/encrypted1on1/issues/28), the overdue flag flipping a
  day early because it was computed independently in three places) — only its own unit test called
  `isOverdue()` before this, so widening its shape was zero-risk.

## Out of scope, found but not fixed

Three issues surfaced during review, all pre-existing and unrelated to the decomposition itself — fixing
any of them would have meant behavior changes or touching files this issue doesn't own:

- `handleSaveGoal()` (now in `AnketaGoals.svelte`) doesn't disable its title/description inputs while a
  save request is in flight, so a user editing again before the response lands can have their new edit
  silently overwritten by the server's pre-edit snapshot. Also has no empty-title guard, unlike
  `handleAddGoal()`. Both identical to the pre-refactor `Anketa.svelte`.
- `GOAL_STATUS_KEYS`/`CHECKPOINT_STATUS_TAG_KEYS` (now in `AnketaGoals.svelte`) are duplicated in
  `frontend/src/pages/Report.svelte` — predates this diff; the decomposition relocated one copy, it didn't
  create a new duplicate.
- `.native-checkbox` CSS is duplicated across `AnketaArchiveSection.svelte`, `ResetPassword.svelte`, and
  `AccountSettings.svelte` — same, pre-existing.

## Verification

Typecheck/lint/unit tests/knip/format clean throughout. Full real-crypto e2e suite (9 dual-actor Playwright
specs against the isolated stack, see `e1o1-verify-with-real-crypto`) passed after every round, including a
throwaway spec written specifically to exercise the Outcomes/Goals UI (add/edit/delete outcome; add goal,
edit fields, save, change status, add checkpoint) since nothing in the existing suite touched those
sections — deleted after confirming green. 9 rounds of the `code-review` skill loop; real issues fixed
along the way (the `commentThreadsBusy` binding gap above, the `isOverdue()` widening above, dead/duplicated
`authorLabel` logic consolidated into `authorLabel.ts`, missing unit tests added for the two new shared
modules, several stale `Anketa.svelte`-file cross-references in `goals.ts`/`report.ts`/`CommentThread.svelte`
corrected) before landing on nothing but the pre-existing, out-of-scope items above.
