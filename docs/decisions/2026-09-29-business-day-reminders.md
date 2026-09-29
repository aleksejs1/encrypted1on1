# Business-day meeting reminders

Closes [GitHub issue #167](https://github.com/aleksejs1/encrypted1on1/issues/167).

## Problem

`app:send-reminders` runs once a day and reminds every meeting that is tomorrow. For a Monday 1:1
that meant a work email on Sunday, which most people either resent or don't read before the meeting.

## Decision

- **A Friday run also reminds Monday's meetings**, with their own copy (`email.meeting_monday`,
  `email.not_filled_out_monday`: "Your 1:1 is on Monday") in all 6 locales. "Tomorrow" would be wrong
  on a Friday. `AnketaNotifier` gets `notifyMeetingMonday()`/`notifyNotFilledOutMonday()`, gated by
  `User::wantsMeetingReminders()` like the existing two.
- **Sunday's run is the fallback.** It still reminds tomorrow's (Monday's) meetings that haven't been
  reminded for that day: one scheduled over the weekend, or one a failed Friday run missed, gets the
  normal "tomorrow" copy.
- **Saturday and Sunday meetings keep the plain day-before rule.** This differs from the issue, whose
  Friday window was Saturday–Monday. A Friday email about a Sunday meeting would say "tomorrow", which is
  wrong, and a third copy variant for a rare case isn't worth it. Someone who meets on a Sunday works on
  the weekend anyway, so Saturday's reminder doesn't cross the boundary the issue is about.
- **Days are UTC days.** The command reads "today" from an injected `Psr\Clock\ClockInterface` (new
  direct dependency: `symfony/clock`, MIT), converts it to UTC, and checks for Friday. A user far from UTC
  may get the reminder late on Thursday or early on Saturday local time; per-user timezones would be a
  separate change. The stored meeting date is the picked calendar day at midnight (the web UI sends UTC
  midnight), so its date part is the day. A reviewer suggested converting offset dates to UTC; that was
  tried and reverted, since it moves a `+03:00` midnight to the previous day, breaking that invariant.

### Which day was reminded (a migration, unlike the issue's plan)

The issue planned no migration, relying on `reminderSentAt` as a one-shot flag. With a reminder up to
three days ahead, that flag stopped being enough: a Monday meeting reminded on Friday and moved to
Wednesday over the weekend would never be reminded for Wednesday. Clearing the flag on reschedule was
tried first and took four review rounds of races. The job could stamp the old date after the move, two
reschedules could interleave, and a meeting moved away and back got a second reminder for the same day.
Each fix needed more locking. The maintainer chose a migration instead.

`Anketa::$reminderMeetingDay` (DATE, SQLite and MySQL migrations `Version20260929150000`) records the
meeting day the last reminder was for. A reminder is due while it differs from the meeting's current day
(`AnketaRepository::findDueForReminder()`). Moving the meeting to another day makes a reminder due again,
and moving it back to the reminded day doesn't. Rescheduling needs no special handling and stays the
plain entity update it was. The migration backfills the column for every already-reminded anketa
with the day the old day-before-only code reminded for: the meeting's day when `reminderSentAt` falls on
it or the day before (a batch past midnight stamped the meeting day), otherwise the day after
`reminderSentAt`. The upgrade
reminds nothing twice, and a meeting moved after its old-style reminder is still due for its new day. One
case can't be told apart from the old data: a meeting moved by exactly one day after a past-midnight
stamp is recorded as reminded for its new day. The reminder index is now
`(archivedAt, meetingDate)`, since the query no longer filters on `reminderSentAt`, which stays as the
time of the last reminder.

**The command claims each anketa before sending** (`AnketaRepository::claimReminder()`, one conditional
UPDATE on the same conditions as the select, the approach of `markArchivedIfOpen()`, #130). Two
overlapping runs (a cron retry) can't both send. A meeting moved to another day or archived since the
select is skipped rather than reminded with its old date, and it's due again for its new day. The select
returns ids only, and the command loads each anketa with its participants from a cleared EntityManager
right before claiming it. A batch loaded up front goes stale while earlier emails send: a side that
published meanwhile would still get the "not filled out" nudge, a participant who opted out would still
be emailed, and on a Friday the Monday pass would get back the Saturday pass's copy of an anketa moved to
Monday since. Loading one at a time also holds only one anketa's encrypted blobs in memory at a time (each is still
hydrated in full, blobs included, though only its dates, published flags and participants are read). A claim that
throws (a locked database) counts as a failure like a send that throws, without stopping the batch.

Accepted trade-offs:

- A killed process between the claim and the send loses that reminder for good, and a Friday claim for
  Monday also keeps Sunday's fallback from retrying it. An exception there is reported, the claim is released
  back to the anketa's previous reminded day (`AnketaRepository::releaseReminder()`), the rest of the batch
  carries on, and the command exits non-zero. A same-day rerun (or, for a Monday meeting, Sunday's
  fallback) reminds it, possibly re-sending an email that did go out; the next day's run looks at the
  next day's meetings, so `docs/deployment.md` says to rerun the same day. A mail transport failure counts too:
  `AnketaNotifier` still logs and swallows it, but its reminder methods now return false, so an SMTP
  outage leaves every reminder due and fails the run instead of claiming them all and losing them (which
  the old stamp-after-send code also did). A permanent failure (an address the mailer rejects) fails
  every rerun the same way, re-sending the other side's emails each time; the error names the anketa. The old stamp-after-send code failed the other way: a
  crash mid-batch re-sent on the rerun. Claiming first is what makes overlapping runs send once.
- `reminderSentAt` is now the last claim's time, set even when every participant has opted out or the
  send failed, so it isn't proof an email went out. The command's summary says "Processed", not "Sent", for the same reason.
- A meeting moved after its reminder went out keeps the email that names the old date; the new date gets
  its own reminder only if its reminder day hasn't passed yet (moved to tomorrow after today's run, it
  gets none, as before).
