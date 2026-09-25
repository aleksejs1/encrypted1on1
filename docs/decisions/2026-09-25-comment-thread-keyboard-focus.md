# Comment actions keep keyboard focus

## Problem

[GitHub issue #149](https://github.com/aleksejs1/encrypted1on1/issues/149) was recorded as a deferred
edge of the collapsed read-only view (`2026-09-25-collapsed-read-only-anketa-view.md`). Deleting the
last comment on an emptied field hides the field along with its thread, so keyboard focus on
"Confirm delete" falls back to `<body>`.

Measured on the e2e stack before the fix, the problem was wider than that. Every comment action lost
focus, not just the one that hides a field:

- Pressing Enter on "Delete" swaps it for "Confirm delete"/"Cancel", so focus went to `<body>`. A
  keyboard user couldn't reach "Confirm delete" without tabbing from the top of the page.
- "Confirm delete" dropped focus even when the field stayed shown, because the button goes away with
  the deleted comment.
- Edit, Save and Cancel swap their own buttons in the same way. Chromium also blurs a focused button
  as soon as it's disabled for an in-flight request, which Post, Save and Confirm delete all are.

The maintainer chose to fix all of `CommentThread`, not only the hidden-field case.

## Decision

**First, a pure extraction.** `Anketa.svelte` rendered each question block twice, once for my side
and once for the counterpart's. Both are now `frontend/src/anketa/AnswerBlock.svelte`, so the focus
fallback exists once. Anketa.svelte still owns the keyed state the block binds into (`myAnswers`,
`fieldsWithOpenEntryEdit`, `commentThreadsBusy`), because its live-update poll reads that state (see
`2026-09-18-anketa-component-decomposition.md`). The counterpart's answers are bound too, although
never written: the block binds them down to `AnswerField`, and Svelte warns
(`ownership_invalid_binding`) when that goes through a prop the parent didn't bind. The block keeps
one keyed `{#each}` over its shown fields (#131 §4.2). All 20 existing e2e tests passed on the
extraction before any focus change.

**Then explicit focus after each transition in `CommentThread.svelte`:**

| Action | Focus goes to |
| --- | --- |
| Post (success or error) | the new-comment input |
| Edit | the edit input |
| Edit → Cancel, or Save succeeds | that comment's Edit button |
| Save fails | the edit input |
| Delete | that comment's **Cancel**, not Confirm delete |
| Delete → Cancel | that comment's Delete button |
| Confirm delete succeeds | the thread's toggle button, which now reads the lower count |
| Confirm delete fails | Confirm delete again |
| Confirm delete succeeds and the thread is gone | the question block's `<h4>` (`tabindex="-1"`), via a new `onFocusLost` prop |

`refocus()` waits for `tick()` and then moves focus, but only when focus is still inside the thread
or was dropped to `<body>`. If the user moved focus elsewhere while a slow request was in flight, it
stays there.

Focus goes to Cancel after Delete, following the WAI-ARIA APG pattern for destructive confirmations.
A button activates on Enter keydown, so with focus on Confirm delete, holding Enter a moment too
long would delete the comment. Confirm delete is one Shift+Tab away.

`onFocusLost` is optional. Only `AnswerBlock` passes it: an outcome's or goal's thread isn't
unmounted by deleting one of its comments.

## Alternatives considered

- **Detecting the unmount from focus events.** This would have covered a case the issue mentions:
  the other participant deletes the last comment on my emptied field via the live poll while my
  focus is inside that thread. A probe showed Chromium fires the same `focusout` (with `relatedTarget`
  null and the target still connected) for a removed button, a disabled button and a click on blank
  page space. Telling them apart would take guesswork that could pull focus, and scroll, away from a
  user who had just clicked elsewhere. By the time a component's teardown runs, Svelte has already
  removed its DOM. That poll case is left as it was: focus falls to `<body>`.
- **Focus on Confirm delete after Delete.** This is the issue's own wording and one keystroke
  shorter. It was dropped for the held-Enter reason above.

## Out of scope

Outcomes (`AnketaOutcomes.svelte`) and list-answer entries (`AnswerField.svelte`) swap their
Edit/Delete/Save/Cancel buttons the same way and don't manage focus either. Reading the code shows
this; it wasn't measured. They're tracked as [GitHub issue #151](https://github.com/aleksejs1/encrypted1on1/issues/151) rather than widened into this change. Since fixed, see `2026-09-25-outcome-and-entry-keyboard-focus.md`, which also moved `refocus()`/`focusIsFree()` into `frontend/src/anketa/keepFocus.ts`, narrowed the "still inside the thread" check above to the comment's own row, gave `onFocusLost` a `FocusOptions` argument so a pointer delete's fallback doesn't scroll, and made the thread ignore an auto-repeated Enter (`ignoreHeldEnter()`), so a held Enter doesn't carry into the control focus moved to.

## Verification

- A new dual-actor e2e test, `comment actions keep keyboard focus, down to a hidden field`, drives
  every row of the table from the keyboard. It posts, edits, cancels, deletes, and fails a delete
  through a routed 500. It holds an edit's save while focus moves to the outcome input, which must
  keep focus. Finally, the employee empties the answer and the manager deletes its last comment,
  after which the block heading must have focus.
- Three temporary breakages each made that test fail at the expected assertion: `refocus()`
  returning immediately, `AnswerBlock` not passing `onFocusLost`, and `focusIsFree()` always
  returning true.
- The full e2e suite passed on the extraction alone, with no Svelte dev warnings.
