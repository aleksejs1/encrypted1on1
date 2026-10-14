import { describe, expect, it } from 'vitest';
import type { BillingOverview, PlusPrice } from '../api/types';
import { repoFile } from '../testRepoFile';
import { BILLING_STATES, type BillingState } from '../api/billingState';
import { planPageView, priceLine, seatLine } from './planPage';

function overview(changes: Partial<BillingOverview> = {}): BillingOverview {
  return {
    state: 'free',
    planTier: 'free',
    seatLimit: 100,
    activeUsers: 37,
    pendingInvites: 3,
    periodEndsAt: null,
    cancelsAt: null,
    hasInvoices: false,
    freeSeatLimit: 100,
    plusSeatLimit: 1000,
    supportEmail: null,
    ...changes,
  };
}

/** A company on Plus, with the dates a running subscription has. */
function plus(
  state: BillingState,
  changes: Partial<BillingOverview> = {},
): BillingOverview {
  return overview({
    state,
    planTier: 'plus',
    seatLimit: 1000,
    activeUsers: 137,
    periodEndsAt: '2026-11-11T00:00:00+00:00',
    hasInvoices: true,
    ...changes,
  });
}

const EUR_20: PlusPrice = {
  amount: 2000,
  currency: 'eur',
  interval: 'month',
  taxBehavior: 'exclusive',
};

describe('BILLING_STATES', () => {
  it("is the server's list, in the server's order", () => {
    const php = repoFile('backend/src/Billing/BillingState.php');
    const cases = [...php.matchAll(/^\s*case \w+ = '([^']+)';$/gm)].map(
      (match) => match[1],
    );
    expect(cases.length).toBeGreaterThan(0);
    expect([...BILLING_STATES]).toEqual(cases);
  });
});

describe('seatLine', () => {
  it('counts people and pending invitations against the limit', () => {
    expect(seatLine(overview())).toEqual({
      used: 40,
      limit: 100,
      people: 37,
      pendingInvites: 3,
      full: false,
    });
  });

  it('is full from the limit up', () => {
    expect(seatLine(overview({ activeUsers: 96 }))?.full).toBe(false);
    expect(seatLine(overview({ activeUsers: 97 }))?.full).toBe(true);
    expect(seatLine(overview({ activeUsers: 140 }))?.full).toBe(true);
  });

  it('is absent without a limit', () => {
    expect(seatLine(overview({ seatLimit: null }))).toBeNull();
  });
});

describe('priceLine', () => {
  it('writes a whole amount without cents, in the UI language', () => {
    expect(priceLine(EUR_20, 'en')).toEqual({
      amount: '€20',
      interval: 'month',
      tax: 'added',
    });
    // German puts the sign after the number, with a no-break space.
    expect(priceLine(EUR_20, 'de')?.amount).toBe('20 €');
  });

  it('keeps both digits of an amount with cents', () => {
    expect(priceLine({ ...EUR_20, amount: 2050 }, 'en')?.amount).toBe('€20.50');
  });

  it("divides by the currency's own minor units, not always by 100", () => {
    const yen = priceLine({ ...EUR_20, amount: 3000, currency: 'jpy' }, 'en');
    expect(yen?.amount).toBe('¥3,000');
    const dinar = priceLine({ ...EUR_20, amount: 6500, currency: 'kwd' }, 'en');
    expect(dinar?.amount).toBe('KWD 6.500');
  });

  // Intl gives both no decimals; Stripe counts both in hundredths.
  it("counts minor units as Stripe does where Intl's digits differ", () => {
    const forint = priceLine(
      { ...EUR_20, amount: 700000, currency: 'huf' },
      'en',
    );
    expect(forint?.amount).toBe('HUF\u00a07,000');
    const krona = priceLine(
      { ...EUR_20, amount: 300000, currency: 'isk' },
      'en',
    );
    expect(krona?.amount).toBe('ISK\u00a03,000');
    // And nothing after the point is rounded away.
    expect(
      priceLine({ ...EUR_20, amount: 199950, currency: 'huf' }, 'en')?.amount,
    ).toBe('HUF\u00a01,999.50');
  });

  it('takes the currency in either case', () => {
    expect(priceLine({ ...EUR_20, currency: 'EUR' }, 'en')?.amount).toBe('€20');
    expect(
      priceLine({ ...EUR_20, amount: 3000, currency: 'JPY' }, 'en')?.amount,
    ).toBe('¥3,000');
  });

  it('says what Stripe says about tax, and nothing when it says nothing', () => {
    expect(priceLine(EUR_20, 'en')?.tax).toBe('added');
    expect(priceLine({ ...EUR_20, taxBehavior: 'inclusive' }, 'en')?.tax).toBe(
      'included',
    );
    expect(
      priceLine({ ...EUR_20, taxBehavior: 'unspecified' }, 'en')?.tax,
    ).toBe('none');
    // Not a key of Object.prototype.
    expect(priceLine({ ...EUR_20, taxBehavior: 'toString' }, 'en')?.tax).toBe(
      'none',
    );
  });

  it('names every period Stripe has', () => {
    for (const interval of ['day', 'week', 'month', 'year']) {
      expect(priceLine({ ...EUR_20, interval }, 'en')?.interval).toBe(interval);
    }
  });

  it('is absent when there is nothing the page can name', () => {
    expect(priceLine(null, 'en')).toBeNull();
    expect(priceLine({ ...EUR_20, interval: 'quarter' }, 'en')).toBeNull();
    expect(priceLine({ ...EUR_20, currency: 'euros' }, 'en')).toBeNull();
    expect(priceLine(EUR_20, 'en_US')).toBeNull();
  });
});

