# Meeting emails: clearer copy, one reminder, a notice when the date moves

Closes [GitHub issue #200](https://github.com/aleksejs1/encrypted1on1/issues/200), part of the
product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)). Builds on
[`2026-09-29-business-day-reminders.md`](2026-09-29-business-day-reminders.md).

## Problem

- The "new 1:1" email named its creator by email address and said only "Please fill out your
  part", nothing about what the product is.
- The day before a meeting, a participant who hadn't published got two emails: the reminder and a
  separate "not filled out" nudge.
- Moving a meeting to another date sent nothing, so the counterpart never learned the new date.

## Decision

- **People are named as "Display name (email)", or by the email alone** if they never set a name
  (`AnketaNotifier::nameOf()`), in every email `AnketaNotifier` sends. The email stays next to
  the name: a display name is self-chosen, and a mandatory email from the instance's own sender
  address naming only "the CEO" would be an impersonation tool. A display name has its control
  characters stripped on input (`DisplayNameField`), so it can't add lines to a body.
- **The "new 1:1" email explains the product** and says the answers are end-to-end encrypted.
  It says "only the two of you can read them", the wording the app's empty meeting list uses,
  rather than repeating the creator's "name (email)" a second time. It says "answers", not "everything": goal titles and company template texts are plaintext
  (see [`docs/encryption.md`](../encryption.md)). The same email goes out for each auto-created
  next meeting, so a pair meeting regularly reads the explanation every cycle; a shorter variant
  for those would need the notifier to know whether the recipient has had a meeting before.
- **One reminder email per recipient.** A recipient whose side isn't published gets the same
  reminder with one more line (`body_not_published`), for both the "tomorrow" and the "on Monday"
  variant. `notifyNotFilledOut()` and its Monday variant are gone; the notifier itself checks
  `Anketa::isPublished()`, on the anketa the command loaded right before the claim.
- **The extra line says "You haven't published your part yet"**, not the issue's "you haven't
  added anything yet". The condition is "not published", and a participant with a saved draft has
  added something; telling them otherwise reads as lost data.
- **Rescheduling emails the counterpart** the old and the new date
  (`AnketaLifecycleService::reschedule()`, `notifyMeetingRescheduled()`).
- **That email is a mandatory notice, not a reminder**: it isn't gated by
  `User::wantsMeetingReminders()`. Like the "new 1:1" email it reports something another person
  did that the recipient can't learn any other way without opening the app. Someone who turned
  reminders off still has to know their meeting moved. The account settings hint says so.

## Limits

- No email for a move within the same day: emails show a date only, so it would read "moved from X
  to X".
- No email to a blocked counterpart (a deleted account is blocked): they can't open the meeting.
  The guard is in `reschedule()` only: the day-before reminder still goes to a blocked (not
  deleted) participant, as before this change.
- The reminder's subject is the same whether or not the recipient published; only the body differs.
- Each move sends an email; several moves in a row send several. No batching, cooldown or rate
  limit, so a participant can send their counterpart many emails by moving a meeting back and
  forth. Creating meetings in a loop already allowed the same; a limiter for both is a follow-up
  (it needs a new env-configured limiter like the existing ones).
- The email is sent inside the request, after the date is saved, like the "new 1:1" email: a
  slow mail server slows the request, and a non-transport failure answers 500 with the date
  already moved.
- A mail transport failure is logged and the reschedule still succeeds, like the "new 1:1" email.
- The other notifiers (invites, password reset) still name people by email.

## Verification

`AnketaNotifierTest` (body variant by published state for both reminders, display-name fallback,
the reschedule email's parameters and that it ignores the opt-out), `SendRemindersCommandTest`
(one email per recipient on a Friday, weekday and Sunday run; the extra line only for the
unpublished side, including a side that publishes mid-batch), `AnketaControllerTest` (a
reschedule emails the counterpart in their locale with the mover's display name, whichever side
moves it; none for a same-day move or a blocked counterpart). `TranslationConsistencyTest` covers the 6 locales' keys.
