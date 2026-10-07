# Closing a meeting asks first, and never over my own unpublished answers

Closes [GitHub issue #229](https://github.com/aleksejs1/encrypted1on1/issues/229).

## Problem

An employee with a filled-in draft remembered that Publish is "the button at the bottom",
scrolled to the end of the page and pressed the big button there. It was Archive. The meeting
closed for both people at once, with no question and no way back, and the draft could no longer
be published: the pair re-created the meeting and copied everything over by hand.

Publish and Archive were both `btn-primary`, Archive was the last one on the page and enabled
whatever state my side was in, and its label said nothing about closing the meeting for both.
"Didn't happen" in the header's "not closed" card was the same one-click archive.

## Decision

Frontend only: no endpoint, schema or encryption change.

- **Closing is two presses.** The first opens a confirmation in place (`anketa/ArchiveConfirm.svelte`,
  no modal) and sends nothing. Focus lands on Cancel. It is shown every time: a meeting is closed
  once, so there is nothing to get tired of.
- **Never over my own unpublished answers.** If my side is unpublished and its draft has an
  answer, the confirmation's button is "Publish and close": `closeMeeting()` in `Anketa.svelte`
  publishes first and archives only if that worked. There is no way to close and leave the draft
  behind.
- **Everything else is allowed, with a warning** saying whose answers the closed meeting will
  lack: the counterpart's (the meeting happened, they never published), mine (an empty draft), or
  both (nobody filled it in). Forbidding these would leave such a meeting impossible to close.
  The rule is `archiveConfirmation()` in `anketa/archiveConfirmation.ts`.
- **"Didn't happen" gets the same confirmation and the same rule**, so the trap doesn't move to
  the top of the page.
- **The archive button is secondary** and says what it does ("Close and schedule the next one",
  or "Close this 1:1" for a one-off or with "don't create the next meeting" ticked). While my
  side is unpublished, Publish is the page's only primary button.

## Details worth knowing

- **"The draft has an answer" is `hasAnswer()`** in `answerDisplay.ts`, built on the
  `isAnswerEmpty()` the read-only view already uses: what publishing would actually show. Text
  typed into "add an entry" but never added doesn't count, as in the published-answers edit
  (#166). For a company template whose questions couldn't be loaded there are no fields to check
  against, so any content in the draft counts.
- **The check is client-side only.** The draft is encrypted, so the server can't tell whether it
  is empty. This guards against an accidental press; the archive endpoint is unchanged, and a
  request built by hand can still close a meeting over a draft.
- **A double click only opens the confirmation.** Its second click lands on whichever of the
  confirmation's buttons is now under the pointer, and both ignore it (`click.detail > 1`), so it
  neither closes the meeting nor dismisses the question. A held Enter is ignored as in the other
  focus-moving regions (`ignoreHeldEnter()`).
- **The confirmation remembers the anketa it was opened on**, not a boolean: the page component
  is reused when navigating to another meeting.
- **Closing waits for open edits**, as before for an edit of published answers, and now also for
  an open list-entry edit when closing would publish the draft (Publish waits for it too).
- **Both controls are disabled during any publish of my side as well as the archive**, so a
  second press can't start another; they read "Archiving…" only while the meeting is being
  closed.
- **A tab that missed its own publish is told to reload.** The live-state poll never updates my
  own side, so a tab whose side was published elsewhere (another tab, or a lost response) has
  "Publish and close" refused with 409 on every attempt. A publish 409 now says so and asks to
  copy what was typed and reload (`anketa.publishConflict`), in place of the server's bare
  "Already published.". Reloading for the person was tried after the first review round and
  dropped after the second: `load()` replaces the text typed in that tab and resets the archive
  form's date and meeting type.
- **A missing next-meeting date stops the close before the publish.** Other archive failures
  (a "discussed"/topics save that didn't settle, a template archived meanwhile) can't be known up
  front, so there my side ends up published with the meeting still open and the error shown,
  which is what Publish alone would have done.
- **"Didn't happen" confirms with "Close as missed"** and a line saying the meeting will be
  marked as missed, so it can't be taken for the card's other "Close this 1:1" button.
- **Accepted:** the double-click guard also drops a deliberate click made right after another in
  the same spot (`detail` counts any quick clicks there), which then has to be repeated.

## Not covered

The side that published can still close the meeting while the counterpart is writing their
draft; the warning names it, but only an undo would recover it. That is
[GitHub issue #230](https://github.com/aleksejs1/encrypted1on1/issues/230).

## Verification

Unit tests for `archiveConfirmation()` (every combination) and `hasAnswer()`. Three dual-actor
e2e tests against the real stack: closing with a draft publishes it first and the counterpart
then reads the answer, with no archive request before the confirmation and a double click
opening it only; a tab whose publish response was lost is told to reload on the 409, keeps its text
and choices, and closes after the reload; and the published side closes a meeting the counterpart never published on,
after the warning. Every existing e2e archive goes through the confirmation
(`e2e/helpers/archive.ts`), as do the two generation scripts.
