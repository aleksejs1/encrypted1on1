import { describe, expect, it } from 'vitest';
import { pairChainState } from './pairChain';

function anketa(
  id: string,
  overrides: Partial<{
    counterpartId: string;
    meetingDate: string;
    archivedAt: string | null;
    oneOff: boolean;
    periodicityDays: number | null;
  }> = {},
) {
  return {
    id,
    counterpartId: 'bob',
    meetingDate: '2026-01-01T00:00:00Z',
    archivedAt: null,
    oneOff: false,
    periodicityDays: 14,
    ...overrides,
  };
}

describe('pairChainState', () => {
  it('has nothing to inherit for a brand-new pair', () => {
    expect(
      pairChainState([anketa('other', { counterpartId: 'carol' })], 'bob'),
    ).toEqual({
      previousAnketa: undefined,
      openAnketa: undefined,
      inheritedPeriodicityDays: null,
    });
  });

  it('picks the most recent archived chain anketa and inherits its periodicity', () => {
    const newer = anketa('newer', {
      meetingDate: '2026-02-01T00:00:00Z',
      archivedAt: '2026-02-01T00:00:00Z',
      periodicityDays: 30,
    });
    const older = anketa('older', { archivedAt: '2026-01-01T00:00:00Z' });

    const state = pairChainState([newer, older], 'bob');

    expect(state.previousAnketa).toBe(newer);
    expect(state.openAnketa).toBeUndefined();
    expect(state.inheritedPeriodicityDays).toBe(30);
  });

  it('finds the open chain anketa, inheriting its periodicity for a pair with no archived one', () => {
    const open = anketa('open', { periodicityDays: 7 });

    const state = pairChainState([open], 'bob');

    expect(state.openAnketa).toBe(open);
    expect(state.inheritedPeriodicityDays).toBe(7);
  });

  it('skips one-offs, open or archived', () => {
    const openOneOff = anketa('open-one-off', {
      meetingDate: '2026-03-01T00:00:00Z',
      oneOff: true,
    });
    const archivedOneOff = anketa('archived-one-off', {
      meetingDate: '2026-02-01T00:00:00Z',
      archivedAt: '2026-02-01T00:00:00Z',
      oneOff: true,
      periodicityDays: 30,
    });
    const archivedChain = anketa('archived-chain', {
      archivedAt: '2026-01-01T00:00:00Z',
    });

    const state = pairChainState(
      [openOneOff, archivedOneOff, archivedChain],
      'bob',
    );

    expect(state.openAnketa).toBeUndefined();
    expect(state.previousAnketa).toBe(archivedChain);
    expect(state.inheritedPeriodicityDays).toBe(14);
  });

  it('picks the earliest open chain anketa, matching the server', () => {
    const later = anketa('later', { meetingDate: '2026-02-01T00:00:00Z' });
    const earlier = anketa('earlier', { meetingDate: '2026-01-01T00:00:00Z' });

    expect(pairChainState([later, earlier], 'bob').openAnketa).toBe(earlier);
  });

  it('breaks a meeting-date tie by id, matching the server', () => {
    const second = anketa('0199-b');
    const first = anketa('0199-a');

    expect(pairChainState([first, second], 'bob').openAnketa).toBe(first);
    expect(pairChainState([second, first], 'bob').openAnketa).toBe(first);
  });

  it('breaks an archived meeting-date tie by id, matching the server', () => {
    const first = anketa('0199-a', { archivedAt: '2026-01-01T00:00:00Z' });
    const second = anketa('0199-b', { archivedAt: '2026-01-02T00:00:00Z' });

    expect(pairChainState([first, second], 'bob').previousAnketa).toBe(second);
    expect(pairChainState([second, first], 'bob').previousAnketa).toBe(second);
  });

  it('falls back to the open anketa when the archived one has no periodicity on record', () => {
    const open = anketa('open', { meetingDate: '2026-02-01T00:00:00Z' });
    const legacy = anketa('legacy', {
      archivedAt: '2026-01-01T00:00:00Z',
      periodicityDays: null,
    });

    expect(pairChainState([open, legacy], 'bob').inheritedPeriodicityDays).toBe(
      14,
    );
  });

  it('has nothing to inherit when the only chain anketa is legacy', () => {
    const legacy = anketa('legacy', { periodicityDays: null });

    const state = pairChainState([legacy], 'bob');

    expect(state.openAnketa).toBe(legacy);
    expect(state.inheritedPeriodicityDays).toBeNull();
  });
});
