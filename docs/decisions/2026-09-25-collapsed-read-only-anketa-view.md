# The read-only anketa view shows only what was answered

## Problem

GitHub issues [#134](https://github.com/aleksejs1/encrypted1on1/issues/134) and
[#135](https://github.com/aleksejs1/encrypted1on1/issues/135), parts 1 and 2 of
[#131](https://github.com/aleksejs1/encrypted1on1/issues/131). The read-only view of an anketa side
showed filler around unanswered fields: "Anything to add?" followed by "No answer.", an "Entries"
label followed by nothing, and rows of disabled, unchecked radios. During a live meeting both
participants had to read past it. The full design, reviewed before it was split into issues, is in
#131. This record keeps the decisions and the accepted edges in the repo.

## Decision

**A collapsed view, separate from readonly.** It applies to the counterpart's side, to my own side
once published and not being edited, and to both sides once archived (including a side that was
never published). `Anketa.svelte` has two deriveds: `mySideReadonly` (the existing expression, which
includes an in-flight Save) and `mySideCollapsed`, which deliberately ignores `savingAnswersEdit`.
Collapsing the instant Save is clicked, and expanding again if it failed, would remount list fields
and lose their unsubmitted new-entry text. `AnswerField` takes a `collapsed` prop, which implies
readonly.

**What a collapsed side shows.** `frontend/src/anketa/answerDisplay.ts` holds the rules as pure,
unit-tested functions:

- `isAnswerEmpty(field, value)`: text that is empty or whitespace, an empty list, or a radio/checkbox
  value that isn't one of the field's options. Markdown-only text such as `**` counts as answered,
  the same as before; that is out of scope.
- `readonlyVisibleFields(question, answers, hasComments)`: every answered field, plus an unanswered
  one that already has comments, so a comment on an answer that was later cleared stays reachable.
- A block with no field shown renders its title and one muted "No answer." line (`.block-empty`).
  The block stays, since "no achievements this time" is information. The line doesn't use
  `.answer-text`, so the existing `.answer-text').first()` e2e assertions can't match it.
- An empty field kept only for its comments shows its label (even a generic one), a "No answer."
  line (`.field-empty`) and its thread.
- `GENERIC_LABEL_KEYS` ("Entries", "Details", "Anything to add?") are captions for an input. They
  are dropped above an answer. Every other label, the real sub-prompts, keeps showing. A structural
  "single-field block" rule was rejected because it would also hide real sub-prompts, such as the
  support template's "(the date shown is when the entry was added)".

Each side keeps **one keyed `{#each}` per block** over the fields it shows, not an `{#if}` between two
loops. Toggling Edit/Save/Cancel then only mounts or unmounts the fields whose visibility actually
changes, instead of every `AnswerField` and `CommentThread` on the side losing its comment drafts,
expanded threads and list entry text.

**No new comments on unanswered fields in the collapsed view.** A comment needs something to
comment on. "You skipped X" belongs in the discussion list, or is said out loud in the meeting. My
own side in edit mode after publishing still shows a thread on every field.

**Radio and checkbox answers show only the chosen options** (#135, part 2 of #131). In the collapsed
view a radio shows its chosen option's label as plain text (`.answer-choice`, not `.answer-text`,
which is for Markdown), under its field label, which is always a real sub-prompt. Checkboxes show
only the chosen options, in the field's option order, as a `<ul class="answer-choices">` of `<li
class="tag pill pill-chosen">`. That reuses the chosen-pill styling at full opacity instead of the
dimmed disabled buttons, and a screen reader hears "list, 2 items" rather than disabled form
controls. `selectedOptions(field, value)` in `answerDisplay.ts` skips unknown stored values, like
`isAnswerEmpty`, which for choice fields is defined through it, so a shown choice field always has
an option to show. The list has `role="list"`, since WebKit drops list semantics once `list-style`
is `none` (the list-answer `<ul class="entries">` got the same). The field label comes right before
the answer, for radios and checkboxes alike. Naming the list with `aria-labelledby` was tried and
dropped: it made screen readers read the prompt twice, and a radio's `<p>` can't be named that way,
so the two choice types would have been handled differently. A radio answer reads at the answer-text
size (14px) while the chosen pills keep the pills' own 11px, a deliberate consequence of reusing the
edit-mode pill styling. A unit test checks that no radio or checkbox field uses a generic label, so
that label is always shown. Only `collapsed` switches this on: during an in-flight Save the side
keeps today's disabled radios and pills, so Save never flips them to text and back. The #131 plan
was to wait for real-meeting use of part 1 first; the maintainer chose to build it right after part
1 instead.

## Accepted edges

These come from the state and unmount audit in #131 §4.5:

- An unsent first comment on a field that gets emptied is discarded when the field hides. That
  happens when the counterpart empties it (via the live poll), or when my own Save or Cancel does.
  Keeping it would need the unsent draft text to count as busy, threaded through every thread; not
  worth it for this edge. The live poll's counterpart-answers comment and `CommentThread`'s
  `hasOpenAction` docblock say so.
- An unsent reply on an empty field is discarded when the other participant deletes the field's
  last comment (via the poll).
- Typed but not yet added list text in an empty list field is discarded when the field hides: on
  the first Publish, and on a later Save or Cancel. It was never part of the saved answer. In a
  non-empty list the field stays mounted, so the text survives there as before.
- A comment that arrives via the poll on a field the collapsed view was hiding (for example, the
  counterpart comments on one of their own empty fields while editing) mounts the thread with the
  comment already present. Its `aria-live` announcer doesn't fire for content present at mount, so
  that arrival is highlighted but not announced (#131 §4.6 names the narrower version of this).
- When the counterpart archives while I have unsaved edits open, the page leaves those unsaved
  values displayed, as it did before (the archive-mid-edit e2e test asserts this). The collapsed
  view now also decides which fields show from them, so a cleared field can hide and an unsaved new
  answer can show. Reverting to the saved answers on archive would change that existing behavior,
  so it is left as is.
- Found in review, not in #131, and deferred: deleting the last comment on an emptied field myself
  unmounts the field while keyboard focus is on "Confirm delete", so focus falls back to `<body>`.
  A fix would move focus to the block's heading or its "No answer." line. It needs a field that was
  answered, commented on, emptied, and then has its last comment deleted, so it is left for later.
  Tracked as [GitHub issue #149](https://github.com/aleksejs1/encrypted1on1/issues/149), since
  fixed: see `2026-09-25-comment-thread-keyboard-focus.md`.

`AnswerField`'s `hasOpenEntryEdit` now clears itself on unmount, the same as `CommentThread`'s
`hasOpenAction`. No path unmounts a field with an open entry edit today (an open edit means a
non-empty list, which is always shown), but a stuck `true` would disable Publish/Save/Cancel for
good.

## Verification

- Unit tests for `isAnswerEmpty`, `readonlyVisibleFields`, `GENERIC_LABEL_KEYS` and
  `selectedOptions` (`answerDisplay.test.ts`).
- Two new dual-actor e2e tests in `frontend/e2e/dual-actor-anketa.spec.ts`. They cover the
  counterpart side and my own published side, Edit bringing every prompt back and Cancel collapsing
  it again, a Save held in flight keeping the edit-mode layout, an emptied commented field reaching
  the other participant's open tab via the poll, the archived states (published and never
  published), and the support template's real sub-prompt label. Temporarily making
  `mySideCollapsed` follow `savingAnswersEdit` made the held-save assertion fail.
- A third e2e test for #135: a chosen mood radio shows as text and two feelings pills as a two-item
  list in option order (clicked in the reverse order), on the counterpart side and my own published
  side, with no radios or pill buttons left. Edit brings back every option, still selected; the mood
  is then changed and a Save held in flight keeps every option shown, selected and disabled, and the
  new choice reaches the other participant's open tab via the poll. After archive both participants
  still see only the chosen options. Temporarily switching the collapsed radio and checkbox branches
  to `readonly` made that held-Save check fail.
- The existing tests' `.thread').first()` locators pointed at the empty mood radio's thread before;
  they are now scoped to the Mood block's answered notes field (`moodNotesThread()`).
- Doc screenshots (`generate-doc-screenshots.mjs`) will look different when next regenerated; they
  weren't regenerated here. `generate-demo-fixture.mjs` and `generate-doc-screenshots.mjs` found the
  demo comment with `.thread').first()`, which only worked because the demo fixture answers
  `moodNow`. A fixture that left it empty would have silently moved the comment. They now target
  `moodNow`'s thread explicitly through a `data-field-id` attribute on `AnswerField`, the same field
  as before, so the generated output doesn't change.
