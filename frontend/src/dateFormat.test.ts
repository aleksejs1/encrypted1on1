import { describe, expect, it } from 'vitest';
import { formatDate, formatMonth, parseDate } from './dateFormat';

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

describe('parseDate', () => {
  it('round-trips every format back to YYYY-MM-DD', () => {
    expect(parseDate('31.12.2026', 'dmy_dot')).toBe('2026-12-31');
    expect(parseDate('31/12/2026', 'dmy_slash')).toBe('2026-12-31');
    expect(parseDate('12/31/2026', 'mdy_slash')).toBe('2026-12-31');
    expect(parseDate('2026-12-31', 'iso')).toBe('2026-12-31');
  });

  it('is lenient about missing leading zeros', () => {
    expect(parseDate('5.1.2026', 'dmy_dot')).toBe('2026-01-05');
  });

  it('rejects a calendar date that does not exist, not just a shape mismatch', () => {
    // 31 February looks superficially plausible (valid day-of-month range,
    // valid month range) but never actually exists — new Date() alone would
    // silently roll it over to March, so this must be caught explicitly.
    expect(parseDate('31.02.2026', 'dmy_dot')).toBeNull();
  });

  it('rejects text in the wrong format for the selected formatId', () => {
    expect(parseDate('2026-12-31', 'dmy_dot')).toBeNull();
    expect(parseDate('not a date', 'dmy_dot')).toBeNull();
  });

  it('rejects an empty string', () => {
    expect(parseDate('', 'dmy_dot')).toBeNull();
  });

  it('distinguishes day and month correctly between dmy and mdy for an unambiguous date', () => {
    // 25 can only be a day, never a month — a good check that the two
    // slash-formats genuinely swap which group means what, not just labels.
    expect(parseDate('25/03/2026', 'dmy_slash')).toBe('2026-03-25');
    expect(parseDate('03/25/2026', 'mdy_slash')).toBe('2026-03-25');
  });
});

describe('formatMonth', () => {
  it('formats dmy_dot as MM.YYYY', () => {
    expect(formatMonth('2026-03', 'dmy_dot')).toBe('03.2026');
  });

  it('formats dmy_slash and mdy_slash identically — there is no day to reorder', () => {
    expect(formatMonth('2026-03', 'dmy_slash')).toBe('03/2026');
    expect(formatMonth('2026-03', 'mdy_slash')).toBe('03/2026');
  });

  it('formats iso as YYYY-MM', () => {
    expect(formatMonth('2026-03', 'iso')).toBe('2026-03');
  });

  it('pads a single-digit month', () => {
    expect(formatMonth('2026-9', 'iso')).toBe('2026-09');
  });
});
