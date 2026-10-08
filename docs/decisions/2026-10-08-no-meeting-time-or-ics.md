# No meeting time of day or .ics calendar attachments

Closes [GitHub issue #207](https://github.com/aleksejs1/encrypted1on1/issues/207), part of the
product-adoption work ([#213](https://github.com/aleksejs1/encrypted1on1/issues/213)).

## Problem

GitHub issue #207 asked whether meetings should get a time of day (hours and minutes) so the
app could offer `.ics` email attachments and "Add to Google Calendar" links, reducing friction
compared to a shared Google Doc.

## Decision

- **No meeting time of day is added**, and no `.ics` email attachments or calendar buttons are
  implemented.
- **`Anketa::$meetingDate` remains date-only**, stored strictly as UTC midnight
  (`YYYY-MM-DDT00:00:00Z`).
- **Calendar integration relies entirely on the permanent per-pair link**
  (`/pair/{userIdA}/{userIdB}`, [GitHub issue #203](2026-10-03-pair-calendar-link.md)). A manager
  pastes this link once into the description of their existing recurring calendar event in Google
  Calendar or Outlook; together with 30-day browser sessions ([#205](2026-10-04-remember-this-browser.md))
  and shared topics ([#206](2026-10-04-shared-topics-list.md)), clicking from the calendar opens the
  current 1:1 without a password prompt.
- **Reconsideration condition:** this analysis is closed unless real pilot users on v1.7.0+
  explicitly report that pasting the permanent link into their existing calendar event is
  insufficient, and explain why.

## Alternatives considered and why they lost

1. **Emailing `.ics` attachments with `METHOD:REQUEST`:**
   - **Organizer identity:** An invitation requires an `ORGANIZER`. The app sends from a system
     address (`MAILER_FROM`, `noreply@...`). Setting `ORGANIZER` to noreply means attendee RSVP
     responses (Accept/Decline) are lost or bounce, and Outlook tags the event as machine-generated.
     Setting `ORGANIZER` to the meeting creator's email address causes corporate mail filters
     (Exchange, Google Workspace) to flag or reject the message due to SPF/DKIM alignment failures
     (sending from the app host on behalf of a user's address).
   - **Recipient asymmetry:** On archive, only the counterpart receives the `notifyAnketaCreated()`
     email (`AnketaLifecycleService::archive()`); the participant who closed the meeting and picked
     the next date receives no email. If the `.ics` arrived as an email attachment, the person who
     scheduled the meeting would get no calendar file. Emailing them a duplicate confirmation for
     their own action contradicts the noise reduction from #200.
   - **Calendar pollution:** In practice, 1:1s already have a recurring event in corporate
     calendars with room bookings and video call links (Google Meet, Zoom, Teams). Importing an
     `.ics` creates a duplicate event at the same hour without the video link.
2. **Recurring rules (`RRULE`) vs. per-cycle events:**
   - Meetings in this app are created dynamically one cycle at a time upon archiving the previous
     one, not pre-generated as a calendar series. Emitting an `RRULE` on the first meeting creates
     an infinite series in the user's calendar. Subsequent cycles would create overlapping duplicate
     series unless the server ran a two-way CalDAV synchronization engine, which is out of scope.
3. **Mandatory meeting time of day:**
   - Contradicts #213's primary goal of reducing friction. Teams agree on meeting days ("we meet
     every second Thursday") and decide exact hours flexibly. Requiring hours and minutes blocks
     creating or archiving an anketa when the exact slot is not yet settled.
4. **Altering `Anketa::$meetingDate` to include time:**
   - The entire codebase relies on `meetingDate` representing a calendar day normalized to UTC
     midnight. `User` has no timezone column. Storing full UTC instants would shift calendar days
     across timezones: an evening meeting in San Francisco (UTC-7) would advance to the next UTC day,
     while a morning meeting in Tokyo (UTC+9) would fall back to the previous day. This breaks
     `SendRemindersCommand` (daily UTC query at `07:00 UTC` with `CRON_TZ=UTC`), `OverviewAggregator`
     (midnight truncation `$today = $now->setTime(0,0,0,0)`), and `isOverdue.ts` (`slice(0, 10)`).
5. **Client-side "Add to Google Calendar" / "Download .ics" buttons:**
   - Contradicts the permanent link model from #203. A one-off button generates single-cycle,
     all-day events without video links, requiring users to re-add events on every cycle. Furthermore,
     `/pair/{a}/{b}` resolves to the pair's chain meeting, so a button on a one-off meeting would
     link to the wrong meeting.

## Future design constraints (if pilot demand ever re-opens this)

- **Encryption boundary:** The server has no operational need for the meeting time (reminders are
  sent daily, not per-minute). If time is ever added, it should be stored encrypted under `anketaKey`
  to protect work-routine metadata from database leaks. Storing it unencrypted (`AllowPlaintext`)
  would require an explicit maintainer exception and an update to `docs/encryption.md`.
- **Timezones:** A naive floating time (`HH:mm`) breaks for cross-timezone pairs (e.g. London and Riga
  interpreting `14:30` as two different moments). It must either be a UTC instant separate from
  `meetingDate`, or store an explicit IANA timezone.
- **Standards:** Any downloaded `.ics` file must strictly follow RFC 5545 (CRLF line endings,
  75-octet line folding, proper character escaping) and omit the `METHOD` header.

## Verification

Codebase audit of `AnketaLifecycleService`, `SendRemindersCommand`, `OverviewAggregator`,
`isOverdue.ts`, and RFC 5545 mail delivery constraints. See
`private/meeting-time-and-calendar-proposal.md` and `private/meeting-time-and-calendar-review.md`.
