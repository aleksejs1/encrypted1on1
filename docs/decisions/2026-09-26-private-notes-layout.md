# Private notes: the two-column layout

## Problem

[GitHub issue #138](https://github.com/aleksejs1/encrypted1on1/issues/138) is part 3 of the
[#132](https://github.com/aleksejs1/encrypted1on1/issues/132) private-notes design. Part 2 (#137,
`2026-09-26-private-notes-panel.md`) shipped the notes panel as a card under the anketa header at
every width. That means scrolling away from the question being discussed to write a note. The
design's §6.1 answers this on wider screens: a sticky second column beside the form.

## Decision

- **Wide (≥ 52.5em, 840px at the default font size):** `Anketa.svelte`'s `<main>` becomes a
  grid, `minmax(30rem, 46rem - 48px)` for the form and `minmax(16rem, 22rem)` for the notes, with
  a 24px gap, and at most the design's `68rem + 24px` wide.
  - The notes `<aside>` spans every row and is `position: sticky; top: 16px`.
  - It takes most of the viewport's height: `100dvh - 160px`, at least 24rem and at most
    `100dvh - 32px`. The textarea fills what the heading, subtitle and status leave, and
    scrolls on its own.
  - Not the design's `100dvh - 32px`. That fits only once the panel sticks. At the top of the
    page it starts below the app header and `<main>`'s padding, about 130px when the header
    wraps, and its bottom (the status line, Retry, the footer) would be below the fold. The
    e2e test checks the footer is fully in view both at the top and at the bottom of the page.
    In a window under about 544px tall, the 24rem floor wins, and the bottom is in view only
    once the panel sticks.
  - The rows are `auto 1fr`. The content's row absorbs any height the column needs beyond the
    content, so a short page never stretches the header's row.
- **Hidden, wide:** the column shrinks to a rail, the eye-slash icon above "Show notes". The form
  keeps its width, and the grid is centered. `Anketa.svelte` owns the hidden state and binds it
  into the panel, which still reads and saves it (`readNotesPanelHidden()`). So the columns don't
  change while no panel is mounted, between anketas, which would reflow the whole form twice on
  each navigation. The rest of the heading and the subtitle are
  `display: none` there. The heading still labels the `<aside>`.
- **Narrow (< 840px):** the #137 card under the header, unchanged. The e2e test checks the card
  sits above my side and is as wide as the form.

## Deviations from the design

- **840px, not 820px.** The design assumed a 16px page gutter, but `<main>` has 24px. The grid's
  minimum is then 30rem + 16rem + 24px + 48px = 808px, and with a classic 17px scrollbar it
  needs 825px. At 840px nothing overflows.
- **In em, not px.** The columns are in rem, so a larger browser font widens the grid's minimum,
  to 992px at a 20px font. A px breakpoint would then turn the grid on too early and scroll the
  page sideways. Media queries resolve em against the same browser font size, so `52.5em` grows
  with the columns. Review caught it.
- **Two wrappers, not one.** §6.1 moves the page's content into one left wrapper. The narrow card
  has to stay between the header and the rest, so the header gets its own `.anketa-top` wrapper,
  and `.anketa-main` holds the rest. Both keep `<main>`'s column and 20px gap, so the page below
  the breakpoint looks as before.
- **The form's maximum is `46rem - 48px`, not 46rem.** The old `max-width: 46rem` included
  `<main>`'s 48px of padding (`border-box`), so the form was 46rem − 48px wide. This keeps it
  that wide, and the design's `68rem + 24px` total then fits both columns at their maximum.

## Alternatives considered

- **`:has(.private-notes.is-hidden)` on `<main>`,** with the panel owning its hidden state. It
  stops matching while no panel is mounted between anketas, so the columns jumped on each
  navigation. Review caught it, and the page now owns the state.
- **Moving the panel in the DOM** between the two layouts. That would remount the panel, and a
  remount is a save and a backup handover (#137). One instance stays in one place, and only CSS
  changes.

## Verification

- e2e 7 of the design (`frontend/e2e/private-notes.spec.ts`), against the real stack:
  - at 1440px and 900px the panel is beside the form, with no horizontal overflow, and its text
    is still in view after scrolling to the bottom;
  - at 700px it's the top card;
  - Hide takes the text out of the page in both layouts, and the wide one becomes a rail
    narrower than 200px.
- Screenshots at 1440px, 1000px and 700px, light and dark, shown, hidden and scrolled, for the
  maintainer's review before merge, as the issue requires.
- The existing e2e suite and unit tests are green.
