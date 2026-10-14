import { describe, expect, it } from 'vitest';
import { BILLING_STATES, type BillingState } from '../api/billingState';
import { repoFile } from '../testRepoFile';
import {
  focusesPlan,
  initialReturn,
  waitEndsAt,
  keepsParam,
  nextReturn,
  offersPayment,
  showsPlan,
  PAYMENT_WAIT_MS,
  type PlanReturn,
  type PlanReturnEvent,
  polls,
  PORTAL_WAIT_MS,
  readReturnParam,
  searchWithoutReturn,
} from './planReturn';

/** A Plus that is paid: the ones thanked for. */
const PLUS_STATES: BillingState[] = ['plus', 'plus-ending'];

function read(state: BillingState, at = 0): PlanReturnEvent {
  return { type: 'read', state, at };
}

function time(at: number): PlanReturnEvent {
  return { type: 'time', at };
}

/** The state after the events, from the page opened with `search`. */
function run(search: string, ...events: PlanReturnEvent[]): PlanReturn {
  return events.reduce(nextReturn, initialReturn(readReturnParam(search)));
}

describe('readReturnParam', () => {
  it("reads Stripe's three return addresses", () => {
    expect(readReturnParam('?checkout=success')).toBe('checkout-success');
    expect(readReturnParam('?checkout=cancelled')).toBe('checkout-cancelled');
    expect(readReturnParam('?portal=return')).toBe('portal-return');
  });

  it('reads nothing else', () => {
    expect(readReturnParam('')).toBeNull();
    expect(readReturnParam('?checkout=paid')).toBeNull();
    expect(readReturnParam('?portal=1')).toBeNull();
    expect(readReturnParam('?success')).toBeNull();
    expect(readReturnParam('?toString')).toBeNull();
    expect(readReturnParam('?x=1&portal=return')).toBe('portal-return');
  });

  // The addresses are BillingController's, which the page can't import.
  it("matches the server's return addresses", () => {
    const php = repoFile('backend/src/Controller/BillingController.php');
    for (const search of [
      '?checkout=success',
      '?checkout=cancelled',
      '?portal=return',
    ]) {
      expect(php).toContain(`'${search}'`);
    }
  });
});

describe('searchWithoutReturn', () => {
  it('drops the return parameters and keeps the rest', () => {
    expect(searchWithoutReturn('?checkout=success')).toBe('');
    expect(searchWithoutReturn('?portal=return')).toBe('');
    expect(searchWithoutReturn('?x=1&checkout=cancelled')).toBe('?x=1');
    expect(searchWithoutReturn('')).toBe('');
    // The rest exactly as it was written.
    expect(searchWithoutReturn('?ref=a%20b&portal=return&x=~/+')).toBe(
      '?ref=a%20b&x=~/+',
    );
    expect(searchWithoutReturn('?checkouts=1')).toBe('?checkouts=1');
    // Only what readReturnParam() reads.
    expect(searchWithoutReturn('?checkout=success&portal=foo')).toBe(
      '?portal=foo',
    );
    expect(searchWithoutReturn('?checkout')).toBe('?checkout');
  });
});

describe('waitEndsAt', () => {
  it('is when a wait ends by time, and the time that ends it', () => {
    const waiting = run('?checkout=success', read('free', 1_000));
    const end = waitEndsAt(waiting);
    expect(end).toBe(1_000 + PAYMENT_WAIT_MS);
    expect(nextReturn(waiting, time(end! - 1)).phase).toBe('waiting');
    expect(nextReturn(waiting, time(end!)).phase).toBe('gave-up');
    const portal = run('?portal=return', read('plus', 1_000));
    expect(waitEndsAt(portal)).toBe(1_000 + PORTAL_WAIT_MS);
    expect(nextReturn(portal, time(waitEndsAt(portal)!)).phase).toBe('none');
  });

  it('is nothing without a wait', () => {
    for (const state of [
      run(''),
      run('?checkout=success'),
      { phase: 'gave-up', check: 'idle' } as const,
      run('?checkout=cancelled', read('free')),
      { phase: 'left' } as const,
    ]) {
      expect(waitEndsAt(state)).toBeNull();
    }
  });
});

