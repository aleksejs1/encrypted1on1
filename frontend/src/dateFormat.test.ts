import { describe, expect, it } from 'vitest';
import { formatDate } from './dateFormat';

describe('formatDate', () => {
  // Noon UTC — safely within the same calendar day across every real-world
  // timezone offset (UTC-12..UTC+14), so this test isn't sensitive to
  // whatever timezone it happens to run in.
  const fullIso = '2026-03-05T12:00:00.000Z';

  it('formats dmy_dot', () => {
    expect(formatDate(fullIso, 'dmy_dot')).toBe('05.03.2026');
  });

  it('formats dmy_slash', () => {
    expect(formatDate(fullIso, 'dmy_slash')).toBe('05/03/2026');
  });

  it('formats mdy_slash', () => {
    expect(formatDate(fullIso, 'mdy_slash')).toBe('03/05/2026');
  });

  it('formats iso', () => {
    expect(formatDate(fullIso, 'iso')).toBe('2026-03-05');
  });

  it('pads single-digit day and month', () => {
    expect(formatDate('2026-01-09T12:00:00.000Z', 'dmy_dot')).toBe(
      '09.01.2026',
    );
  });

  it('accepts a Date object directly', () => {
    expect(formatDate(new Date(2026, 2, 5), 'dmy_dot')).toBe('05.03.2026');
  });

  it('parses a plain YYYY-MM-DD date-only string by its own components, not via UTC', () => {
    // A naive `new Date('2026-01-01')` parses as UTC midnight — under a
    // timezone west of UTC, reading it back with local getters would show
    // 2025-12-31, a day early. A goal's targetDate has no time-of-day at
    // all, so this must stay 2026-01-01 regardless of the viewer's timezone.
    expect(formatDate('2026-01-01', 'dmy_dot')).toBe('01.01.2026');
    expect(formatDate('2026-01-01', 'iso')).toBe('2026-01-01');
  });
});
