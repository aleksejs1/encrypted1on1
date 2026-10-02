# Editing published answers: sticky actions and an unsaved-changes guard

Closes [GitHub issue #166](https://github.com/aleksejs1/encrypted1on1/issues/166). Builds on
[`2026-09-07-editable-published-anketa-answers.md`](2026-09-07-editable-published-anketa-answers.md).

## Problem

A published side is edited by clicking "Edit", changing answers and clicking "Save". Both buttons
were only at the bottom of my side's card, which on a long anketa is far from the first questions.
Unlike a draft, which autosaves, such an edit is kept only by an explicit Save, yet nothing on
screen showed an edit was open once its buttons scrolled away, and closing the tab dropped it
without a warning. A user lost an edit exactly that way.

## Decision

- **Edit is also in the card's header**, next to "My side (role)". The bottom's Edit stays.
- **While editing, a bar sticks to the top of the viewport** within my side's card: "Editing
  answers", "Unsaved changes" once there are any (not a live region, which would be announced on
  nearly every keystroke into an empty or restored field), Save and Cancel. A top bar rather than a bottom
  one: it never sits under a phone's on-screen keyboard, and it stays inside the form's column
  next to the notes column. The bottom's Save/Cancel stay too, for someone reading down the card.
- **Focus isn't hidden under the bar**: while it's shown, the page gets a `scroll-padding-top`
  (more on a narrow screen, where the bar wraps), so a control focused by keyboard doesn't scroll
  to the very top, behind it.
- **Save is the primary button only while there are unsaved changes**, secondary otherwise. It
  stays enabled either way, as before.