- A Monday meeting gets one "not filled out" nudge, on Friday. A second nudge closer to the meeting
  would mean a Sunday email, the thing this change removes.
- If Friday's run fails or is skipped, Sunday's fallback sends the Monday reminders. The command doesn't
  report that separately; an operator sees it only in the cron log's timing.
- `reminderMeetingDay` is new plaintext metadata: for a meeting moved after its reminder, it keeps the
  earlier date until the next reminder, so the database shows the reschedule. Listed in
  `docs/encryption.md`'s threat model.
- A failed run reports every failure, then throws once at the end, so error tracking (Sentry on the
  Cloud deployment) still sees it with the first cause attached.
- The job must run every day, weekends included (`docs/deployment.md`): Saturday's run reminds Sunday
  meetings and Sunday's is the Monday fallback.
- Holidays aren't handled: a Monday meeting after a Friday holiday is still reminded on that Friday.

## Verification

`tests/Functional/SendRemindersCommandTest.php` runs the real command against the test database with a
`MockClock`: a Friday run (Saturday gets "tomorrow", Monday gets "on Monday" with the fill-out nudge only
for the unpublished side, Sunday and Tuesday untouched), a Sunday run after a Friday one (no duplicate, a
later-created Monday meeting reminded), a Wednesday run (only Thursday), a clock in `Pacific/Auckland`
whose local Saturday is still Friday in UTC, a Monday meeting moved to Wednesday after Friday's reminder
(reminded again on Tuesday, while a same-day move isn't re-sent), a second run started mid-batch (each
reminder sent once), a Saturday meeting moved to Monday between a Friday run's two passes (the Monday
email names the new date), a side that publishes mid-batch (no nudge; both fail if the batch is loaded up front), a send that throws (reported, released, the rest of
the batch still reminded, and the rerun reminds it), an SMTP outage (the same), and resolving the command from the container (the new clock dependency).
`AnketaRepositoryTest` covers the claim: once per day, released (back to the previous day), refused for a moved or archived meeting,
and due again only for another day. `AnketaNotifierTest` the two new methods' keys, parameters and opt-out, and every
reminder method's transport-failure result. The SQLite migration was run
up, down and up again on the dev database.
