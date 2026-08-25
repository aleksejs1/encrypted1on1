import { describe, expect, it } from 'vitest';
import { groupByCounterpart } from './groupByCounterpart';

interface Row {
  id: string;
  counterpartId: string;
  counterpartEmail: string;
  counterpartName: string;
  meetingDate: string;
}

function row(
  id: string,
  counterpartId: string,
  counterpartEmail: string,
  meetingDate: string,
): Row {
  return {
    id,
    counterpartId,
    counterpartEmail,
    counterpartName: '',
    meetingDate,
  };
}

describe('groupByCounterpart', () => {
  it('returns an empty array for an empty input', () => {
    expect(groupByCounterpart<Row>([])).toEqual([]);
  });

  it('puts every anketa for one counterpart into a single group, in input order', () => {
    const anketas = [
      row('a1', 'u1', 'alice@example.com', '2026-03-01T00:00:00.000Z'),
      row('a2', 'u1', 'alice@example.com', '2026-01-01T00:00:00.000Z'),
    ];

    const groups = groupByCounterpart(anketas);

    expect(groups).toEqual([
      {
        counterpartId: 'u1',
        counterpartEmail: 'alice@example.com',
        counterpartName: '',
        anketas: [anketas[0], anketas[1]],
      },
    ]);
  });

  it("orders groups by each counterpart's first (most recent) appearance in a date-DESC input", () => {
    // Bob's most recent anketa (Feb) sorts before Alice's most recent one (Jan) in the
    // date-DESC input, even though Alice also has an even-earlier anketa mixed in later.
    const anketas = [
      row('b1', 'u2', 'bob@example.com', '2026-02-01T00:00:00.000Z'),
      row('a1', 'u1', 'alice@example.com', '2026-01-15T00:00:00.000Z'),
      row('a2', 'u1', 'alice@example.com', '2025-12-01T00:00:00.000Z'),
    ];

    const groups = groupByCounterpart(anketas);

    expect(groups.map((g) => g.counterpartId)).toEqual(['u2', 'u1']);
    expect(groups[1].anketas).toEqual([anketas[1], anketas[2]]);
  });
});
