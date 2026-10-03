# Follow-up email after a meeting nobody closed

Closes [GitHub issue #202](https://github.com/aleksejs1/encrypted1on1/issues/202), part of the
product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)). Builds on
[`2026-09-29-business-day-reminders.md`](2026-09-29-business-day-reminders.md).

## Problem

After a meeting's date passed, nothing more was sent. Only archiving creates the next meeting, so
an unclosed meeting also meant no further reminders. The server can't roll the cycle over (the
next meeting's key is generated client-side), so the fix is an email that brings a participant
back.

## Decision

- **One follow-up per participant, on the next business day.** `app:send-reminders` also looks at
  meetings whose day has passed and that are still open, and sends "Did your 1:1 happen?"
  (`email.meeting_follow_up`, all 6 locales, `AnketaNotifier::notifyMeetingFollowUp()`). It is
  gated by `User::wantsMeetingReminders()`, and the account page's hint for that toggle now says
  so. A pair with a blocked (or deleted) account gets none: that account can't log in to use
  the links, and its counterpart can't schedule a next meeting with it (the day-before reminder
  has no such check; left as it was).
- **Weekend meetings are followed up on Monday**, with Friday's. A weekend run sends no
  follow-ups: the point of the business-day rule is no work email on a weekend. This differs from
  the reminder, which a weekend meeting does get the day before, because that one is needed
  before the meeting, and this one can wait.
- **Only the previous business day is looked at**, as the issue proposed: yesterday, and on a
  Monday also Saturday and Friday. A wider look-back (three, then five days on every weekday
  run) was tried, as an automatic retry for a failed or skipped run, and dropped after review.
  A failed send releases the claim and the retry re-sends to both participants, so with one
  mailbox rejecting mail for good the other participant got the same follow-up on every run in
  the window. It also followed up a meeting moved back to a past date days later. So a follow-up
  is retried the way a reminder is, by a rerun on the same day (`docs/deployment.md`), and one
  that no run sent on its day is not sent.
- **A meeting created with yesterday's date, or moved to it, before the run is followed up too.**
  It is past its day and not closed, which is what the email says, and the page already shows the
  "not closed" card for it.
- **Claimed per meeting day**, like the reminder: `Anketa::$followUpMeetingDay` (SQLite and MySQL
  migrations `Version20261003170313`), `AnketaRepository::findDueForFollowUp()`,
  `claimFollowUp()`, `releaseFollowUp()`. A rerun sends nothing new, an archived or moved meeting
  is skipped at the claim, a meeting moved after its follow-up is due again once its new day has
  passed, and a failed send (a mail transport failure included) releases the claim and fails the
  run. The command's passes are a `ReminderPass` enum (`Tomorrow`, `Monday`, `FollowUp`) over one
  loop, so the follow-up gets the same load-fresh, claim, send, release handling without a copy.
- **Not backfilled.** When this ships, a meeting already open past its day gets a follow-up only
  if its day is the first weekday run's previous business day. Older ones are left to the
  banner planned in #204.
- **Two links, told apart by the URL fragment**: `…/anketas/{id}#close` and `#reschedule`. The
  fragment never reaches the server and survives the login or unlock screen, which render at the
  same URL. `#close` scrolls to the archive form once the page has loaded (`goToArchiveSection()`,
  shared with the "not closed" card); `#reschedule` focuses the date field, opening the "Change
  date" row for a meeting that is no longer past its day. One handler in `Anketa.svelte`
  follows both, once the page has loaded, a company template's questions included (they load
  separately). The fragment is dropped once followed (`clearFollowUpHash()`), so a reload
  doesn't scroll or take focus again, and it is also followed on `hashchange`, for a link opened
  in a tab already showing the meeting.
  `frontend/src/anketa/followUpLinks.ts` holds the fragments, and a test reads them back from the
  PHP source.
- **A one-off gets its own body** (`body_one_off`): closing it schedules nothing, so "close it and
  schedule the next one" would be wrong.

Accepted trade-offs:

- Until [#205](https://github.com/aleksejs1/encrypted1on1/issues/205) the link usually lands on
  the password screen first.
- Holidays aren't handled, as for reminders.
- The scroll to the archive form doesn't wait for the private notes, which load separately and
  sit above the answers on a narrow screen; where the browser doesn't anchor the scroll position,
  the form can move down after the scroll. Focus is on its heading either way.
- A `#reschedule` link to a meeting closed since the email does nothing; the page shows it as
  archived.
- Days are UTC days, as for reminders. For a user far west of UTC, a run early in the UTC day
  arrives late on the evening of the meeting day itself.
- The email says nothing about whether the recipient published; it is about closing.
- `followUpMeetingDay` is new plaintext metadata, listed in `docs/encryption.md`.

## Verification

`SendRemindersCommandTest` runs the real command with a `MockClock`: a Monday run (Friday,
Saturday and Sunday followed up, with both links; Thursday, today's and an archived meeting not),
weekend runs sending nothing and Monday's sending once across a rerun and Tuesday's run, a
Thursday run following up Wednesday's meeting and not Tuesday's, a meeting moved before and after
its follow-up, an opted-out recipient, and an SMTP outage (released, sent by a same-day rerun).
`AnketaRepositoryTest` covers the claim and release, `AnketaNotifierTest` the parameters, the
one-off body, the opt-out and the transport-failure result. The SQLite migration was run up, down
and up on the dev database; the MySQL one is hand-written (one nullable column, the same statement
as `reminderMeetingDay`'s) and was not run against MySQL. A Playwright test
(`dual-actor-anketa.spec.ts`) opens both links on a past-date meeting in a real browser, by a
page load and by a fragment change in the open tab.
