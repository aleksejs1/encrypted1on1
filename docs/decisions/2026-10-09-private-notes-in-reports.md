# All my private notes in one list, in Reports

Closes [GitHub issue #243](https://github.com/aleksejs1/encrypted1on1/issues/243). Builds on
private notes ([#132](https://github.com/aleksejs1/encrypted1on1/issues/132),
[overview](2026-09-26-private-notes.md)).

## Problem

A private note is attached to one meeting and could be read only on that meeting's page. To
find a note without remembering its meeting, the only ways were opening meetings one by one or
reading the JSON export.

## Decision

- **A page of its own, `/report/notes`** (`pages/ReportNotes.svelte`), not a section of
  `/report`. The Report is generated for one person and a date range; the notes list is mine
  across every meeting and has other filters. The two pages share a tab strip
  (`report/ReportTabStrip.svelte`). `design/TabStrip.svelte` is the admin pages' tab strip made
  generic; `AdminTabStrip.svelte` now only supplies its tabs.
- **Frontend only.** `GET /api/me/private-notes` (the export's endpoint) gives every notes row
  of mine, and `openNotesForExport()` decrypts each one in the browser. The date and the
  colleague come from `GET /api/anketas`, the meeting list's own endpoint, not
  `/api/anketas/bulk`: the list needs no answers, comments or goals.
- **One entry per meeting with a non-empty note**, newest meeting first, open and archived
  alike. Read-only and as last saved; text still unsaved in another tab isn't shown.
- **Filter by colleague and search by text**, both in the browser
  (`report/privateNotesList.ts`). The search looks in the note's text, the colleague's name and
  email, and the meeting date as displayed. No date range filter: the list is already in date
  order and the date is searchable.
- **A note's text is not in the page until asked for.** See the next section.
- **A note that can't be opened is listed**, with its meeting and the panel's "can't be opened"
  message, at its place in the order. It has no "Show note".
- **An emptied note that can't be opened is still left out.** A note typed and then cleared
  stays on the server as an encrypted empty text. After a password reset it can't be opened, and
  the list would show a "can't be opened" card for a note that holds nothing. The blob isn't
  padded, so its length gives it away (`isEmptyNotesBlob()` in `crypto/privateNotes.ts`), and
  such a row is treated as empty. A note of only spaces isn't recognized this way and is listed
  as unreadable. The data export is unchanged and still marks such a row `unreadable`.
- **Deleted colleagues share one filter option**, "Deleted user". Their names are gone, so
  separate options would read the same. Their placeholder addresses are never shown or
  searched.
- **A note whose meeting isn't in my list** goes last, with its text, no date or colleague, and
  no link (it would lead to "not found"). It's listed only under "Everyone". The foreign key
  from a note to its meeting makes this unreachable today; the page handles it so that a note
  is never dropped silently.
- **No collapsing of long notes.** Nothing is shown until asked for, so a long note pushes
  nothing off the screen by itself, and a second "Show more" inside "Show note" would be two
  clicks to read one note.

## Screen sharing

The meeting page shows one meeting's notes, and "Hide notes" takes them out of the page. This
page could show every note about everyone at once, so it's stricter:

- The page always opens with no note's text in the DOM. A card has the date, the colleague, a
  link to the meeting and "Show note".
- "Show note" adds that one note's text. "Show all notes" adds the listed notes, and "Hide all
  notes" removes them.
- A note that the colleague filter or the search takes out of the list is hidden again. So the
  shown notes are always among the listed ones, and no text comes back by itself when a filter
  is changed later, for example after a screen share has started. Review found both halves of
  this: the first version's "Show all notes" added every note, listed or not, and a note shown
  one by one stayed shown while filtered out.
- Which notes are shown is page state only, never stored, so a reload hides everything again.
- The search field has `autocomplete="off"`, so the browser doesn't offer earlier search words.
- Searching never shows text. A blur or a reveal on hover was rejected: the text would stay in
  the DOM, and moving the pointer during a call would show it.

What this doesn't hide:

- **Search results show which notes have the word.** Typing a word while sharing a screen
  narrows the list to the meetings whose notes contain it, with dates and names. Accepted:
  search over the notes' text is the point of the page.
- **The text is in the browser's memory** from the moment the page loads, since search needs
  it. Hiding is about what's on the screen and in the DOM.

## The issue's open questions

| Question | Answer |
| --- | --- |
| Where it lives | Its own route, `/report/notes`, as a second tab of Reports |
| Search by text | In scope |
| Date range filter | No |
| Unreadable notes | Listed, marked as can't be opened |
| Notes whose meeting isn't in my list | Listed last, with what is known |
| Long notes | Shown in full once shown |
| Loading | Everything is fetched and decrypted on open, one note after another, as the export does. Not measured with hundreds of notes |
| Privacy on screen | Text only after a click |

## Not done

- Match highlighting. In a long note the word still has to be found by eye (or the browser's
  own find, once the note is shown).
- The filter and the search aren't kept across "Open 1:1" and Back. Putting the search words
  in the URL would leave them in the browser's history.
- The list is read once, when the page opens. Going straight from a meeting's page to this one
  can read the list before that page's last save of the notes has landed; a reload shows it.
- If the meeting list fails to load, the page shows an error, not the notes without their
  meetings.
- A deleted colleague's label is worked out here a third time, beside the meeting list and the
  notes panel. One shared helper would touch both of those and was left for its own change.
- A tab whose session was ended elsewhere keeps showing what it has shown, like the Report.
- Editing from this page, and Markdown in notes (plain text everywhere).
- The six locales' new strings were written by Claude and haven't had a native-speaker review.

## Verification

- `report/privateNotesList.test.ts`: the join, the order and its tie-break, empty and
  unreadable notes, an unknown meeting, deleted colleagues, the colleague filter and the search.
- `e2e/report-private-notes.spec.ts`, against the real stack with real crypto: notes written on
  two meetings' pages are listed with no text in the page; Show, Hide, Show all, the colleague
  filter, the search and a reload; and after a password reset the note is listed as unreadable.
