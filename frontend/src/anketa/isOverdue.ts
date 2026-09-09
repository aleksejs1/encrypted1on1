import { formatDate } from '../dateFormat';

/**
 * Whole calendar days between "today" (the viewer's *local* calendar date)
 * and `meetingDate`'s picked day — 0 for today, negative for a day already
 * past. `meetingDate` is always a full ISO instant at UTC midnight of the
 * picked calendar day (see CreateAnketa.svelte's
 * `new Date(meetingDate).toISOString()`), so its first 10 characters are
 * exactly that picked date, stable regardless of the viewer's own timezone.
 *
 * Both `meetingDay` and `today` are plain `YYYY-MM-DD` strings by the time
 * they reach `new Date()` here, which JS parses as UTC midnight directly —
 * unlike a full ISO instant, a date-only string has no local-getter read to
 * reintroduce a timezone shift, so no manual UTC reconstruction is needed.
 * (`formatDate` must not be used on `meetingDate` itself for this same
 * reason: passed a full ISO instant, it reads local getters off a
 * UTC-midnight instant, which *would* reintroduce that shift.)
 */
export function daysUntilMeeting(
  meetingDate: string,
  now: Date = new Date(),
): number {
  const meetingDay = meetingDate.slice(0, 10);
  const today = formatDate(now, 'iso');
  return Math.round(
    (new Date(meetingDay).getTime() - new Date(today).getTime()) / 86_400_000,
  );
}

/**
 * Defined in terms of `daysUntilMeeting` (not a second, independent
 * day-comparison) so the two can never desync — a caller that needs both
 * "is this overdue" and "how many days" should call `daysUntilMeeting` once
 * and derive both from its sign, rather than calling this function and
 * `daysUntilMeeting` separately: each defaults `now` to its own
 * `new Date()`, and two independent calls a moment apart could disagree
 * right at a local-midnight boundary.
 */
export function isOverdue(
  anketa: { archivedAt: string | null; meetingDate: string },
  now: Date = new Date(),
): boolean {
  if (anketa.archivedAt !== null) return false;
  return daysUntilMeeting(anketa.meetingDate, now) < 0;
}
