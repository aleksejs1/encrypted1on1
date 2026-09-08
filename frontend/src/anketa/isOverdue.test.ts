import { describe, expect, it } from 'vitest';
import { isOverdue } from './isOverdue';

// A fixed instant partway through 2026-08-25, matching the backend's own
// OverviewAggregatorTest fixture ('2026-08-25 12:00:00') so both suites
// exercise the same "same-day" boundary.
const NOW = new Date('2026-08-25T12:00:00Z');

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
