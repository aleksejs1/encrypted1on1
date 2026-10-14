import type { BillingState } from '../api/billingState';

/**
 * The Plan page right after Stripe sends the admin back (GitHub issue #307):
 * one state, and one function that moves it, so that the page only runs it.
 *
 * Stripe returns to `/admin/plan?checkout=success`, `?checkout=cancelled`
 * (BillingController::checkout()) or `?portal=return` (portalSession()). A
 * payment reaches the company only through the webhook, which may come
 * after the admin does (decision 6 of #286): until then the page waits, and
 * shows no pay button, as that is the moment a person pays a second time.
 * The page trusts the parameter, not Stripe: a link to it opened from the
 * browser's history waits for nothing and then says "If you just paid".
 */

/**
 * How long after each answer the page reads the plan again while it waits:
 * after the answer, not on a fixed beat, or a slow server never gets one in.
 */
export const POLL_MS = 2_000;
/** How long it waits for a payment to arrive. */
export const PAYMENT_WAIT_MS = 30_000;
/** How long it keeps reading after the portal, where anything may have changed. */
export const PORTAL_WAIT_MS = 10_000;

/** The return addresses' parameters, as BillingController writes them. */
const RETURN_PARAMS = {
  'checkout=success': 'checkout-success',
  'checkout=cancelled': 'checkout-cancelled',
  'portal=return': 'portal-return',
} as const;

export type ReturnParam = (typeof RETURN_PARAMS)[keyof typeof RETURN_PARAMS];

/**
 * The parts of `search`, as written: not through URLSearchParams, which would
 * encode the rest anew when the return parameter is taken out.
 */
function searchParts(search: string): string[] {
  return search
    .replace(/^\?/, '')
    .split('&')
    .filter((part) => part !== '');
}

function returnParamOf(part: string): ReturnParam | null {
  return Object.hasOwn(RETURN_PARAMS, part)
    ? RETURN_PARAMS[part as keyof typeof RETURN_PARAMS]
    : null;
}

/** The parameter Stripe sent the admin back with, if any. */
export function readReturnParam(search: string): ReturnParam | null {
  for (const part of searchParts(search)) {
    const param = returnParamOf(part);
    if (param !== null) return param;
  }
  return null;
}

/** `search` without the return parameters, the rest as written: "" or "?x=1". */
export function searchWithoutReturn(search: string): string {
  const rest = searchParts(search).filter(
    (part) => returnParamOf(part) === null,
  );
  return rest.length === 0 ? '' : `?${rest.join('&')}`;
}

export type PlanReturn =
  /** A parameter, and no plan read yet: the first read decides. */
  | { phase: 'starting'; param: ReturnParam }
  /** Nothing to say: no parameter, or done with one. */
  | { phase: 'none' }
  /** Back from Checkout, the company still on Free: "Switching to Plus…". */
  | { phase: 'waiting'; since: number }
  /** The plan moved to Plus: "You're on Plus. Thank you." */
  | { phase: 'confirmed' }
  /**
   * Waited long enough: "If you just paid…", and "Check again" (one read),
   * whose last answer is said: still Free (`unchanged`) or no answer.
   */
  | {
      phase: 'gave-up';
      check: 'idle' | 'running' | 'unchanged' | 'failed';
    }
  /** Checkout left without paying: "You haven't been charged." */
  | { phase: 'cancelled' }
  /** Back from the portal: reads again for a while, says nothing. */
  | { phase: 'portal'; since: number }
  /**
   * The page was left, or ended by a refusal (the session ended, the admin
   * role taken away): nothing more happens, the address isn't touched.
   */
  | { phase: 'left' };

export type PlanReturnEvent =
  /**
   * The plan was read, `at` the time it arrived. Times are the page's own
   * monotonic clock (`performance.now()`), so a change of the system clock
   * neither ends a wait nor stretches it.
   */
  | { type: 'read'; state: BillingState; at: number }
  /** A read failed. Only "Check again" says so; a poll just tries again. */
  | { type: 'read-failed' }
  /** Time passed: the page's own clock, between reads. */
  | { type: 'time'; at: number }
  /** "Check again" was pressed. */
  | { type: 'check' }
  /** The page was left, or a read was refused. */
  | { type: 'leave' };

export function initialReturn(param: ReturnParam | null): PlanReturn {
  return param === null ? { phase: 'none' } : { phase: 'starting', param };
}

