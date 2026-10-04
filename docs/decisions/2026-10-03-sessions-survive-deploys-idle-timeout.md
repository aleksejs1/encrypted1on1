# Sessions survive deploys and end after 12 idle hours

Closes [GitHub issue #194](https://github.com/aleksejs1/encrypted1on1/issues/194), part of the
product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)).

## Problem

- Session files went to PHP's default location, the container's own tmp directory. Every
  deployment mounts a volume at `/app/var` only, so recreating the container on deploy signed
  everyone out.
- `session.gc_maxlifetime` was PHP's default, 24 minutes. PHP's session garbage collection is
  probabilistic (1 request in 100 by default), so an idle session was sometimes gone after 24
  minutes and sometimes alive days later. It was neither a usable session length nor a security
  timeout.

## Decision

- **Session files live in `var/sessions`** (`framework.session.handler_id`/`save_path` in
  `backend/config/packages/framework.php`), inside the volume every topology already has. Not
  Symfony's own default for a file handler, `%kernel.cache_dir%/sessions`: the documented deploy
  step deletes `var/cache/prod`.
- **`AuthSession` enforces the idle timeout itself.** `logIn()` and every successful
  `getCurrentUser()` write the current time to the session (`last_active_at`); a
  `getCurrentUser()` more than `AuthSession::IDLE_TIMEOUT_SECONDS` (12 hours, a working day)
  after the last one ends the login and returns `null`, which every caller already treats as
  401. Exactly 12 hours is still valid.
- **An idle logout drops the login, not the session.** `logOut()` invalidates the whole session,
  CSRF secret included. The idle check runs in `CompanyFilterListener`, before
  `CsrfProtectionListener`, so doing the same there made a tab's first Save after a night fail
  with 403 "invalid CSRF token" instead of 401; the client shows the login screen only for a 401
  and keeps its cached token, which would then fail the login as well. Removing just `user_id`
  and `last_active_at` keeps the token valid. The session id is still regenerated at the next
  login (`migrate()`).
- **Any authenticated request counts as activity**, the meeting page's 4-second live-update poll
  included, so a visible open page on an awake machine stays signed in. The poll pauses while
  the tab is hidden, so a background tab doesn't.
- **A logged-in session with no timestamp counts as idle.** No session can skip the timeout by
  lacking the value. In practice only sessions created before this change lack it, and those are
  lost with the old container anyway.
- **`session.gc_maxlifetime` is 48 hours** and only clears old files off the disk. It must stay
  above the idle timeout. (31 days since
  [#205](2026-10-04-remember-this-browser.md), to cover a remembered login.)
- **`cookie_lifetime` stays `0`**: the cookie still ends when the browser closes. Longer, opt-in
  sessions are [#205](https://github.com/aleksejs1/encrypted1on1/issues/205), which is meant to
  reuse the same check with another threshold.
- **The clock is `Psr\Clock\ClockInterface`**, as in `SendRemindersCommand`, so the functional
  tests move time with Symfony's `ClockSensitiveTrait` instead of editing session data.

## What this doesn't change

- The encryption key is still tab-scoped (`sessionStorage`). A session that survives a deploy
  keeps the user authenticated; a tab that was closed still asks for the password
  (`UnlockTab.svelte`).
- An anonymous session (one holding only a CSRF token) has no timeout of its own; the 48-hour
  cleanup removes its file.
- There's still no absolute session lifetime: a session used at least every 12 hours lives until
  the browser closes.
- Sessions are still files on one container's disk, so the single-instance limit in
  [ADR 4](../adr/0004-session-based-auth.md) stands.

## Verification

- Unit (`AuthSessionTest`) and functional tests with a mocked clock (`AuthControllerTest`,
  `AnketaControllerTest`): idle past the timeout is rejected and stays rejected, exactly the
  timeout passes, each request restarts the window, the live-state poll alone keeps a session
  alive, logging in again after an idle logout works, and a write sent with the CSRF token an
  idle tab still holds gets 401 and that token still logs in.
- One `code-review` round found the 403-instead-of-401 problem above and the "open page"
  wording that ignored hidden tabs; both fixed.
- On the dev stack: a real logged-in session returned 200 from `/api/me` before and after
  `docker compose up -d --force-recreate`; setting its `last_active_at` 12 hours and 5 seconds
  back returned 401. The throwaway account and its session files were removed afterwards.
- Not yet verified on a production deployment; the issue asks for that after the deploy.
