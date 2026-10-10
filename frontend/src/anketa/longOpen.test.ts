import { describe, expect, it } from 'vitest';
import { isOpenPastPeriod, nextCadenceDate } from './longOpen';

// Midday, so the local calendar date is 2026-08-25 in any test timezone.
const NOW = new Date('2026-08-25T12:00:00Z');

function meeting(
  overrides: Partial<Parameters<typeof isOpenPastPeriod>[0]> = {},
): Parameters<typeof isOpenPastPeriod>[0] {
  return {
    archivedAt: null,
    meetingDate: '2026-08-01T00:00:00.000Z',
    periodicityDays: 14,
    oneOff: false,
    ...overrides,
  };
}

describe('isOpenPastPeriod', () => {
  it('is true once more than one period has passed since the meeting date', () => {
    // 15 days before NOW.
    expect(
      isOpenPastPeriod(
        meeting({ meetingDate: '2026-08-10T00:00:00.000Z' }),
        NOW,
      ),
    ).toBe(true);
  });

  it('is false exactly one period after the meeting date', () => {
    expect(
      isOpenPastPeriod(
        meeting({ meetingDate: '2026-08-11T00:00:00.000Z' }),
        NOW,
      ),
    ).toBe(false);
  });

  it('is false for a meeting that is merely past its date', () => {
    expect(
      isOpenPastPeriod(
        meeting({ meetingDate: '2026-08-24T00:00:00.000Z' }),
        NOW,
      ),
    ).toBe(false);
  });

  it('is false for an upcoming meeting', () => {
    expect(
      isOpenPastPeriod(
        meeting({ meetingDate: '2026-09-01T00:00:00.000Z' }),
        NOW,
      ),
    ).toBe(false);
  });

  it('is false for an archived meeting', () => {
    expect(
      isOpenPastPeriod(
        meeting({ archivedAt: '2026-08-02T10:00:00.000Z' }),
        NOW,
      ),
    ).toBe(false);
  });

  it('is false for a one-off, which has no next meeting', () => {
    expect(isOpenPastPeriod(meeting({ oneOff: true }), NOW)).toBe(false);
  });

  it('is false for a legacy meeting without a period', () => {
    expect(isOpenPastPeriod(meeting({ periodicityDays: null }), NOW)).toBe(
      false,
    );
  });

  it('is false for a period that is not positive', () => {
    expect(isOpenPastPeriod(meeting({ periodicityDays: 0 }), NOW)).toBe(false);
  });
});

describe('nextCadenceDate', () => {
  it('skips the cadence dates that are already past', () => {
    // 2026-08-01 + 14 = 08-15 (past), + 28 = 08-29.
    expect(nextCadenceDate('2026-08-01T00:00:00.000Z', 14, NOW)).toBe(
      '2026-08-29',
    );
  });

  it('suggests today when today is on the cadence', () => {
    expect(nextCadenceDate('2026-08-11T00:00:00.000Z', 14, NOW)).toBe(
      '2026-08-25',
    );
    expect(nextCadenceDate('2026-07-28T00:00:00.000Z', 14, NOW)).toBe(
      '2026-08-25',
    );
  });

  it('moves to the following cadence date the day after', () => {
    expect(nextCadenceDate('2026-08-10T00:00:00.000Z', 14, NOW)).toBe(
      '2026-09-07',
    );
  });

  it('is one period after a meeting that is today or still ahead', () => {
    expect(nextCadenceDate('2026-08-25T00:00:00.000Z', 7, NOW)).toBe(
      '2026-09-01',
    );
    expect(nextCadenceDate('2026-09-01T00:00:00.000Z', 7, NOW)).toBe(
      '2026-09-08',
    );
  });

  it('covers a meeting left open for many periods', () => {
    // 2026-01-06 is 231 days before NOW: 33 weekly periods, landing on today.
    expect(nextCadenceDate('2026-01-06T00:00:00.000Z', 7, NOW)).toBe(
      '2026-08-25',
    );
    expect(nextCadenceDate('2026-01-07T00:00:00.000Z', 7, NOW)).toBe(
      '2026-08-26',
    );
  });

  it('crosses a month and year boundary', () => {
    expect(
      nextCadenceDate(
        '2026-12-20T00:00:00.000Z',
        30,
        new Date('2027-01-25T12:00:00Z'),
      ),
    ).toBe('2027-02-18');
  });
});
