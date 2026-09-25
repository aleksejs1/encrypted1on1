# Outcome and list-entry actions keep keyboard focus

## Problem

[GitHub issue #151](https://github.com/aleksejs1/encrypted1on1/issues/151) was the out-of-scope part of
`2026-09-25-comment-thread-keyboard-focus.md` (#149). Outcomes (`AnketaOutcomes.svelte`) and
list-answer entries (`AnswerField.svelte`: achievements, growth, discuss) swap their
Edit/Delete/Save/Cancel buttons in the same way `CommentThread` did, so each action dropped
keyboard focus to `<body>`. Adding an outcome did too: its input and button are disabled while
the request runs, and Chromium blurs a focused control once it's disabled.

## Decision

The #149 approach, applied to both components. `focusIsFree()` and `refocus()` moved out of
`CommentThread.svelte` into `frontend/src/anketa/keepFocus.ts` rather than being copied a second
and third time, as the issue asked. `CommentThread` now calls the shared version. Its focus check
was narrowed to the comment's row, and a successful delete whose row survives lands on that row's
Delete button instead of the toggle, for the reasons given under the tables.

**Outcomes:**

| Action | Focus goes to |
| --- | --- |
| Add (success or error) | the add-outcome input |
| Edit | the edit input |
| Edit → Cancel, or Save succeeds | that outcome's Edit button |
| Save fails | the edit input |
| Delete | that outcome's **Cancel**, not Confirm delete |
| Delete → Cancel | that outcome's Delete button |
| Confirm delete fails | Confirm delete again |
| Confirm delete succeeds | the card's `<h2>` (`tabindex="-1"`), or the row's Delete button if the row is somehow still there |

**List entries**, only on my own side while it's editable:

| Action | Focus goes to |
| --- | --- |
| Edit | the entry's edit input |
| Cancel or Escape, Save or Enter | that entry's Edit button |
| Remove | the question block's `<h4>`, via `onFocusLost` |

Adding an entry needs nothing: it isn't disabled and doesn't swap controls, so focus stays in the
add input or on the Add button.

All three components use one row-based shape from `keepFocus.ts`. `findRow()` looks the row up
before the action changes anything, and `refocus(row, selector, { onRootGone })` either focuses
inside the row or, once the row is gone, calls the fallback. Only a delete passes a fallback. When
any other action's row has vanished, that wasn't the user's doing, so focus is left alone rather
than sent to a heading that could be far away. A successful delete picks the row's own
Delete button as its target. The row is normally gone, so the fallback runs. If the row survived
(for example, the save returned early without writing), focus still lands somewhere real.

**After a request, a row action checks focus against its own row, not the whole card or thread.**
An outcome's save is a request, and the user can move on while it runs. If the check covered the
whole card, tabbing to the add-outcome input during a slow save would count as focus "still
inside", and the save would pull focus back to the Edit button. The row (`[data-outcome-id]`: the
checkbox, text and buttons, without the outcome's comment thread) is looked up before the action
changes anything, because a deleted outcome's row is gone afterwards. Every other control in that
row is disabled or swapped out while its request runs, so it can't hold focus meanwhile. Once the
row is gone, focus goes to the heading, and only if it was dropped to `<body>`.

Review found that #149's `CommentThread` had the same gap within one thread. During a slow comment
save, focus moved to that thread's new-comment input was pulled back to the Edit button. Comment
actions are now scoped to their `.comment` row, and posting to the new-comment form. Once a
deleted comment's row is gone, focus goes to the toggle as before, or through `onFocusLost` when
the whole thread went with it. The #149 e2e test's held-save step now moves focus to the thread's
own new-comment input instead of the outcome input, so it covers this.

List entries use the same row scoping, although their actions are synchronous, so every
component follows one rule. That rule is documented once, on `refocus()` in `keepFocus.ts`.

**A refocus after a request only runs if the user hasn't done anything since.** An action that
refocuses after awaiting a request (post, save, add, delete) calls `beginAction()` first and passes
the result to `refocus()` as `startedOn`. `keepFocus.ts` counts the user's interactions with the
page, using capture-phase `pointerdown`, `keydown`, `wheel` and `touchmove` on the document. It
skips a held key's auto-repeats, a modifier key on its own (a screen-reader user presses Ctrl to
silence speech), and a pointer press on a disabled control, which is where the second click of a
double-click on a busy button lands. A scroll or key press is counted even over a disabled control:
it's the user moving on. The listeners are installed on the first `beginAction()`,
not at import. `refocus()` does nothing once that count or `location.pathname` has changed since
`beginAction()`. The rule covers every control on the page with no per-action wiring, including
ones outside #151 like Publish or the goals controls:

- a button pressed anywhere else while the request ran, whose own request may have left focus on
  `<body>`;
- scrolling away during a slow save, which leaves focus on `<body>` but shouldn't be undone by a
  jump back;
- navigating to another anketa. The anketa page, its outcomes card and threads are reused then,
  so a late refocus would land on the new anketa's page.

An earlier version counted only the three components' own actions, and missed the other page
controls and scrolling. A remaining edge: trackpad momentum `wheel` events still arriving after a
click count as scrolling, so that click's late refocus is dropped and focus stays on `<body>`.

Review also suggested avoiding most of this by marking a busy control `aria-disabled` instead of
`disabled`. Focus would then stay on it through the request. That changes every busy control on the
page and how it's announced, so it wasn't done here.

A synchronous action (Edit, Cancel, Delete, Remove, a list entry's Save) always moves focus: it's
the user's own press, applied right away. That also covers a Safari mouse click, which doesn't
focus the clicked button, so focus can still be in another input when Edit is clicked.

**An auto-repeated Enter is ignored in these regions.** Every action now lands focus on the next
control, so an Enter held a moment too long carries over. On Edit, the repeats land in the edit
input and submit it at once. On Delete, they flip between Delete and Cancel. After a failed add or
delete, they retry on every round-trip. #149's comment thread already had all three. Focusing
Cancel after Delete deals with one repeated Enter; `ignoreHeldEnter()` in `keepFocus.ts` deals with
held ones. It's a capture-phase keydown handler that drops Enter when `event.repeat` is set. It sits
on the comment thread's root, the outcomes card, and a list field's entries `<ul>`. It isn't on the
whole field, because a text field's Markdown textarea takes a held Enter for new lines. The
list-entry edit and add inputs became real `<form>`s. Enter now saves or adds through the browser's
implicit submission, which a held Enter's `preventDefault()` stops, and which never fires on an Enter
that commits an IME composition. Their old `keydown` Enter handlers got that wrong. `isComposing`
checks were tried first, but Safari reports it false on the committing Enter, and keyCode 229 also
turns up on a plain Enter from some Android keyboards. The outcome and comment edits were already
forms. The IME behaviour can't be driven from Playwright, so it rests on the browser's rule.

This replaced an earlier attempt from review: skipping a save whose text matched the list. That
compared against a list the live poll stops updating while an edit is open. After another tab had
changed the item, saving it back to its old text was silently dropped. A held Enter also still
toggled the edit open and closed on every repeat.

A single listener at the page root was also considered. It would cover the page's other
focus-moving controls too (answer Save/Publish, goals), but those are outside #151. Per-region
handlers keep this change to the regions it covers.

**After a delete, focus goes to a heading, not the add input.** A text input would open the
on-screen keyboard on a phone after a tapped Remove or Confirm delete, and the add-outcome input is
disabled (so it can't take focus) while an add is running. A list entry uses its question block's
`<h4>`, the same `tabindex="-1"` target #149 uses for a comment thread that disappears. `AnswerField`
gets its own `onFocusLost` prop, which `AnswerBlock` wires to that heading. The field's own label
was tried first and rejected: every list field's label is the generic "Entries".

The next or previous row's buttons were also considered as the target after a delete, since they'd
keep a keyboard user's place when clearing several entries. The issue names the add input or the
heading. A counterpart's outcome rows have no buttons, so "the neighbour" would need a search that
skips them. The heading was kept.

**Whether the fallback scrolls depends on how the delete was triggered.** A heading, or #149's
thread toggle, can be far from the deleted row. A keyboard user needs it scrolled into view to see
where focus went (WCAG 2.4.11). A mouse or touch user shouldn't have the page jump, and the fallback
runs for them too, because Chromium blurs the disabled Confirm button to `<body>`. A button activated
by Enter or Space fires `click` with `detail === 0`, so `fallbackFocusOptions(click)` in
`keepFocus.ts` sets `preventScroll` only for pointer clicks. The options go through every fallback:
the outcomes heading, `CommentThread`'s toggle, and both `onFocusLost` props, which now take
`FocusOptions`. It's a heuristic. An assistive-technology "click" can carry `detail` 1, and then the
heading takes focus without scrolling into view. That's still better than `<body>`, where focus
went before.

**A double-click's second click never confirms a delete.** Delete swaps itself for Confirm
delete/Cancel on the first click, so the second click lands on whichever renders in Delete's place.
In English that's Cancel, but in a locale where "Confirm delete" is wider it could be Confirm
delete, deleting with no real confirmation. Both confirm handlers (outcomes and comments) ignore a
click with `detail > 1`. So does a list entry's Remove: after the first click the next entry's
Remove moves up under the cursor, and after a Cancel the entry's own Remove renders where Cancel
was. This predates #151 but sits in the handlers it changed. A double-click on Edit still lands
its second click on Save, which saves the unchanged text and closes the edit. Nothing is lost, so
it's left.

**Posting a comment keeps text typed while the post was in flight.** The input stays enabled
during the request, and clearing it unconditionally afterwards dropped whatever was typed
meanwhile. It's now cleared only if it still holds the posted text.

Keys typed while an outcome add is in flight hit `<body>`, because the add input is disabled. They
count as moving on, so the add input isn't refocused afterwards. The `aria-disabled` redesign
above would fix this too.

**Adding an outcome still returns focus to the add input, even after a tapped Add button.** That can
reopen the on-screen keyboard on a phone, including after a failed add, whose error is in the
page-level banner. It's accepted: another outcome, or a retry, is the likely next step,
and a keyboard user on the Add button needs somewhere to land, because the button stays disabled
once the input is cleared.

## Out of scope

A failed outcome add shows its error in the page-level banner, not inside the outcomes card where
focus returns. That placement predates this change.

The outcome edit input doesn't cancel on Escape, but a list-entry edit input does. That's a
keyboard-shortcut difference rather than focus loss, so it isn't part of this change.

`AnketaGoals.svelte` has no Edit/Delete swap, but its Save goal, Add checkpoint and Add goal
controls are disabled during their requests. Chromium blurs a focused control when it's
disabled, so these most likely drop focus as well. This is read from the code and wasn't
measured, and the issue doesn't cover it.

## Verification

- A new dual-actor e2e test, `outcome and list entry actions keep keyboard focus`, drives both
  tables from the keyboard. It fails an outcome add and an outcome delete through a routed 500. It
  holds an outcome save while focus moves to the add-outcome input, which must keep focus. It
  holds Enter on an outcome's and an entry's Edit (Playwright's repeated `keyboard.down` sets
  `repeat`). Once the edit is open, it types new text, sends the repeats, cancels, and expects the
  original text back. A repeat that got through would have saved the typed text. It doesn't drive a
  delete that succeeds with its row still mounted, or a pointer delete's no-scroll fallback
  (`fallbackFocusOptions()` is unit-tested instead).
- The #149 test gained the same held-Enter step for a comment's Edit.
- The #151 test dispatches a `detail: 2` click on an outcome's Confirm delete and on an entry's
  Remove, and checks nothing happens. It holds a comment post while typing into the input, and
  checks the typed text survives. Each fails without its fix.
- Five temporary breakages each made that test fail: `refocus()` returning right after `tick()`,
  outcome refocus scoped to the whole card (at the held-save assertion), Remove not refocusing,
  `AnswerBlock` not passing `onFocusLost` to `AnswerField` (at the heading assertion), and
  `ignoreHeldEnter()` doing nothing, which failed both tests. Removing only the list `<ul>`'s
  handler failed the list-entry step. Earlier versions of the held-Enter step passed without the
  filter: checking only focus after the key was released missed a save still in flight, and an
  entry's unchanged text saved and reopened unnoticed. Typing new text first closed both.
- Scoping comment refocus back to the whole thread made the #149 test fail at its held-save
  assertion, which now moves focus to the thread's own new-comment input.
- `keepFocus.test.ts` unit-tests the helpers under jsdom.
- The #149 e2e test still passes on the shared helper.
