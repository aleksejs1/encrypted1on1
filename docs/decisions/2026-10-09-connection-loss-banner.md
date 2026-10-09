# A banner while the connection is lost, and a retry when it's back

Closes [GitHub issue #242](https://github.com/aleksejs1/encrypted1on1/issues/242).

## Problem

When the connection dropped, the app said nothing. On a meeting page the live-update poll
stopped at its first failed tick, the page looked as it does online, and the user found out
from a failed save, or not at all. During a 1:1 in a meeting room with weak Wi-Fi there was no
way to tell whether what was being typed was safe.

## Decision

Frontend only: no endpoint, schema or encryption change.

- **Scope: a banner with honest text, plus retrying what is already kept locally.** Queueing
  every write for replay (comments, topics, ticks, publish) was the issue's other option and is
  not done: it is a conflict-resolution design over every versioned blob, not a banner.
- **The text depends on the page.** Only a meeting page keeps anything locally (unpublished
  answers and private notes, both encrypted in `sessionStorage`), so only it says so:
 "Unpublished answers and private notes in this tab are kept locally; other actions will work
  once back online." "Unpublished answers", not "drafts": an edit of already published answers
  has no backup, and mustn't be read as one. Every other page, the create form under `/anketas/new` included, gets "Actions will
  work again once back online." The template editor keeps its unsaved changes in memory only,
  and the first text would be false there.
- **One message for "you are offline" and "the server can't be reached".** The user does the
  same thing in both cases, and on a flaky connection the two alternate.
- **Buttons stay enabled** and fail with the error they already show. Detection can lag, and a
  click is often the request that proves the connection is back.
- **No poll on other pages.** While offline, and only then, every page probes `GET /health`.

## How "offline" is decided

One status, `online | offline | reconnected`, in `connectivity/connectionState.svelte.ts`.
`api/client.ts` reports every request to it through one wrapper around `fetch`.

- **Offline:** a request that got no answer from the app. That is a network error, a timeout
  (`TimeoutError` from an `AbortSignal.timeout()`), a 502 or 504, or any status from 502 up that
  isn't JSON (a proxy's 503 page, a CDN's 520–530): with a reverse proxy in front (the default
  deployment), a backend restarting during a deploy looks like that. The browser's `offline` event counts too. A caller's own abort doesn't.
- **Not offline:** any status of the app's own, a 401 or a 500 included, and its two JSON 503s
  (`BillingController`, `ActivationController`).