export function nextReturn(
  current: PlanReturn,
  event: PlanReturnEvent,
): PlanReturn {
  if (current.phase === 'left') return current;
  if (event.type === 'leave') return { phase: 'left' };

  switch (current.phase) {
    case 'starting':
      return event.type === 'read' ? started(current.param, event) : current;
    case 'waiting':
      if (event.type === 'read' && event.state !== 'free') {
        return settled(event.state);
      }
      if (
        event.type === 'time' &&
        event.at >= current.since + PAYMENT_WAIT_MS
      ) {
        return { phase: 'gave-up', check: 'idle' };
      }
      return current;
    case 'gave-up':
      if (event.type === 'check' && current.check !== 'running') {
        return { phase: 'gave-up', check: 'running' };
      }
      if (event.type === 'read' && event.state !== 'free') {
        return settled(event.state);
      }
      // "Checked just now" only for the read "Check again" asked for.
      if (event.type === 'read' && current.check === 'running') {
        return { phase: 'gave-up', check: 'unchanged' };
      }
      if (event.type === 'read-failed' && current.check === 'running') {
        return { phase: 'gave-up', check: 'failed' };
      }
      return current;
    case 'portal':
      if (event.type === 'time' && event.at >= current.since + PORTAL_WAIT_MS) {
        return { phase: 'none' };
      }
      return current;
    case 'none':
    case 'confirmed':
    case 'cancelled':
      return current;
  }
}

function started(
  param: ReturnParam,
  read: { state: BillingState; at: number },
): PlanReturn {
  switch (param) {
    case 'checkout-success':
      return read.state === 'free'
        ? { phase: 'waiting', since: read.at }
        : settled(read.state);
    // Said only on Free: a company that pays already has its answer on the
    // page, whatever this Checkout was.
    case 'checkout-cancelled':
      return read.state === 'free' ? { phase: 'cancelled' } : { phase: 'none' };
    case 'portal-return':
      return { phase: 'portal', since: read.at };
  }
}

/**
 * Off Free: thanks for a Plus that is paid. A plan set by hand, or a server
 * that stopped offering Plus, gets no thanks for a payment it didn't take,
 * and a Plus whose payment failed has its own warning on the page.
 */
function settled(state: BillingState): PlanReturn {
  return state === 'plus' || state === 'plus-ending'
    ? { phase: 'confirmed' }
    : { phase: 'none' };
}

/**
 * Whether focus goes to the plan's heading: on Plus confirmed, at any step,
 * and when a wait ends on another plan (also through "Check again", whose
 * button is gone then). Not when the first read finds a plan that needs no
 * wait: that is an ordinary page load, and moves no focus.
 */
export function focusesPlan(before: PlanReturn, after: PlanReturn): boolean {
  if (after.phase === before.phase) return false;
  if (after.phase === 'confirmed') return true;
  return (
    after.phase === 'none' &&
    (before.phase === 'waiting' || before.phase === 'gave-up')
  );
}

/**
 * When the wait ends by time, or `null`: the page sends 'time' then too, not
 * only between reads, so that a read that hangs doesn't hold it up.
 */
export function waitEndsAt(current: PlanReturn): number | null {
  if (current.phase === 'waiting') return current.since + PAYMENT_WAIT_MS;
  if (current.phase === 'portal') return current.since + PORTAL_WAIT_MS;
  return null;
}

/** Whether the page reads the plan again, POLL_MS after each answer: while a wait runs. */
export function polls(current: PlanReturn): boolean {
  return waitEndsAt(current) !== null;
}

/**
 * Whether the parameter stays in the address. It does while the page waits,
 * so that a reload then waits again instead of showing the pay button, and
 * before the first read, so that a reload after a failed one starts over.
 * Never touched once the page is left: the address is another page's then.
 */
export function keepsParam(current: PlanReturn): boolean {
  return (
    current.phase === 'starting' ||
    current.phase === 'waiting' ||
    current.phase === 'portal' ||
    current.phase === 'left'
  );
}

/**
 * Whether the card of the current plan is shown: not while waiting, where
 * "Your plan: Free" would contradict "Payment received", nor before the
 * first read has said whether to wait.
 */
export function showsPlan(current: PlanReturn): boolean {
  return current.phase !== 'starting' && current.phase !== 'waiting';
}

/**
 * Whether the Plus card with its pay button may be shown. Not before the
 * first read has said whether to wait, and not once the page has ended
 * either, which may have been in the middle of a wait.
 */
export function offersPayment(current: PlanReturn): boolean {
  return (
    current.phase !== 'starting' &&
    current.phase !== 'waiting' &&
    current.phase !== 'gave-up' &&
    current.phase !== 'left'
  );
}