describe('focusesPlan', () => {
  const step = (before: PlanReturn, event: PlanReturnEvent): boolean =>
    focusesPlan(before, nextReturn(before, event));
  const waiting = run('?checkout=success', read('free', 0));
  const gaveUp: PlanReturn = { phase: 'gave-up', check: 'running' };

  it('moves focus to the plan when a wait ends, however it ends', () => {
    expect(step(waiting, read('plus'))).toBe(true);
    expect(step(waiting, read('plus-past-due'))).toBe(true);
    expect(step(waiting, read('custom'))).toBe(true);
    expect(step(gaveUp, read('plus'))).toBe(true);
    expect(step(gaveUp, read('custom'))).toBe(true);
    // Plus already there when the page opens.
    expect(step(run('?checkout=success'), read('plus'))).toBe(true);
  });

  it('leaves focus alone otherwise', () => {
    expect(step(waiting, read('free'))).toBe(false);
    expect(step(waiting, time(PAYMENT_WAIT_MS))).toBe(false);
    expect(step(gaveUp, read('free'))).toBe(false);
    expect(step(gaveUp, { type: 'read-failed' })).toBe(false);
    expect(step(waiting, { type: 'leave' })).toBe(false);
    expect(step(run(''), read('plus'))).toBe(false);
    expect(step(run('?checkout=success'), read('custom'))).toBe(false);
    expect(step(run('?checkout=cancelled'), read('free'))).toBe(false);
    const portal = run('?portal=return', read('plus', 0));
    expect(step(portal, time(PORTAL_WAIT_MS))).toBe(false);
    const confirmed: PlanReturn = { phase: 'confirmed' };
    expect(focusesPlan(confirmed, confirmed)).toBe(false);
  });
});

