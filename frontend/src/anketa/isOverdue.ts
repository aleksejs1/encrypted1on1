import { formatDate } from '../dateFormat';

/**
 * `meetingDate` is always a full ISO instant at UTC midnight of the picked
 * calendar day (see CreateAnketa.svelte's `new Date(meetingDate).toISOString()`)
 * — its first 10 characters are exactly that picked date, stable regardless
 * of the viewer's own timezone. Comparing that against `now` formatted as a
 * *local* calendar date (not a raw instant comparison) is what keeps the
 * meeting day itself from reading as overdue: an instant comparison flips
 * true the moment UTC clock time reaches the meeting day's first instant,
 * which lands inside (or, west of UTC, even before) the meeting day itself
 * rather than after it.
 *
 * `formatDate` must not be used on `meetingDate` for the other side of this
 * comparison — passed a full ISO instant it reads local getters off that
 * UTC-midnight instant, which reintroduces the same timezone-shift bug this
 * function exists to avoid.
 */
export function isOverdue(
  anketa: { archivedAt: string | null; meetingDate: string },
  now: Date = new Date(),
): boolean {
  if (anketa.archivedAt !== null) return false;
  const meetingDay = anketa.meetingDate.slice(0, 10);
  const today = formatDate(now, 'iso');
  return today > meetingDay;
}
