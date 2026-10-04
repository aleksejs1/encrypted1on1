# Opt-in "Remember this browser for 30 days"

Closes [GitHub issue #205](https://github.com/aleksejs1/encrypted1on1/issues/205), part of the
product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)).

## Problem

The master key lives only in a tab's `sessionStorage`, and the session cookie ends when the
browser closes. Every new visit — from a reminder email, a calendar link, or the next morning —
starts with typing the password, and every new tab with typing it again. The links #202 and
#203 added land on a password screen.

## Decision

A checkbox on the login form, off by default. With it:

- **The session lasts 30 days from the login** (`AuthSession::REMEMBER_SECONDS`). The session
  stores its end (`remembered_until`), `getCurrentUser()` rejects it after that, and the 12-hour
  idle timeout doesn't apply to it. The cookie gets the same lifetime
  (`Session::migrate(false, $lifetime)`, called before the login is written — see below). Activity doesn't extend it: the cookie is only sent at
  login, so a sliding limit on the server could never be longer than the fixed one in the
  browser, and a fixed end also bounds a stolen cookie. This replaces the issue's "30-day idle
  threshold".
- **The browser keeps the master key in IndexedDB** (`frontend/src/crypto/rememberedKey.ts`),
  encrypted with an AES-GCM key created as non-extractable, with the session's end date beside
  it. A new tab with no key of its own reads it, unwraps the private key with it, and copies it
  into its own `sessionStorage`.
- **The server decides, not the checkbox.** `POST /api/login` answers `rememberedSecondsLeft`
  (or `null`). The client stores a key only on a non-null answer.
  The demo account always gets `null`. It is a duration, not a date: the browser counts the
  key's end date on its own clock, so a clock that disagrees with the server's neither deletes
  a valid key on every load nor keeps one past its session.

Without the checkbox nothing changes: tab-scoped key, cookie until the browser closes, 12 idle
hours.

### What this does to the threat model

Stated the same way in [`docs/encryption.md`](../encryption.md#remember-this-browser).

- The non-extractable wrapping key stops a script from reading that key's bytes. It does not
  stop a script on the page (XSS) from asking the browser to decrypt, and it does not protect
  the key on disk: the wrapping key and the ciphertext are both in the browser profile, and a
  copy of the profile works in another browser. The issue's "protects against copying the key
  off the disk" was too strong and isn't claimed.
- So a remembered browser is a place where **anyone who can use the browser, or read its
  profile, can read the user's 1:1s for up to 30 days** without knowing the password. What
  protects it is the device's own lock and disk encryption. The checkbox says so.
- Nothing changes for the server: it still never sees a key, and a database or backup leak
  reveals what it did before.

### A password change ends every other session

The issue's first version needed no revocation, on the argument that a remembered key can't
unwrap the private key once the password changes. That leaves two gaps on a lost device:

- its session keeps serving ciphertext and the plaintext metadata (names, dates, goal titles,
  the template library) for up to 30 days;
- a private key already unwrapped on it stays valid, because a password change re-wraps the
  same private key. With a live session that key still decrypts everything.

So a session now also stores a stamp of the password it was opened under
(`credential_stamp`, a SHA-256 of `authHash`), and `getCurrentUser()` ends a login whose stamp
doesn't match the user's current one. A password change or reset on any device ends every
other session of that user on its next request; the session that made the change gets the new
stamp (`AuthSession::refreshCredentialStamp()`). No token table. This is the maintainer's
decision, and it changes one row of the issue's table: a password change elsewhere leads to
the login screen, not to "Unlock this tab".

It applies to ordinary sessions too. A session opened before this change has no stamp; it gets
the user's current one on its next request, so the deploy signs nobody out (#194's point).

