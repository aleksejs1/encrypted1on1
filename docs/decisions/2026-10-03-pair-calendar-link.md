# A permanent per-pair link for calendar events

Closes [GitHub issue #203](https://github.com/aleksejs1/encrypted1on1/issues/203), part of the
product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)).

## Problem

A pair's 1:1 usually already exists as a recurring calendar event, and nothing in that event
points here. A link to one meeting (`/anketas/{id}`) is no use in a recurring event: each cycle
is a different meeting with a different id, so the link is stale after the first archive.

## Decision

- **`/pair/{userIdA}/{userIdB}` is the pair's permanent link**, the same for both people
  (`pairPath()` sorts the two ids). `pages/PairMeeting.svelte` takes the id that isn't the
  caller's as the counterpart (`pairCounterpartId()`), loads the caller's own meeting list
  (`GET /api/anketas`, which the list page already uses) and picks the meeting with
  `pairMeeting()` in `frontend/src/anketa/pairChain.ts`. Frontend only: no new endpoint, no
  schema change.
- **The pair's open chain meeting wins; else the last closed one.** `pairMeeting()` is a thin
  wrapper over the existing `pairChainState()`, so the link agrees with what the create form and
  the server (`AnketaRepository::findOpenForPair()`/`findMostRecentArchivedForPair()`) call the
  pair's chain. One-offs are never the link's target.
- **An open meeting is a redirect** that replaces the link's history entry, so Back doesn't
  bounce forward again.
- **No open meeting is a page, not a redirect**: "No open 1:1 with [name]", the last meeting's
  date, "Schedule the next one" and "Open the last 1:1". "Schedule the next one" opens the
  create form with the colleague preselected (`startCreateWith()`/`takeCreateWith()` in
  `createDefaults.ts`, an in-memory hand-over like "Create another"; `UserTypeahead` now shows
  a value set from outside). A deleted colleague gets no "Schedule" action.
- **No chain meeting behind the link is one message**, "No 1:1 behind this link". A pair the
  caller isn't part of, an unknown user and a colleague with no shared meetings are
  indistinguishable without a new API, and the message covers all three.
- **"Calendar link" on the meeting page** (`AnketaHeader.svelte`) copies the URL and shows the
  hint. It reuses the template share link's `copyToClipboard()` and `CopyableLink` (the link in
  a field to copy by hand where the clipboard is unavailable). Hidden for a deleted colleague.
  Shown on a one-off too: the link is the pair's, not the meeting's.

## The issue's open questions

- **URL format**: `/pair/{userIdA}/{userIdB}`, not the proposed `/with/{counterpartUserId}`.
  The proposed form was built first; it names only the counterpart, so each participant had
  their own link and the other one's link led nowhere. A recurring 1:1 is normally one shared
  calendar event with one description, so the link has to work for both. Naming both people
  does that without a pair id the data model doesn't have.
- **A pair with chains in both role directions**: there is no such thing. The server matches a
  pair as unordered (`chainAnketasForPair()`), so a pair has one chain whichever of the two
  leads it, and the link ignores `myRole`.

## What the link reveals

The URL holds the instance's host and the two people's user ids (random UUIDs), nothing else.
Pasted into a calendar event, the calendar provider learns that much. The ids are not secrets
(API responses to anyone in the company carry them) and grant nothing: the page behind it needs a
login and an unlocked tab, and only ever reads the caller's own list.

## Not done

- A click from the calendar still lands on the login or unlock screen first (until #205). Both
  keep the path, so the link resumes after the password.
- A pair whose chain is closed but who still have an open one-off see "No open 1:1 with
  [name]" with no pointer to the one-off. Rare: it needs a one-off made while the chain was
  open, and the chain then ended without a next meeting.
- A colleague preselected by "Schedule the next one" replaces text typed into the colleague
  field before the form's first load finishes.
- With a forked chain from before #111 (several open chain meetings), the earliest meeting
  wins, the same rule as the server's.

## Verification

Unit tests for `pairMeeting()` (open over closed, most recent closed, the next cycle after an
archive, one-offs ignored, unknown user), `pairCounterpartId()`, the route, and the create-form hand-over. A
dual-actor Playwright test (`frontend/e2e/dual-actor-anketa.spec.ts`) against the real e2e
stack copies the link from the meeting page, follows it to the open meeting as each participant,
archives, follows
it to the successor, ends the chain, and schedules the next one from the link's page.