- **Neither:** a response that isn't JSON and isn't a gateway error (a captive portal's login
  page, a proxy's plain 404 while the container is recreated). Every answer of the app's is
  JSON; such a page proves nothing either way (`answeredBy()` in `api/client.ts`).
- **Back:** an answer from the app to a request sent after the connection was lost, or a probe
  answered with 200 and `{"status":"ok"}`. The browser's `online` event and `navigator.onLine`
  never count: they only say a network interface is up. The event prompts a probe;
  `navigator.onLine` isn't read at all, and a page always starts online: it was just served,
  and some systems report no network with a working one.
- **"Back online" shows for 3 seconds.** A failure during them returns to offline at once and
  cancels the timer.

The probe first runs 5 seconds after the loss, then every 10 seconds (±1), pauses in a hidden
tab, and runs at once when the tab is visible again or the browser reports `online`.

## What happens on reconnect

`onReconnect()` listeners run once per `offline → reconnected`:

- **The live-update poll starts again** (`Anketa.svelte`). It still stops at its first failed
  tick; before this it waited for a focus or visibility event to try again.
- **An unsaved draft is saved** without waiting for the next keystroke. "Unsaved" is the
  answers' fingerprint (`answersFingerprint()`, as for published answers in #166) against
  what the server is known to have: the draft it sent on load, or the last save it confirmed,
  and nothing at all while a save is in flight or after one failed (its request may have
  arrived). See "Draft saves" below.
- **Private notes save again** (`NotesSession.reconnected()`), also after their own 2s/5s/15s retries
  ran out, which takes 22 seconds: less than an ordinary Wi-Fi drop. A failed load is retried.
  A save still in flight at the reconnect (one that hung through the outage) is retried when
  it fails; a first load that hung isn't, and ends at its Retry button.

## Draft saves

Retrying a draft by itself needed the save path to be exact about what the server has, which
it wasn't. Changed in `Anketa.svelte`:

- **One save at a time**, with the timeout the private notes use (`api/requestTimeout.ts`, 20
  seconds plus an allowance for the body's size). Saves used to overlap, so one that hung
  could reach the server after a newer one. A save that comes due while another is in flight
  runs when that one ends (`draftSave`, one value over idle / in flight / in flight then
  again).
- **A publish and the draft.** A draft save that comes due during a publish is dropped: the
  publish sends the same answers. A publish that fails, other than with a 409 (which refuses
  a draft too), then saves the draft if the server doesn't have it; before, the save the
  publish had cancelled stayed unsent until the next edit.
- **A save that would send what the server already has is skipped.** That also removes the
  duplicate when the draft's own save is the request that brought the connection back. "The
  same" is by fingerprint, which ignores blank values and the order of ticked options.
- **An unreadable draft with nothing typed over it is never replaced** by the reconnect retry,
  the rule the autosave already had (#129).
- **A local backup restored on load counts as unsaved**, since the server's draft is the
  baseline. It was already sent on load in the common case (the counterpart not published
  yet, by the order `load()`'s awaits happen to run in) and on the first keystroke otherwise;
  now a reconnect sends it too. The risk is the existing one: the backup is cleared only on
  publish, so a tab's old backup can replace a newer draft saved from another browser. The
  same goes for a tab whose last save failed and that reconnects (a laptop waking up) after
  the draft was continued elsewhere: drafts carry no version, and this tab's text wins without
  a keystroke where it used to need one.

## Details worth knowing

- **A hung request is as common as a failed one** (a dead VPN, a captive portal), and `fetch`
  has no timeout. Both requests of a poll tick get 10 seconds; without that a hung tick holds
  `pollInFlight` and no later tick runs. Other requests have no timeout: on a meeting page the
  poll is the detector, on other pages a hang is noticed only by the user.
- **A request's outcome counts only if nothing changed since it was sent.** Found by the e2e
  test: the `offline` event arrived, then the response to a request already on its way, and the
  banner said "Back online" with no network. The other direction is the same: a request that
  hung through a whole outage times out after the reconnect, and mustn't bring the banner
  back. Each request remembers `connectionEpoch()` (bumped on every loss and every reconnect),
  and `recordOnline()`/`recordOffline()` ignore an older one.
- **The body is tracked too** (`readJson()` in `api/client.ts`): it arrives after the headers,
  and a request's timeout covers it. Invalid JSON is an answer, not a lost connection.
- **On reconnect the poll restarts from a clean interval**, and a failed tick stops only the
  interval it started under. Otherwise a tick that hung before the loss would keep the old
  interval set through the reconnect, then stop the poll when it finally timed out.
- **A reconnect listener that throws is logged**, and neither fails the request that brought
  the connection back nor keeps the other listeners from running.
- **A probe's answer is ignored once the probe was replaced or cancelled** (`probeToken`), so a
  slow answer from an earlier offline period can't end the current one.
- **The banner is fixed to the top of the viewport**, not placed under the app header, which
  scrolls away: someone typing far down a meeting has to see it. Its measured height is
  `--connection-banner-offset`, which pads the page (so the header isn't covered) and moves
  down what sticks to the top: the answers edit bar, the notes column and its height, and
  `scroll-padding-top` (on every page, so a scroll to the archive form doesn't land under it). The height is measured, not fixed: the text is several lines
  on a phone.
- **The page moves down by the banner's height when it appears.** Accepted: the alternative is
  a banner over the header and its Log out link. The browser's scroll anchoring keeps the
  viewport in place for a scrolled page where it is supported.
- **The live region is always in the DOM**, empty while online and never `display: none`: a
  region added together with its text is often not announced.
## Accepted limitations

- Comments, topics, "discussed" ticks, outcomes, checkpoints, edits to published answers,
  publish, archive and reschedule are not retried. They fail with their own error, as before.
- A deployment whose own proxy doesn't forward `/health` to the backend never sees the probe
  succeed: the banner then clears only on the next answered request (`docs/deployment.md`).
- The browser's `offline` event is trusted even when the server is still reachable (an
  instance on localhost or another interface): the banner shows until the first probe.
- Firefox fails the requests in flight when a page is left, as a network error: "Connection
  lost" can flash on the page being left. A page restored from the back/forward cache probes
  at once.
- Only the poll and the draft and notes saves have a timeout. Any other request into a dead
  connection hangs as before; on a meeting page the poll notices, elsewhere only the user.
- A page whose first load failed isn't loaded again on reconnect, on any page: it shows its
  load error under "Back online" until it's reloaded. Only the private notes retry their load.
- One failed request among several sent together shows the banner until the first probe (5
  seconds), even though the others were answered: they were sent before the loss, and the
  rule above can't tell them from answers that were already on the wire. The same after a
  laptop wakes with a poll tick in flight.
- A 502 or 504 for one slow endpoint, with everything else working, shows the banner until the
  first probe answers, about 5 seconds.
- A server that takes more than 10 seconds to answer the poll reads as a lost connection, and
  if `/health` still answers quickly the banner alternates. Such a server is unusable for a
  live meeting anyway. The same alternation, with the reconnect retries each time, happens
  behind a proxy that answers `/health` while failing `/api/*` with 502 or 504: there is no
  backoff, and each round is lighter than the poll it replaces.
- A draft save refused for a reason that isn't the network is sent again on each reconnect
  (an archived meeting is skipped).
- A proxy or CDN that answers for a dead backend with JSON, at a status other than 502 or 504,
  reads as the app.
- The text is chosen by the URL alone, so a meeting's URL showing the login or unlock form gets
  the meeting text. It is still true there: the backups are in the tab.
- The poll still stops on a 500 or a 401 and waits for focus, as before: neither is a lost
  connection.
- All 6 locales' strings were written by Claude and are not native-speaker-reviewed.

## Verification

Unit tests for every transition, the probe's timing and its rejected answers
(`connectionState.test.ts`), and for what `api/client.ts` reports (`client.test.ts`).
`frontend/e2e/connection-banner.spec.ts` runs against the real stack with real crypto: the
server cut off with the network up, a draft and a note typed meanwhile, the notes' retries run
out, a topic added by the counterpart; on reconnect, with nothing typed or clicked, the draft
and the note reach the server (read back in a fresh browser with no local backup) and the topic
appears. Also a proxy's 502 page, including that the probe doesn't read it as being back, and
the browser's own offline mode on the list and the create form.
