import { describe, expect, it } from 'vitest';
import { sortByRecentCounterparts } from './recentCounterparts';

const alice = {
  id: 'alice',
  email: 'alice@example.com',
  displayName: '',
  publicKey: 'k',
};
const bob = {
  id: 'bob',
  email: 'bob@example.com',
  displayName: '',
  publicKey: 'k',
};
const carol = {
  id: 'carol',
  email: 'carol@example.com',
  displayName: '',
  publicKey: 'k',
};

describe('sortByRecentCounterparts', () => {
  it('leaves order unchanged with no anketa history', () => {
    expect(sortByRecentCounterparts([alice, bob, carol], [])).toEqual([
      alice,
      bob,
      carol,
    ]);
  });

  it('puts a counterpart with history before ones without it', () => {
    const priorAnketas = [
      {
        counterpartId: 'bob',
        counterpartEmail: bob.email,
        counterpartName: '',
        meetingDate: '2026-01-01T00:00:00Z',
      },
    ];

    expect(sortByRecentCounterparts([alice, bob, carol], priorAnketas)).toEqual(
      [bob, alice, carol],
    );
  });

  it('orders multiple recent counterparts by most-recent anketa first', () => {
    // meetingDate DESC, same assumption GET /api/anketas already guarantees —
    // carol's most recent anketa is more recent than bob's.
    const priorAnketas = [
      {
        counterpartId: 'carol',
        counterpartEmail: carol.email,
        counterpartName: '',
        meetingDate: '2026-03-01T00:00:00Z',
      },
      {
        counterpartId: 'bob',
        counterpartEmail: bob.email,
        counterpartName: '',
        meetingDate: '2026-02-01T00:00:00Z',
      },
      {
        counterpartId: 'carol',
        counterpartEmail: carol.email,
        counterpartName: '',
        meetingDate: '2026-01-01T00:00:00Z',
      },
    ];

    expect(sortByRecentCounterparts([alice, bob, carol], priorAnketas)).toEqual(
      [carol, bob, alice],
    );
  });

  it('ranks a counterpart only once, by their most recent anketa, not every occurrence', () => {
    const priorAnketas = [
      {
        counterpartId: 'bob',
        counterpartEmail: bob.email,
        counterpartName: '',
        meetingDate: '2026-03-01T00:00:00Z',
      },
      {
        counterpartId: 'carol',
        counterpartEmail: carol.email,
        counterpartName: '',
        meetingDate: '2026-02-01T00:00:00Z',
      },
      {
        counterpartId: 'bob',
        counterpartEmail: bob.email,
        counterpartName: '',
        meetingDate: '2026-01-01T00:00:00Z',
      },
    ];

    expect(sortByRecentCounterparts([alice, bob, carol], priorAnketas)).toEqual(
      [bob, carol, alice],
    );
  });
});
