# Private notes: the panel

## Problem

[GitHub issue #137](https://github.com/aleksejs1/encrypted1on1/issues/137) is part 2 of the
[#132](https://github.com/aleksejs1/encrypted1on1/issues/132) private-notes design. It covers the
client crypto, the panel, autosave with a tab-local backup, and conflict handling, building on
the storage and API from #136 (`2026-09-26-private-notes-storage-and-api.md`). Notes are only
worth having if they're never lost and never readable by anyone but their author. That holds
across two tabs, a logout, a lost response, a password reset, in-app navigation and a closed tab.

## Decision

Implemented as designed in #132 §4, §6.2–§6.5, in five pieces:

- **Crypto** (`crypto/privateNotes.ts`). A random notes key is wrapped in an authenticated
  `crypto_box_easy` from the author's keypair to itself. A key sealed by anyone else fails to
  open and is shown as unreadable, never adopted. The notes blob is bound to its anketa and author
  through AEAD associated data. For that, `encryptBlob`/`decryptBlob` gained an optional
  `associatedData` parameter. Existing callers pass none and are unchanged.
- **State machine** (`anketa/notesState.ts`). A pure reducer over one status enum (`loading`,
  `loadError`, `unreadable`, `idle`, `dirty`, `saving`, `retrying`, `conflict`, `stopped`)
  implements every transition in §6.3, the §6.4 reconciliation and the §6.5 resolutions. It
  also exports `keepBoth()`.
- **Controller** (`anketa/notesSession.ts`). It performs the requests and feeds their outcomes
  back to the reducer.
  - **Save triggers:** a 1 s debounce while typing, blur, `visibilitychange` to hidden, a
    `keepalive` save on `pagehide`, and destroy. A save that finishes after the text has moved
    on is followed straight away only if a trigger arrived meanwhile. Otherwise the pending
    debounce decides, so steady typing saves about once a second, the timing the design's
    threat model states.
  - **Retries:** after 2s, 5s and 15s, then a manual Retry. While retrying, only the retry
    timer, the manual Retry, a hidden tab, `pagehide` and destroy send; typing and blur leave
    the backoff alone.
  - **Logout:** it checks the identity generation, so it never sends after a logout.
  - **Unload:** only `pagehide` saves with `keepalive`, because the page is going. Every other
    request is a plain one, since `keepalive` requests share a small per-page quota. On
    `pagehide` during a save, the request in flight is therefore a plain one, cancelled with the
    page, so the latest text goes out once more as `keepalive`, against the same version. If the
    page survives in the back-forward cache, the text is saved again once the earlier request
    finishes. Beforeunload listeners are attached only while something is unsaved, because
    Firefox keeps no page with one in its back-forward cache.
  - **Timeouts:** every request, `keepalive` ones included, is abandoned after 20 s plus 0.2 s per
    KB of body (a GET allows for a full-size row), and counts as a network failure. A stalled
    connection can't leave the panel "Saving…" for good, not even after a back-forward-cache
    restore, and a near-cap save on a slow link isn't cut off. Whether it landed is then unknown,
    which the sent-text tracking already covers.
  - **Sent texts:** a re-sent text is recorded once, moved to the newest position. The newest is
    always kept, and older ones, newest first, only while they total under 200,000 characters
    (an oversized one is skipped, not a stop). Each is a full copy that goes into the backup on
    every keystroke, so a long outage near the cap can't push the backup toward the storage quota.
    An older text landing late then shows as a conflict, which "Keep both" resolves without loss.
  - **Unload warning:** a live panel warns on `beforeunload` while anything is unsaved. Closing
    the tab also warns while the logged-in user (or, logged out, anyone) has a notes backup left
    in it (`anketa/notesUnloadWarning.ts`, below).
- **Backup** (`anketa/notesBackup.ts`). It lives in `sessionStorage`, written on every change
  that matters, one write at a time with the latest state last.
- **Panel** (`anketa/PrivateNotes.svelte`). It's the top card under the anketa header at every
  width. #138 adds the second column.
  - **Mounting:** Anketa.svelte renders it as `{#key id}`, and only once the page's `detail` is
    this anketa's.
  - **Privacy cues:** an eye-slash icon, not the shared sections' lock; an "Only you" tag; a
    subtitle naming the counterpart, which is replaced on the demo account; and its own surface.
  - **Hide:** it removes the text from the page entirely and is remembered in `localStorage`.
  - **Screen readers:** only errors and stopped states are announced.
  - **Conflicts:** a banner with `role="alert"` offers "Keep both", "Keep this tab's text" and
    "Load saved version" (after a confirm step).
  - **Warnings:** the footer warns that notes are lost on a password reset. The reset page's
    warning gained one sentence about private notes.
- **i18n.** 33 `privateNotes.*` keys were added in all six locales, in each locale's existing
  register. The non-English strings were written by Claude and need a native speaker's review,
  especially the privacy sentences, which make a security claim.

### Where the implementation goes beyond or differs from the design

Most of these came out of eight review rounds on the controller's concurrency.

- **The backup key is derived from the private key, not the master key.** The design encrypts
  the backup under the session master key, which is derived from the password. But a panel
  destroyed with a failed save leaves its text only in the backup. An in-app password change
  then made that backup unopenable, and the text was lost without notice. The key is now derived
  from the private key (`deriveNotesBackupKey`, its own `crypto_kdf` context), like the drafts key
  since #129. It's the same after logging back in, unchanged by a password change, and available
  before the first save.
- **A destroyed panel makes one last save and nothing more.** The design has a destroyed panel
  keep its whole save chain running, listeners included, and a new panel for the same anketa
  await all of it. Each review round found another way that lost text or left stale state: a new
  panel stuck behind a retry schedule, an old panel's late 200 clearing the new panel's backup, an
  orphaned warning, or a stray keepalive PUT. The simpler shape:
  - on destroy, a panel removes all its listeners and saves its pending text once. It never
    retries;
  - the one backup per anketa has one owner. A new panel for the same anketa waits for a
    destroyed panel's last save, at most 5 seconds. It then takes the backup over before reading
    it. From then on the old panel never writes or clears it, and ignores whatever its late
    request brings back. A write that has been encrypted but not yet stored is dropped too;
  - reconciliation carries the backup's sent texts over, whether or not a row exists yet. If the
    text equals the server's but another sent text may still land, it saves again. A stale save
    then fails its version check, or, if it landed first, reads as this tab's own and is
    overwritten;
  - the unload warning for text a destroyed panel left unsaved comes from the backups themselves
    (`anketa/notesUnloadWarning.ts`). Every unsaved state keeps a backup and idle clears it, so
    the logged-in user having any notes backup in the tab means there is unsaved text. It's
    read from `sessionStorage` when the tab closes, holds no text, and is per user, like the
    backups.
    - It's armed from the app, not only from panels: `App.svelte` re-evaluates it whenever the
      logged-in user changes (startup, unlock, logout, login), and panels whenever their backup
      changes. So it holds after a refresh, on any page, and after logging out and back in as
      the same user, and it never fires for another logged-in user.
    - Logged out, any notes backup in the tab warns. There's no other user to protect, and a
      logout with unsaved notes sends no last save (the session has ended), so the text is only
      in the backup.
    - The user id it checks (`loggedInUserId()`, now reactive) is untouched by a routine identity
      cache-bust. Only a logout clears it.
    - A backup that can't be opened (under the keypair before a forgotten-password reset) is
      removed, on read and in a sweep of the user's backups on unlock (`App.svelte`). The sweep
      matters because the anketa page that would read it may not open until the counterpart
      re-shares. So it can't keep prompting about text nobody can recover.
    - Two earlier designs were rejected in review: a per-panel listener kept after destroy, and an
      in-memory registry. Both lost the warning on a refresh, on fast navigation, or when the other
      participant opened the same anketa in the tab;
  - detached panels are tracked per user and anketa, like the backups;
  - nothing references a destroyed panel once its last request settles, so its keys and text go
    with it. A logout with nothing unsaved leaves it idle rather than `stopped`.
- **The footer doesn't link to the export yet.** The design's footer says "Export keeps a copy."
  The export only starts including private notes in #139, so until then that would promise a copy
  that doesn't exist. #139 adds the sentence and the link.
- **A conflict doesn't advance the acknowledged version until it's resolved.** The backup records
  `baseVersion = ackVersion`. If `ackVersion` moved to the server's version on entering the
  conflict, a reload during it would find `baseVersion === V` with different text. It would then
  silently overwrite the other device's text instead of showing the banner again. The server's
  version is held separately and adopted on resolution.
- **"Saved" means nothing this tab sent is unaccounted for.** A save whose response was lost may
  have landed. The model settles to idle only when the text equals the acknowledged text *and* no
  sent text is pending. Otherwise it stays dirty and saves again, or a deleted line could reappear
  after a reload while the panel says "Saved". The same rule keeps carried-over sent texts through
  a conflict: after "Load saved version", the loaded text is saved once more, over anything of
  this tab's still in flight.
- **An unreadable row with restorable backup text counts as unsaved**, so closing the tab warns
  before dropping text that "Start new notes" would have restored. A logout leaves such a panel
  unreadable, rather than stopping it, which would show an empty textarea whose first keystroke
  overwrites the restorable backup.
- **A second null 409 in a row backs off**, as a network failure, instead of re-inserting in a
  tight loop.
- **A save that is too large is recognized from a 400, not a 422.** The #136 record explains why.
  A 400 whose violations include `notesBlob` is `stopped(tooLarge)`, and any other is
  `stopped(invalid)`.
- **After a password reset, the notes aren't reachable until the counterpart re-shares the anketa
  key.** The anketa page as a whole can't open without the anketa key, and the panel lives on it.
  The design's e2e 6 assumed it could. The notes themselves stay unreadable whatever the
  counterpart does, as designed.
- **The light-theme panel surface is a step lighter than the cards, not darker.** The design's
  "text at about 4% over the surface" put `.banner-error` under 4.5:1 in the light theme, which
  `contrast.test.ts` caught. The light theme uses the midpoint of the page background and the card
  surface. The dark theme uses the design's 4%. Both are solid tokens checked by the contrast test.
- **`AnketaDetail` declares `counterpartDeleted`**, which the backend served all along.
- **The CSRF token cache can't be refilled across a logout.** The panel warms the token on load
  (§6.3), which made two pre-existing gaps in `getCsrfToken()` much more likely to be hit:
  - a token fetch started before a logout could land after `resetCsrfToken()` and cache the old
    session's token, so logging back in got a 403. A token fetched across a reset is now dropped;
  - an error response was read as a token and sent as `X-CSRF-Token: undefined`. The resulting
    403 looked like an ended session and stopped saving for good. An error response now fails
    the request, and the notes panel retries it.

### Known limits

- **A logout sends no last save.** Text typed within the debounce before an in-app logout, or
  left by a failing save, stays only in the backup. Closing the tab then warns, and logging back
  in as the same user restores and saves it. Flushing the notes before the logout request would
  need `auth.svelte.ts` to know about notes panels, so it wasn't done.
- **A backup for an anketa the user can no longer open** (403/404) keeps the unload warning on,
  because no panel can load it. Access is rarely lost, and the backup is gone when the tab closes.

- **A mobile page frozen or killed right after being hidden.** A hidden tab saves with a plain
  request (see below). If the OS freezes and then kills the page before that save lands, and
  before any `pagehide`, the text survives only in `sessionStorage`. That's gone when the tab is
  killed, but not when Chrome discards and later restores it.
- **Keystrokes typed during the last save's round trip, on a forced close.** On `pagehide` during
  a save, the latest text is re-sent against the same version. If the save in flight landed
  first, the re-send gets a 409, and whatever was typed during that round trip is lost.
  `beforeunload` warns in that state. Sending against the next version as well was tried and
  rejected in review: it blindly overwrites whatever else took that version, another device's
  save included, which is worse.

## Alternatives considered

- **One unload mechanism instead of two.** A live panel's own `beforeunload` listener and the
  backup-based one guard the same condition. The panel's listener stays because it covers the
  moment before its first backup write lands, which is asynchronous (encryption).

- **Sharing one nonce‖ciphertext packing helper across the crypto layer.** The notes key wrap
  packs its nonce the same way `encryptBlob` and the private-key wrap do, each by hand. A shared
  helper would touch the existing drafts, answers and key code for no functional change. Left for
  a separate cleanup.
- **`keepalive` for the hidden-tab save.** It was tried in review round 5 and taken out in round
  6. A switch to the video-call tab happens constantly during a meeting, and `keepalive`
  requests share a ~64 KB per-page quota, so a later `pagehide` re-send could be rejected.

- **Keeping the design's long-running detached chains**, with ever more handover rules. Rejected
  after review, for the reasons above.
- **Debouncing backup writes.** Near the cap, each keystroke encrypts and stores about 350 KB.
  That's a few milliseconds, so the writes are coalesced but not debounced: a debounce would
  leave the last keystrokes out of the backup when the page goes.
- **Generating the notes key only when there's no row** is done. The spare box per load was cheap,
  but pointless.
- **Seeding demo accounts into the e2e stack to test the demo subtitle.** The subtitle choice is a
  pure function (`notesSubtitleKey`) with a unit test instead. The design allows either.

## Verification

- **Unit tests:**
  - `notesSession.test.ts` covers the controller with the API, crypto and backup mocked, and fake
    timers:
    - one save a second under steady typing;
    - no sending during a backoff;
    - the null-409 backoff;
    - the generation guard;
    - a save on destroy, with the next panel waiting for it;
    - no retries after destroy;
    - the unload warning while a destroyed panel's text is unsaved, including while its last save
      is in flight, when the next panel fails to load, and behind an older panel's hung request.
      It survives logging out and back in as the same user, and never fires for another user;
    - an old panel's late 200 after the handover leaving the new panel's backup alone;
    - no warning left after logging out with everything saved.

    It also covers a single keepalive re-send on `pagehide` over a plain save in flight, and a
    plain, timed-out save for a hidden tab. Four mutations each failed their tests: always
    following up after a 200, dropping the retrying-trigger gate, retrying after destroy, and
    dropping the backup ownership guard.
  - `privateNotes.test.ts`:
    - the wrap round-trip, and the 96-character key the server accepts;
    - rejecting a box from another keypair, or an anonymously sealed key;
    - associated data stopping a blob moved to another anketa or user;
    - `encryptBlob` without associated data unchanged;
    - `encodedNotesLength` matching real output for Cyrillic, emoji and escapes;
    - the backup key stable per keypair and distinct from the drafts key.
  - `notesState.test.ts`: every §6.3 transition; every §6.4 reconciliation row, including the
    deletion-after-a-lost-save case and a matching text with another sent text pending; the §6.5
    resolutions; `keepBoth`; that `sessionEnded` never retries; and the subtitle choice.
  - `notesBackup.test.ts`: the round-trip; ciphertext only; never reading another user's key;
    ignoring an unopenable or malformed backup; and never throwing on a full storage.
  - `client.test.ts`: `keepalive` forwarding, `warmCsrfToken()`, and a token fetched across a
    reset never being cached. Removing the epoch check failed that test.
  - `contrast.test.ts`: the new surface in both themes.
- **e2e** (`private-notes.spec.ts`, against the real stack with real crypto):
  - round-trip, with the counterpart's notes GET returning `null` and its detail response
    carrying no notes fields;
  - editable after archive;
  - Hide takes the text out of the page, and the choice survives a reload;
  - two tabs, where "Keep both" saves `from A\n\n---\n\nfrom B`;
  - first use: saves blocked, then logout, then login, and the text is restored from the backup
    and saved;
  - after a password reset: unreadable, then "Start new notes";
  - in-app navigation from one anketa to another saves the first one's unsaved text into it;
  - closing the tab with unsaved text raises `beforeunload`.
- **Mutation checks.** Removing `{#key id}` failed the navigation test. Disabling the backup write
  failed the first-use test.
- **The full e2e suite** passes, and so do typecheck, lint, format, knip and the unit tests.