What it doesn't fix: someone who copied the private key off the device before the password
changed keeps a key that still matches the user's public key. Only a password reset (a new
keypair) retires it, and anything they already downloaded stays readable. "Lost this device?
Change your password" is the right first step, not a guarantee. A device list and explicit
revocation are [#211](https://github.com/aleksejs1/encrypted1on1/issues/211).

### Who writes and deletes the remembered key

IndexedDB is shared by every tab of the browser, and a tab knows nothing about what the others
have done since it last asked the server. So the rules avoid any step where a tab acts on the
shared store from its own, possibly stale, view:

- **Created only by a login with the checkbox.**
- **Replaced in place, never created, anywhere else** (`replaceRememberedMasterKey()`, which
  does nothing when no key is remembered): after a password change in this browser, and after
  "Unlock this tab". A tab that unlocks just after another tab logged out therefore can't put a
  key back. The record names its owner (the user's public key), and only the same user's key
  is replaced: if a delete ever failed, the next user of that browser profile doesn't get
  their key stored under someone else's opt-in.
- **Never deleted for failing to unwrap.** A tab can't tell a wrong key from an `/api/me`
  answer fetched a moment before another tab changed the password. A wrong key just sends the
  tab to "Unlock this tab", and the password typed there replaces it.
- **Deleted** by logout, by a login that isn't remembered, and when its date passes.

| Event | Remembered key | Session |
|---|---|---|
| Login with the checkbox | written, with an end date `rememberedSecondsLeft` from now | 30 days |
| Login without it, activation, password reset in this browser | deleted | ordinary |
| Explicit logout, account deletion | deleted (started before the logout request, so closing the tab mid-logout still removes it) | ended |
| Password change in this browser | replaced with the new key, same end date; nothing is written if nothing was remembered | kept |
| Password change or reset elsewhere | kept until the next login here replaces or deletes it, or its date passes; it no longer unwraps anything | ended on the next request |
| Remembered key doesn't unwrap the private key | kept; the tab shows "Unlock this tab" | kept |
| Unlocking a tab with the password | replaced with the key that unlocked, if one is remembered | kept |
| User blocked, company suspended | kept until its date passes or the next login | ended by `getCurrentUser()` |
| 401 in a tab (`markSessionExpired()`) | **kept** | — |
| End date passed | deleted on the next page load, logged in or not (`discardExpiredRememberedKey()`), and never returned by a read | already ended |
| Demo account | never written | ordinary |

A 401 keeps the key because `markSessionExpired()` also runs for a stale tab's request sent
before another tab's fresh login; deleting there would remove the key that login just stored.
The cost, as in the issue: after a block or a password change elsewhere, the old key stays in
that browser's profile until its end date. By then it unwraps nothing the server still serves,
but it does unwrap a copy of the old `encryptedPrivateKey` if someone kept one. The end date is
what bounds it.

### The unlock state machine

`authState.unlockStatus` keeps its three states. The second key source is not a fourth status
and not a flag beside it: it is a second step inside the one transition out of `unknown`,
`ensureUnlocked()`.

```
tab key present?  ── yes ─→ unwraps? ── yes ─→ unlocked
      │ no                     │ no: remove the tab key
      ▼                        ▼
remembered key present? ── yes ─→ unwraps? ── yes ─→ copy into the tab → unlocked
      │ no                                  │ no
      ▼                                     ▼
 "Not logged in." (never had a key)     WrongPasswordError → locked
```

- **A tab key proved wrong is removed from the tab; the remembered key is only read.** A tab
  still holding the key from before a password change made in another tab fails with its own
  key and succeeds with the remembered one.
- **Staleness is checked after every await and before the tab's store is touched.** A logout
  or a same-tab relogin during an unwrap means the failure says nothing about the key, so
  nothing is removed and the caller gets "stale". The copy into `sessionStorage` happens in the
  same synchronous step as the last check (`storeEncodedMasterKey()`), so a key can't reappear
  in a tab after its logout.
- **Operations on the remembered key run in call order** (one promise queue in
  `rememberedKey.ts`), and none is skipped or abandoned: a logout following a still-running
  write deletes what it wrote. Callers don't wait for writes or deletes, so a store that hangs
  can't hold up a login or a logout. Opening the database gives up after 3 seconds (IndexedDB
  can hang without failing); an operation that never got a database has done nothing, so the
  order still holds, and a read then reports "nothing remembered".
- `checkUnlocked()` no longer clears the tab key on a wrong password; `ensureUnlocked()` does.

Call sites of the functions this touches, re-derived:

- `markSessionExpired()` — `checkAuth()` 401, `checkUnlocked()` 401, `UnlockTab` 401,
  `logOut()`. Unchanged for all four; only `logOut()` additionally forgets the remembered key.
- `storeMasterKey()` — `Login`, `Activate`, `ResetPassword` (each now also writes or deletes
  the remembered key, per the table), `UnlockTab` (stores an unproven key, so it replaces the
  remembered one only after `checkUnlocked()` says `unlocked`, with the key then in the tab),
  `AccountSettings` (replace if present).
- `loadMasterKey()` — `ensureUnlocked()`, plus `Anketa.svelte` and `AccountSettings` for legacy
  drafts. Those two only run once the tab is unlocked, by which time the key is in
  `sessionStorage` whichever source it came from.

### The login is written after the session id changes

`logIn()` used to write `user_id` and then call `migrate()`. That was safe while `migrate()`
only changed the id. With a cookie lifetime to change, Symfony's `migrate()` first saves the
session under its **old** id, whose file `migrate(false)` keeps — so the pre-login id would
have held a working login, for 30 days when remembered, that a logout doesn't end. `logIn()`
now changes the id first and writes the login into the new one only. Checked on the e2e stack:
after a remembered login the pre-login cookie gets 401.

Deleting the old session instead (`migrate(true)`) was tried and dropped: a request still in
flight with the old cookie then finds an empty session, and Symfony answers it with a
clear-cookie header that can arrive after the login response and delete the new cookie. What
this leaves as it was before: logging in again without logging out keeps the earlier login
alive under its old id until that login's own timeout.

### Not done

- **Handing the key between tabs over `BroadcastChannel`** (the issue's smaller alternative).
  It only covers a link clicked while the app is open elsewhere, and adds a second way for key
  material to move, with its own states. The remembered key covers that case too.
- **Remembering at activation or password reset.** Those screens have no checkbox; the next
  login can opt in.
- **A "forget this browser" button.** Logging out does it.

## Accepted limits

- "Unlock this tab" accepts any password while a working remembered key exists (it only shows
  in that state if reading the key failed at page load): the tab key fails, the remembered one
  unlocks. Nothing is gained by it; a new tab in that browser opens without a password anyway.
- A new tab that read the remembered key just before another tab's password change, and asked
  the server just after, shows "Unlock this tab" although the store now holds a working key.
- A session opened before this release has no stamp until its next request, so a password
  change made in that gap doesn't end it. Such a session is an ordinary one: 12 idle hours at
  most.
- Two tabs logging in at the same moment, one with the checkbox and one without, can leave
  the key and the session disagreeing about which one won.
- Only opening the database has a timeout. A transaction that never completes would block the
  queue, and with it unlocking a new tab.
- The `e1o1` IndexedDB database is created, empty, in every visitor's browser by the page-load
  expiry check.

- A browser whose remembered key was lost while its session lives on (site data partly
  cleared) asks for the password in every new tab until the next login: "Unlock this tab" only
  replaces a key, it doesn't create one.
- In a browser with IndexedDB unavailable the checkbox still gives a 30-day session, and new
  tabs ask for the password as before. Nothing fails.
- `session.gc_maxlifetime` is now 31 days for every session file, anonymous ones included.
- PHP sends the session cookie again, without an expiry, each time a session starts. Symfony's
  `SessionListener` removes that header, except for a session already closed by
  `AuthSession::closeForReading()` — which is most read requests. Left in, it turned the 30-day
  cookie back into a browser-session one on the first `/api/me` after login (found by the e2e
  restart test). `closeForReading()` now removes it itself, with Symfony's
  `SessionUtils::popSessionCookie()`; that class is marked `@internal`, and the restart test is
  what would catch it changing.
- The 30-day cookie lifetime is set with `ini_set()` inside Symfony's `migrate()`. `logIn()`
  passes a lifetime on every login, `0` included, so a remembered login's lifetime isn't carried into an ordinary one. The
  deployments here don't run FrankenPHP in worker mode; if one did, an anonymous session
  started after a remembered login could get a 30-day cookie. The server-side limits don't
  depend on the cookie.

## Verification

- Backend unit and functional tests (`AuthSessionTest`, `AuthControllerTest`) with a mocked
  clock: a remembered login outlives the idle timeout, ends at exactly 30 days, isn't extended
  by requests, is refused to the demo account, and a password change ends another session but
  not its own.
- Frontend unit tests: `rememberedKey.test.ts` (real WebCrypto, `fake-indexeddb`),
  `identity.svelte.test.ts` (real key wrapping; every branch of the diagram, a logout
  landing mid-unlock, and no write to the shared store), `auth.test.ts`.
- Six `code-review` rounds. The first two reshaped the cross-tab rules (above); the third
  found the login left under the pre-login session id; the fourth's fix for that
  (`migrate(true)`) was reverted in the fifth for the clear-cookie race; the sixth returned
  only points already listed under "Accepted limits".
- On the e2e stack with `curl`: a remembered login's `Set-Cookie` carries `Max-Age=2592000`,
  `/api/me` re-sends no cookie, and the pre-login session id gets 401.
- `frontend/e2e/remember-browser.spec.ts`, real crypto against the e2e stack with a persistent
  Chromium profile closed and launched again as the browser restart: one test per table row
  that needs a browser.