describe('planPageView', () => {
  it('offers Plus to a company on Free', () => {
    expect(planPageView(overview())).toEqual({
      state: 'free',
      plan: 'free',
      customPlanName: null,
      seats: {
        used: 40,
        limit: 100,
        people: 37,
        pendingInvites: 3,
        full: false,
      },
      offer: 'plus',
      plusSeatLimit: 1000,
      nextPaymentOn: null,
      endsOn: null,
      seatLimitAfterEnd: null,
      noInvitesAfterEnd: false,
      portal: null,
      supportEmail: null,
    });
  });

  it('says so when no seats are left', () => {
    expect(planPageView(overview({ activeUsers: 97 })).seats?.full).toBe(true);
    expect(planPageView(overview({ activeUsers: 96 })).seats?.full).toBe(false);
  });

  it('links a former payer on Free to the earlier invoices', () => {
    const view = planPageView(overview({ hasInvoices: true }));
    expect(view.offer).toBe('plus');
    expect(view.portal).toBe('invoices');
  });

  it('offers nothing where paid plans are unavailable', () => {
    const view = planPageView(overview({ state: 'free-unavailable' }));
    expect(view.offer).toBe('unavailable');
    // The server never says hasInvoices here: no Stripe, no portal.
    expect(view.portal).toBeNull();
  });

  it('shows the next payment of a Plus that renews', () => {
    const view = planPageView(plus('plus'));
    expect(view.plan).toBe('plus');
    expect(view.offer).toBeNull();
    expect(view.nextPaymentOn).toBe('2026-11-11T00:00:00+00:00');
    expect(view.endsOn).toBeNull();
    expect(view.portal).toBe('manage');
    expect(view.seats?.limit).toBe(1000);
  });

  it('shows when a cancelled Plus ends, and no next payment', () => {
    const view = planPageView(
      plus('plus-ending', { cancelsAt: '2026-11-11T00:00:00+00:00' }),
    );
    expect(view.endsOn).toBe('2026-11-11T00:00:00+00:00');
    expect(view.seatLimitAfterEnd).toBe(100);
    expect(view.nextPaymentOn).toBeNull();
    expect(view.portal).toBe('manage');
  });

  it('names no Free limit after the end for a company without a limit', () => {
    const view = planPageView(
      plus('plus-ending', {
        cancelsAt: '2026-11-11T00:00:00+00:00',
        seatLimit: null,
      }),
    );
    expect(view.endsOn).toBe('2026-11-11T00:00:00+00:00');
    expect(view.seatLimitAfterEnd).toBeNull();
    // Only a Plus that ends has a limit after its end.
    expect(planPageView(plus('plus')).seatLimitAfterEnd).toBeNull();
  });

  it("warns a Plus that ends when Free's seats are all taken already", () => {
    const ending = { cancelsAt: '2026-11-11T00:00:00+00:00' };
    const after = (changes: Partial<BillingOverview>): boolean =>
      planPageView(plus('plus-ending', { ...ending, ...changes }))
        .noInvitesAfterEnd;

    // 137 people and 3 invitations against Free's 100.
    expect(after({})).toBe(true);
    // From the limit up, as the server refuses an invitation.
    expect(after({ activeUsers: 96 })).toBe(false);
    expect(after({ activeUsers: 97 })).toBe(true);
    // A company without a limit keeps having none.
    expect(after({ seatLimit: null })).toBe(false);
    // A Plus that renews has nothing to warn about.
    expect(planPageView(plus('plus')).noInvitesAfterEnd).toBe(false);
  });

  it('asks for a new payment method after a failed payment, and names no next payment', () => {
    const view = planPageView(plus('plus-past-due'));
    expect(view.portal).toBe('update-payment');
    // The period end of a past-due subscription is not a payment date.
    expect(view.nextPaymentOn).toBeNull();
    expect(view.endsOn).toBeNull();
  });

  it('names a plan set by hand, and only that', () => {
    const custom = overview({
      state: 'custom',
      planTier: 'enterprise',
      seatLimit: null,
    });
    expect(planPageView(custom)).toMatchObject({
      plan: 'custom',
      customPlanName: 'enterprise',
      seats: null,
      offer: null,
      portal: null,
    });
    // Free without a seat limit is in the custom state, and is still Free.
    expect(planPageView({ ...custom, planTier: 'free' })).toMatchObject({
      plan: 'free',
      customPlanName: null,
      offer: null,
    });
  });

  it('links a plan set by hand to its subscription if one still runs, else to the invoices', () => {
    const custom = overview({
      state: 'custom',
      planTier: 'enterprise',
      hasInvoices: true,
    });
    expect(planPageView(custom).portal).toBe('invoices');
    expect(
      planPageView({ ...custom, periodEndsAt: '2026-11-11T00:00:00+00:00' })
        .portal,
    ).toBe('manage');
  });

  it('has no portal link in any state when the portal cannot be opened', () => {
    for (const state of BILLING_STATES) {
      expect(
        planPageView(plus(state, { hasInvoices: false })).portal,
      ).toBeNull();
    }
  });

  it('passes the support address on, when there is one', () => {
    expect(
      planPageView(overview({ supportEmail: 'support@example.com' }))
        .supportEmail,
    ).toBe('support@example.com');
  });
});
