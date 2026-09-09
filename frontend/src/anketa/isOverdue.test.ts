import { afterEach, describe, expect, it, vi } from 'vitest';
import { daysUntilMeeting, isOverdue } from './isOverdue';

// A fixed instant partway through 2026-08-25, matching the backend's own
// OverviewAggregatorTest fixture ('2026-08-25 12:00:00') so both suites
// exercise the same "same-day" boundary.
const NOW = new Date('2026-08-25T12:00:00Z');

afterEach(() => {
  vi.unstubAllEnvs();
});

describe('isOverdue', () => {
  it('is not overdue when the meeting is today, regardless of time of day', () => {
    expect(
      isOverdue(
        { archivedAt: null, meetingDate: '2026-08-25T00:00:00.000Z' },
        NOW,
      ),
    ).toBe(false);
  });

  it('is not overdue when the meeting is tomorrow', () => {
    expect(
      isOverdue(
        { archivedAt: null, meetingDate: '2026-08-26T00:00:00.000Z' },
        NOW,
      ),
    ).toBe(false);
  });

  it('is overdue when the meeting was yesterday', () => {
    expect(
      isOverdue(
        { archivedAt: null, meetingDate: '2026-08-24T00:00:00.000Z' },
        NOW,
      ),
    ).toBe(true);
  });

  it('is never overdue once archived, even if the meeting date is long past', () => {
    expect(
      isOverdue(
        {
          archivedAt: '2026-01-01T00:00:00.000Z',
          meetingDate: '2026-01-01T00:00:00.000Z',
        },
        NOW,
      ),
    ).toBe(false);
  });
});

describe('daysUntilMeeting', () => {
  it('is 0 when the meeting is today, regardless of time of day', () => {
    expect(daysUntilMeeting('2026-08-25T00:00:00.000Z', NOW)).toBe(0);
  });

  it('is 1 when the meeting is tomorrow', () => {
    expect(daysUntilMeeting('2026-08-26T00:00:00.000Z', NOW)).toBe(1);
  });

  it('is a whole-day count for a meeting further out', () => {
    expect(daysUntilMeeting('2026-09-01T00:00:00.000Z', NOW)).toBe(7);
  });

  it('is negative when the meeting was yesterday', () => {
    expect(daysUntilMeeting('2026-08-24T00:00:00.000Z', NOW)).toBe(-1);
  });

  // Agreement with isOverdue() under an East-of-UTC local timezone: this
  // instant is still 2026-08-24 in UTC, but already 2026-08-25 locally in
  // Kiritimati (UTC+14) — both functions must derive "today" from the same
  // local calendar date, not the raw UTC instant, so a meeting on the 25th
  // reads as today (not tomorrow) here.
  it('agrees with isOverdue() when local "today" is ahead of UTC "today"', () => {
    vi.stubEnv('TZ', 'Pacific/Kiritimati');
    const now = new Date('2026-08-24T20:00:00Z');
    const anketa = {
      archivedAt: null,
      meetingDate: '2026-08-25T00:00:00.000Z',
    };

    expect(isOverdue(anketa, now)).toBe(false);
    expect(daysUntilMeeting(anketa.meetingDate, now)).toBe(0);
  });

  // Same agreement check under a West-of-UTC local timezone: this instant
  // is already 2026-08-25 in UTC, but still 2026-08-24 locally in Midway
  // (UTC-11), so a meeting on the 25th reads as tomorrow, not today.
  it('agrees with isOverdue() when local "today" lags behind UTC "today"', () => {
    vi.stubEnv('TZ', 'Pacific/Midway');
    const now = new Date('2026-08-25T05:00:00Z');
    const anketa = {
      archivedAt: null,
      meetingDate: '2026-08-25T00:00:00.000Z',
    };

    expect(isOverdue(anketa, now)).toBe(false);
    expect(daysUntilMeeting(anketa.meetingDate, now)).toBe(1);
  });
});
