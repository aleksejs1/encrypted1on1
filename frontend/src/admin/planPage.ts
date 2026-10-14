import type { BillingState } from '../api/billingState';
import type { BillingOverview, PlusPrice } from '../api/types';

/**
 * What the admin Plan page shows, apart from the markup and the words
 * (GitHub issue #286): pure functions over the two answers of the page's API,
 * so every state can be tested without a browser. The Invites tab reads the
 * seat line from here too.
 */

/** "40 of 100 seats used: 37 people, 3 pending invitations", as numbers. */
export interface SeatLine {
  used: number;
  limit: number;
  people: number;
  pendingInvites: number;
  /** No invitation goes out: the server refuses one from the limit up. */
  full: boolean;
}

/** `null` for a company without a seat limit: there is nothing to count against. */
export function seatLine(
  overview: Pick<
    BillingOverview,
    'seatLimit' | 'activeUsers' | 'pendingInvites'
  >,
): SeatLine | null {
  if (overview.seatLimit === null) return null;
  const used = overview.activeUsers + overview.pendingInvites;
  return {
    used,
    limit: overview.seatLimit,
    people: overview.activeUsers,
    pendingInvites: overview.pendingInvites,
    full: used >= overview.seatLimit,
  };
}

const PRICE_INTERVALS = ['day', 'week', 'month', 'year'] as const;

type PriceInterval = (typeof PRICE_INTERVALS)[number];

// How many decimals Stripe counts an amount in. Stripe's own lists, not
// Intl's fraction digits: the two differ (Intl gives the Hungarian forint and
// the Icelandic króna no decimals, Stripe counts both in hundredths).
// https://docs.stripe.com/currencies#zero-decimal
const ZERO_DECIMAL_CURRENCIES = [
  'bif',
  'clp',
  'djf',
  'gnf',
  'jpy',
  'kmf',
  'krw',
  'mga',
  'pyg',
  'rwf',
  'vnd',
  'vuv',
  'xaf',
  'xof',
  'xpf',
];
const THREE_DECIMAL_CURRENCIES = ['bhd', 'jod', 'kwd', 'omr', 'tnd'];

/** 0 for the yen, 3 for the Kuwaiti dinar, 2 for the rest. */
function stripeDecimals(currency: string): number {
  if (ZERO_DECIMAL_CURRENCIES.includes(currency)) return 0;
  if (THREE_DECIMAL_CURRENCIES.includes(currency)) return 3;
  return 2;
}

export interface PriceLine {
  /** The amount with its currency, in the UI language: "€20", "20,50 €". */
  amount: string;
  interval: PriceInterval;
  /** Tax comes on top: everything but a price Stripe calls `inclusive`. */
  plusTax: boolean;
}

/**
 * `null` when there is no price to name (the server had none, or it is per a
 * period the page has no word for): the page then says the price is on the
 * payment page.
 */
export function priceLine(
  price: PlusPrice | null,
  locale: string,
): PriceLine | null {
  if (price === null) return null;
  const interval = PRICE_INTERVALS.find((known) => known === price.interval);
  if (interval === undefined) return null;

  const currency = price.currency.toLowerCase();
  let amount: string;
  try {
    const decimals = stripeDecimals(currency);
    amount = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      // Stripe's digits here too, or Intl rounds away the forint's.
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
      // "€20", not "€20.00"; "€20.50" keeps both digits.
      trailingZeroDisplay: 'stripIfInteger',
    }).format(price.amount / 10 ** decimals);
  } catch {
    // Something Intl refuses: a currency that isn't three letters, or a
    // locale tag it can't read.
    return null;
  }

  return { amount, interval, plusTax: price.taxBehavior !== 'inclusive' };
}

export interface PlanPageView {
  state: BillingState;
  /**
   * Which plan to name: Free and Plus in the page's own words, a plan set by
   * hand by `customPlanName`. Not the state: a `free` plan can be in the
   * `custom` state (one without a seat limit).
   */
  plan: 'free' | 'plus' | 'custom';
  /** The name of a plan set by hand, to show as it is. */
  customPlanName: string | null;
  /** Its `full` is "No seats left". */
  seats: SeatLine | null;
  /**
   * The card under a Free plan: Plus with its pay button, or the line that
   * paid plans aren't available on this server. `null` in every other state.
   */
  offer: 'plus' | 'unavailable' | null;
  /** "Next payment": the period end of a subscription that renews and is paid. */
  nextPaymentOn: string | null;
  /** "Plus ends on": the date of a scheduled cancellation. */
  endsOn: string | null;
  /**
   * With `endsOn`: Free's seats are all taken already, so after that date
   * everyone stays but nobody can be invited. Never for a company without a
   * seat limit, which keeps having none.
   */
  noInvitesAfterEnd: boolean;
  /**
   * The link to Stripe's customer portal, by what it is for here: managing a
   * running subscription, fixing a failed payment, or the invoices of a
   * company whose subscription is over. `null` when the portal can't be
   * opened.
   */
  portal: 'manage' | 'update-payment' | 'invoices' | null;
  /** Where to write, when the server names an address. */
  supportEmail: string | null;
}

export function planPageView(overview: BillingOverview): PlanPageView {
  const { state, planTier } = overview;
  const seats = seatLine(overview);
  const plan = planTier === 'free' || planTier === 'plus' ? planTier : 'custom';

  return {
    state,
    plan,
    customPlanName: plan === 'custom' ? planTier : null,
    seats,
    offer:
      state === 'free'
        ? 'plus'
        : state === 'free-unavailable'
          ? 'unavailable'
          : null,
    nextPaymentOn: state === 'plus' ? overview.periodEndsAt : null,
    endsOn: state === 'plus-ending' ? overview.cancelsAt : null,
    // From the limit up, as the server refuses an invitation (seatLine()).
    noInvitesAfterEnd:
      state === 'plus-ending' &&
      seats !== null &&
      seats.used >= overview.freeSeatLimit,
    portal: portalPurpose(overview),
    supportEmail: overview.supportEmail,
  };
}

function portalPurpose(overview: BillingOverview): PlanPageView['portal'] {
  if (!overview.hasInvoices) return null;
  switch (overview.state) {
    case 'plus-past-due':
      return 'update-payment';
    case 'plus':
    case 'plus-ending':
      return 'manage';
    // A subscription from before the plan was set by hand may still run, and
    // only a running one has a period end.
    case 'custom':
      return overview.periodEndsAt === null ? 'invoices' : 'manage';
    case 'free':
    case 'free-unavailable':
      return 'invoices';
  }
}