describe('nextReturn', () => {
  it('has nothing to do without a parameter', () => {
    expect(run('', read('free'))).toEqual({ phase: 'none' });
    expect(polls(run(''))).toBe(false);
    expect(offersPayment(run('', read('free')))).toBe(true);
    expect(showsPlan(run(''))).toBe(true);
  });

  it('waits for the plan while it is still Free after Checkout, without a pay button', () => {
    const waiting = run('?checkout=success', read('free', 1_000));
    expect(waiting).toEqual({ phase: 'waiting', since: 1_000 });
    expect(polls(waiting)).toBe(true);
    expect(keepsParam(waiting)).toBe(true);
    expect(offersPayment(waiting)).toBe(false);
    expect(showsPlan(waiting)).toBe(false);
  });

  it('confirms Plus whenever it arrives while waiting', () => {
    for (const at of [2_000, 16_000, 30_999]) {
      for (const state of PLUS_STATES) {
        const confirmed = run(
          '?checkout=success',
          read('free', 1_000),
          time(at - 1),
          read('free', at - 1),
          read(state, at),
        );
        expect(confirmed).toEqual({ phase: 'confirmed' });
        expect(polls(confirmed)).toBe(false);
        expect(keepsParam(confirmed)).toBe(false);
      }
    }
  });

  it('gives up after the wait, still without a pay button', () => {
    const almost = run(
      '?checkout=success',
      read('free', 1_000),
      time(1_000 + PAYMENT_WAIT_MS - 1),
    );
    expect(almost.phase).toBe('waiting');
    const gaveUp = nextReturn(almost, time(1_000 + PAYMENT_WAIT_MS));
    expect(gaveUp).toEqual({ phase: 'gave-up', check: 'idle' });
    expect(polls(gaveUp)).toBe(false);
    expect(keepsParam(gaveUp)).toBe(false);
    expect(offersPayment(gaveUp)).toBe(false);
    // The plan as stored, which the message says hasn't changed.
    expect(showsPlan(gaveUp)).toBe(true);
  });

  it('counts the wait from the first read, not from a read since', () => {
    const waiting = run(
      '?checkout=success',
      read('free', 1_000),
      read('free', 20_000),
      time(1_000 + PAYMENT_WAIT_MS),
    );
    expect(waiting.phase).toBe('gave-up');
  });

  it('checks again once per press, and confirms Plus if it came meanwhile', () => {
    const gaveUp: PlanReturn = { phase: 'gave-up', check: 'idle' };
    const checking = nextReturn(gaveUp, { type: 'check' });
    expect(checking).toEqual({ phase: 'gave-up', check: 'running' });
    // A second press while the first runs changes nothing.
    expect(nextReturn(checking, { type: 'check' })).toBe(checking);
    const unchanged = nextReturn(checking, read('free'));
    expect(unchanged).toEqual({ phase: 'gave-up', check: 'unchanged' });
    expect(nextReturn(unchanged, { type: 'check' })).toEqual(checking);
    expect(nextReturn(checking, read('plus'))).toEqual({ phase: 'confirmed' });
    // A read nobody asked for says nothing about checking.
    expect(nextReturn(gaveUp, read('free'))).toBe(gaveUp);
    expect(nextReturn(gaveUp, read('plus'))).toEqual({ phase: 'confirmed' });
    // And time doesn't move it.
    expect(nextReturn(gaveUp, time(10 ** 9))).toBe(gaveUp);
  });

  it('says so when checking again fails, and can check once more', () => {
    const failed = run(
      '?checkout=success',
      read('free', 0),
      time(PAYMENT_WAIT_MS),
      { type: 'check' },
      { type: 'read-failed' },
    );
    expect(failed).toEqual({ phase: 'gave-up', check: 'failed' });
    expect(nextReturn(failed, { type: 'check' })).toEqual({
      phase: 'gave-up',
      check: 'running',
    });
  });

  it('ignores a failed poll while waiting: the next one tries again', () => {
    const waiting = run('?checkout=success', read('free', 0));
    expect(nextReturn(waiting, { type: 'read-failed' })).toBe(waiting);
  });

  it('waits again from the start after a reload while waiting', () => {
    // The parameter stayed in the address, so the reloaded page has it too.
    const before = run('?checkout=success', read('free', 0), time(20_000));
    expect(keepsParam(before)).toBe(true);
    const reloaded = run('?checkout=success', read('free', 21_000));
    expect(reloaded).toEqual({ phase: 'waiting', since: 21_000 });
    expect(nextReturn(reloaded, time(30_000)).phase).toBe('waiting');
  });

  it('keeps the parameter until the first read, so a reload after a failed one starts over', () => {
    const starting = run(
      '?checkout=success',
      { type: 'read-failed' },
      time(10 ** 9),
    );
    expect(starting).toEqual({ phase: 'starting', param: 'checkout-success' });
    expect(keepsParam(starting)).toBe(true);
    expect(polls(starting)).toBe(false);
    // Not until the read says whether to wait.
    expect(offersPayment(starting)).toBe(false);
    expect(showsPlan(starting)).toBe(false);
  });

  it('confirms at once a company already on Plus when the page opens', () => {
    for (const state of PLUS_STATES) {
      expect(run('?checkout=success', read(state))).toEqual({
        phase: 'confirmed',
      });
    }
  });

  it('thanks no plan that is not a paid Plus', () => {
    for (const state of [
      'custom',
      'free-unavailable',
      'plus-past-due',
    ] as const) {
      expect(run('?checkout=success', read(state))).toEqual({ phase: 'none' });
      expect(run('?checkout=success', read('free'), read(state))).toEqual({
        phase: 'none',
      });
    }
  });

  it('gives up for a company that never paid', () => {
    let state = run('?checkout=success', read('free', 0));
    for (let at = 2_000; at <= PAYMENT_WAIT_MS; at += 2_000) {
      state = nextReturn(nextReturn(state, time(at)), read('free', at));
    }
    expect(state).toEqual({ phase: 'gave-up', check: 'idle' });
  });

  it('says a cancelled Checkout charged nothing, and drops the parameter at once', () => {
    const cancelled = run('?checkout=cancelled', read('free'));
    expect(cancelled).toEqual({ phase: 'cancelled' });
    expect(keepsParam(cancelled)).toBe(false);
    expect(polls(cancelled)).toBe(false);
    expect(offersPayment(cancelled)).toBe(true);
    expect(showsPlan(cancelled)).toBe(true);
    // Not to a company that pays.
    expect(run('?checkout=cancelled', read('plus'))).toEqual({ phase: 'none' });
  });

  it('reads silently for a while after the portal, whatever the plan', () => {
    for (const state of BILLING_STATES) {
      const portal = run('?portal=return', read(state, 1_000));
      expect(portal).toEqual({ phase: 'portal', since: 1_000 });
      expect(polls(portal)).toBe(true);
      expect(keepsParam(portal)).toBe(true);
      expect(offersPayment(portal)).toBe(true);
      // A changed plan says nothing either.
      expect(nextReturn(portal, read('free', 3_000))).toBe(portal);
      expect(nextReturn(portal, time(1_000 + PORTAL_WAIT_MS - 1)).phase).toBe(
        'portal',
      );
      const done = nextReturn(portal, time(1_000 + PORTAL_WAIT_MS));
      expect(done).toEqual({ phase: 'none' });
      expect(keepsParam(done)).toBe(false);
    }
  });

  it('stops everything once the page is left, and leaves the address alone', () => {
    const states: PlanReturn[] = [
      run('?checkout=success'),
      run('?checkout=success', read('free')),
      run('?checkout=success', read('free'), time(PAYMENT_WAIT_MS)),
      run('?portal=return', read('plus')),
      run('?checkout=cancelled', read('free')),
      run(''),
    ];
    for (const state of states) {
      const left = nextReturn(state, { type: 'leave' });
      expect(left).toEqual({ phase: 'left' });
      expect(polls(left)).toBe(false);
      // Ended in the middle of a wait, maybe: no pay button after it.
      expect(offersPayment(left)).toBe(false);
      expect(keepsParam(left)).toBe(true);
      // A read or a timer that was already under way changes nothing.
      for (const event of [
        read('plus'),
        time(10 ** 9),
        { type: 'check' } as const,
      ]) {
        expect(nextReturn(left, event)).toBe(left);
      }
    }
  });
});