- **Ctrl+S / ⌘S anywhere in my side saves** the edit instead of opening the browser's "Save
  page" dialog (`saveShortcutPlace()` in `answersEdit.ts`). Not in a comment thread, whose text
  Save wouldn't keep, nor with focus on `<body>`, which may be anywhere on the page. It does
  nothing while a save is in flight or a list entry's inline edit is open, the same as the
  disabled buttons, nor in an "Add an entry" form whose input holds text, which a save would
  drop. Fields, question blocks, comment threads and add-entry forms are found by data attributes
  set for it (`data-field-id`, `data-question-block`, `data-comment-thread`, `data-add-entry`),
  not styling classes. A held
  shortcut's repeats are swallowed anywhere on the page. On a non-Latin layout the physical S key counts, as for the browser's own shortcut;
  on a Latin layout only the letter S does (Dvorak's physical S key is its Ctrl+O).
- **Closing or reloading the tab warns while there are unsaved changes**, through a beforeunload
  listener attached only then (Firefox keeps no page with one in its back-forward cache). The
  attach/detach toggle is now shared with the private notes' warning
  (`frontend/src/anketa/unloadWarning.ts`), each with its own listener. In-app navigation
  doesn't warn, as with the notes.
- **"Unsaved changes" is derived, not a new state**: `answersEditUnsaved` in `Anketa.svelte`
  compares `answersFingerprint(myAnswers)` with the fingerprint of the snapshot taken when editing
  started (`frontend/src/anketa/answersEdit.ts`; the snapshot's is computed once per session).
  Blank (including whitespace-only) values are dropped and keys and checkbox selections sorted,
  so a field typed into and cleared again, or an option unchecked and checked again, reads as
  unchanged. A list entry's inline edit counts as unsaved as soon as it's open, even unchanged:
  its text isn't in `myAnswers` until applied, Save is disabled until then anyway, and a warning
  too many beats a lost entry. Text typed into a list's "Add an entry" input and never added
  doesn't count: it isn't an answer, and Save doesn't keep it (unchanged from before). Counting it
  was tried in review and dropped: a warning about text that Save then silently drops, or
  clearing it on Save, each contradicted the indicator.
- **Focus follows the swapped buttons** (#149, #151): Edit lands on the Cancel that replaces it,
  Save and Cancel on the Edit that replaces them, a failed Save back on Save, all on the same
  side of the card. A Ctrl+S save from a field lands on the heading of the question being edited,
  since the field is swapped out while the save is in flight; if it failed, a free-text answer
  gets focus back in its textarea (other fields don't say which control had focus, so the
  heading). A save that finds the anketa archived lands on my side's heading, since no Edit is
  left. The header's Edit and the bar can be far apart once the bar has followed the user down,
  so the page scrolls there only for a keyboard press, not a mouse click
  (`fallbackFocusOptions()`, through a new `focusOptions` pass-through on `refocus()`). My
  side's card ignores a held Enter's repeats (`ignoreHeldEnter()`), as #151's regions do, so
  Edit and Cancel, each focusing the other, don't flip back and forth. Review
  went back and forth on that: a keyboard user saving from the bar deep in the card then scrolls
  up to the header, but focus moved off-screen without scrolling is worse, and it's the
  convention the rest of the page follows.
- **Two older gaps in `handleSaveAnswersEdit()` fixed on the way**, since the new shortcut and
  focus code made them reachable from more places: a response arriving after the page reloaded
  the anketa (another one opened, or this one again) no longer writes into the new state, its
  saving flag included. It's recognized by a counter `load()` bumps, not the anketa id, so A → B
  → A is caught too, while an archive arriving from the live poll mid-save still lets the
  response through. A save superseded while it was still being encrypted isn't sent at all, and
  its expected version is read when it starts. And a
  409 whose saved content can't be decrypted no longer escapes as an unhandled rejection. It
  ends the edit showing what was published when it started, never this tab's unsent edit as if
  published, and takes the server's version so the live-state poll doesn't re-fetch it forever.
  That version means a later edit can overwrite the content this tab couldn't show; no option
  here is good, and the state needs my own tabs to disagree about the anketa key they share, so
  it isn't expected to happen. The old code took the version too, then threw.

## Alternatives considered

- **Autosaving post-publish edits** stays rejected, as the issue's own option table says: each
  save re-encrypts the whole side for the counterpart, who would see half-typed text.
- **Guarding in-app navigation** (header links, opening another anketa) as well as closing the
  tab: left out, as for the private notes, since the router has no leave hook yet.
- **Keeping the caret after a failed Ctrl+S save**: focus goes back to the textarea, but it's a
  new one (the field is read-only while the save is in flight), so the caret starts at the top.
- **Ctrl+S in a list entry's open inline edit** does nothing (and doesn't open the browser's
  dialog), like the disabled Save; applying the entry and saving in one keystroke was left out.
- **Confirming Cancel when there are unsaved changes**, mentioned in the issue's product-review
  notes but not in its acceptance criteria. Not added: Cancel is a ghost button next to the primary
  Save, and a confirmation step can follow if Cancel turns out to lose edits too.
- **Deep-cloning on edit start, as a bug fix.** The issue says `{ ...myAnswers }` let list and
  checkbox edits mutate the Cancel snapshot. They don't: `AnswerField` replaces those arrays
  rather than mutating them. The snapshot is now `$state.snapshot(myAnswers)` anyway, because the
  unsaved-changes check needs a reactive copy nothing typed can reach. `structuredClone()`, the
  issue's suggestion, throws on a Svelte `$state` proxy.

## Verification

- Unit tests: `answersEdit.test.ts` (changed text, checkbox selection, list entry
  added/edited/removed, new field, key order, checkbox order vs. list entry order, cleared
  fields; the shortcut, on Russian and Dvorak layouts too), `unloadWarning.test.ts` (attached
  only while needed, independent warnings, the re-check on close), `saveShortcutPlace()` (a
  field, the top/bottom controls, a question heading, an "Add an entry" input holding text; a
  comment thread, `<body>` or the other side ignored) and a `focusOptions` case in `keepFocus.test.ts`.
- e2e (`frontend/e2e/dual-actor-anketa.spec.ts`): the first edit flow starts from the header,
  checks focus lands on the bar's Cancel, no and then an "Unsaved changes" status, the bar
  still in the viewport with the card's bottom scrolled into view, a real `beforeunload` dialog
  on closing the page, and a Ctrl+S save that removes the bar and focuses the question's heading.
  The entry-edit flow checks that an open entry edit shows "Unsaved changes". The other edit
  flows now pick the bottom's buttons explicitly, since a role-and-name lookup now matches two.
- Review: 9 `code-review` rounds, capped there by the maintainer. Round 1 found Ctrl+S dead on a
  Russian layout and several focus losses; rounds 2–4 went back and forth on counting "Add an
  entry" text as unsaved until it was dropped; later rounds found stale-response races in
  `handleSaveAnswersEdit()` (A → B → A, an archive mid-save) and scoping gaps in the shortcut.
  Left as is from round 9: a version-conflict 409 whose blob can't be decrypted still takes the
  server's version (see above); Ctrl+S ends the edit like Save, even with nothing changed; Ctrl+S
  in a comment thread opens the browser's dialog; the larger scroll padding is keyed to screen
  width, not to whether the bar actually wrapped; `notesSession.ts` and `TemplateEditor.svelte`
  still toggle their own beforeunload listeners rather than `unloadWarning.ts`.
