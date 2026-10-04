/**
 * A meeting left open for longer than its period, e.g. over a vacation
 * (GitHub issue #204): the meeting list shows a banner for it, and its archive
 * form suggests a next date that isn't in the past.
 */
import type { AnketaSummary } from '../api/types';
import { daysUntilMeeting } from './isOverdue';

/**
 * Whether the meeting is still open more than `periodicityDays` after its
 * date, so the pair's next meeting on the same cadence is already due. Never
 * a one-off, which has no next meeting to schedule, nor a legacy anketa
 * without a period.
 */
export function isOpenPastPeriod(
  anketa: Pick<
    AnketaSummary,
    'archivedAt' | 'meetingDate' | 'periodicityDays' | 'oneOff'
  >,
  now: Date = new Date(),
): boolean {
  if (anketa.archivedAt !== null || anketa.oneOff) return false;
  if (anketa.periodicityDays === null || anketa.periodicityDays <= 0) {
    return false;
  }
  return -daysUntilMeeting(anketa.meetingDate, now) > anketa.periodicityDays;
}

/**
 * The nearest date on the meeting's cadence (`meetingDate` plus a whole
 * number of periods, at least one) that isn't before today, as `YYYY-MM-DD`.
 * `periodicityDays` must be positive. "Today" is the viewer's local calendar
 * date, as in daysUntilMeeting().
 */
export function nextCadenceDate(
  meetingDate: string,
  periodicityDays: number,
  now: Date = new Date(),
): string {
  const daysPast = -daysUntilMeeting(meetingDate, now);
  const periods = Math.max(1, Math.ceil(daysPast / periodicityDays));
  // A date-only string parses as UTC midnight, so UTC getters keep the day.
  const next = new Date(meetingDate.slice(0, 10));
  next.setUTCDate(next.getUTCDate() + periods * periodicityDays);
  return next.toISOString().slice(0, 10);
}
