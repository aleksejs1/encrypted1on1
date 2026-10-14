import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
  type Route,
} from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

/** A new account in its own browser context, activated and on the home page. */
async function activate(
  browser: Browser,
  label: string,
  admin: boolean,
): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  await page.goto(
    `/activate/${createActivationLink(uniqueEmail(label), admin)}`,
  );
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/**
 * GitHub issue #304: the admin "Plan" page belongs to the Cloud deployment.
 * This stack runs without CLOUD_MODE, like a self-hosted instance, so here the
 * tab is absent and the page's address shows "Page not found".
 */
test('a self-hosted instance has no Plan tab and no Plan page', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-admin', true);

  await admin.goto('/admin');
  const tabs = admin.locator('nav.tab-strip');
  await expect(tabs.getByRole('link', { name: 'Templates' })).toBeVisible();
  // The whole strip, as rendered in one go once the identity is known.
  await expect(tabs.locator('.tab')).toHaveText([
    'Users',
    'Reports',
    'Invites',
    'Templates',
  ]);

  // The page asks the server nothing: there the route doesn't exist either.
  const asked: string[] = [];
  admin.on('request', (request) => asked.push(request.url()));
  await admin.goto('/admin/plan');
  await expect(
    admin.getByRole('heading', { name: 'Page not found' }),
  ).toBeVisible();
  await expect(admin.locator('nav.tab-strip')).toHaveCount(0);
  expect(asked.some((url) => url.includes('/api/me'))).toBe(true);
  expect(asked.filter((url) => url.includes('/api/admin/billing'))).toEqual([]);

  // The same for someone who isn't an admin: not "not authorized", which
  // would say the page exists.
  const employee = await activate(browser, 'plan-employee', false);
  await employee.goto('/admin/plan');
  await expect(
    employee.getByRole('heading', { name: 'Page not found' }),
  ).toBeVisible();
});

/** What GET /api/admin/billing answers for a company on Free (BillingController::overview()). */
const FREE_OVERVIEW = {
  state: 'free',
  planTier: 'free',
  seatLimit: 100,
  activeUsers: 1,
  pendingInvites: 0,
  periodEndsAt: null,
  cancelsAt: null,
  hasInvoices: false,
  freeSeatLimit: 100,
  plusSeatLimit: 1000,
  supportEmail: null,
};

/**
 * The Cloud deployment, as far as the browser can tell: this stack's own
 * /api/me answer with `cloudMode` turned on, and the Plan page's API answered
 * here (the real one is a 404 without CLOUD_MODE). The server side of cloud
 * mode has no end-to-end run: that is a manual one (GitHub issue #308).
 */
async function pretendCloud(
  page: Page,
  overview: { status: number; json: unknown },
): Promise<void> {
  // Stripe's own pages, for the buttons that send the admin there.
  await page.route('https://stripe.test/**', (route) =>
    route.fulfill({ contentType: 'text/html', body: '<h1>Stripe</h1>' }),
  );
  await page.route('**/api/me', async (route) => {
    const response = await route.fetch();
    const me = (await response.json()) as Record<string, unknown>;
    await route.fulfill({ response, json: { ...me, cloudMode: true } });
  });
  await page.route('**/api/admin/billing', (route) => route.fulfill(overview));
}

test('in the Cloud deployment the admin panel has a Plan tab and a Plan page', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-cloud-admin', true);
  await pretendCloud(admin, { status: 200, json: FREE_OVERVIEW });

  await admin.goto('/admin');
  const tabs = admin.locator('nav.tab-strip');
  await expect(tabs.locator('.tab')).toHaveText([
    'Users',
    'Reports',
    'Invites',
    'Templates',
    'Plan',
  ]);

  const overviewAsked = admin.waitForResponse('**/api/admin/billing');
  await tabs.getByRole('link', { name: 'Plan' }).click();
  await admin.waitForURL('/admin/plan');
  await overviewAsked;
  await expect(
    admin.getByRole('heading', { name: 'Plan', exact: true }),
  ).toBeVisible();
  await expect(tabs.locator('[aria-current="page"]')).toHaveText('Plan');
  // The app header: the path is among the authenticated pages'.
  await expect(admin.getByRole('button', { name: 'Log out' })).toBeVisible();
  await expect(admin.getByText('Loading')).toHaveCount(0);
  await expect(admin.getByRole('alert')).toHaveCount(0);
});

test('the Plan page says so when its data cannot be loaded', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-error-admin', true);
  await pretendCloud(admin, {
    status: 500,
    json: { error: 'Something went wrong.' },
  });

  await admin.goto('/admin/plan');
  await expect(admin.getByRole('alert')).toHaveText('Something went wrong.');
  await expect(
    admin.getByRole('heading', { name: 'Plan', exact: true }),
  ).toBeVisible();
});

/** What GET /api/admin/billing/plus-price answers for €20 a month, tax on top. */
const PLUS_PRICE = {
  amount: 2000,
  currency: 'eur',
  interval: 'month',
  taxBehavior: 'exclusive',
};

/**
 * An answer held back until the test releases it, so that the page can be
 * seen waiting for it.
 */
function held(answer: { status: number; json: unknown }): {
  handler: (route: Route) => Promise<void>;
  release: () => void;
} {
  let release = (): void => {};
  const released = new Promise<void>((resolve) => {
    release = resolve;
  });
  return {
    handler: async (route) => {
      await released;
      await route.fulfill(answer);
    },
    release: () => release(),
  };
}

/**
 * pretendCloud() with a plan the test changes as it goes, and a count of the
 * times the page read it.
 */
async function changingPlan(
  page: Page,
  first: unknown,
): Promise<{ set: (overview: unknown) => void; reads: () => number }> {
  let overview = first;
  let reads = 0;
  await pretendCloud(page, { status: 200, json: first });
  await page.unroute('**/api/admin/billing');
  await page.route('**/api/admin/billing', (route) => {
    reads++;
    return route.fulfill({ status: 200, json: overview });
  });
  return {
    set: (next) => {
      overview = next;
    },
    reads: () => reads,
  };
}

test('on Free the Plan page offers Plus and sends the admin to Checkout', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-free-admin', true);
  await pretendCloud(admin, {
    status: 200,
    json: { ...FREE_OVERVIEW, activeUsers: 37, pendingInvites: 3 },
  });
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );

  await admin.goto('/admin/plan');
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Free' }),
  ).toBeVisible();
  await expect(
    admin.getByText('40 of 100 seats used: 37 people, 3 pending invitations'),
  ).toBeVisible();
  const plus = admin.getByRole('region', { name: 'Plus', exact: true });
  await expect(plus.getByText('Up to 1,000 seats.')).toBeVisible();
  await expect(
    plus.getByText(
      '€20 per month + VAT, for the whole company, not per person.',
    ),
  ).toBeVisible();
  // A company that never paid has no invoices to show.
  await expect(
    admin.getByRole('button', { name: 'Payment and invoices' }),
  ).toHaveCount(0);
  await expect(admin.getByRole('button', { name: 'Invoices' })).toHaveCount(0);

  // A refusal is shown under the button, which can be pressed again.
  const refusal = held({
    status: 503,
    json: { error: 'Could not start the payment.' },
  });
  await admin.route('**/api/admin/billing/checkout-session', refusal.handler);
  const upgrade = plus.getByRole('button', { name: 'Upgrade to Plus' });
  await upgrade.click();
  await expect(upgrade).toBeDisabled();
  refusal.release();
  await expect(plus.getByRole('alert')).toHaveText(
    'Could not start the payment.',
  );
  await expect(upgrade).toBeEnabled();

  // Stripe's page opens in the same tab.
  await admin.unroute('**/api/admin/billing/checkout-session');
  await admin.route('**/api/admin/billing/checkout-session', (route) =>
    route.fulfill({
      status: 200,
      json: { url: 'https://stripe.test/checkout' },
    }),
  );
  await upgrade.click();
  await admin.waitForURL('https://stripe.test/checkout');
});

test('a Stripe answer without an https address is an error, not a page to go to', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-bad-url-admin', true);
  await pretendCloud(admin, { status: 200, json: FREE_OVERVIEW });
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );
  let url: unknown = 'javascript:alert(1)';
  await admin.route('**/api/admin/billing/checkout-session', (route) =>
    route.fulfill({ status: 200, json: { url } }),
  );

  await admin.goto('/admin/plan');
  const plus = admin.getByRole('region', { name: 'Plus', exact: true });
  const upgrade = plus.getByRole('button', { name: 'Upgrade to Plus' });
  for (const answer of ['javascript:alert(1)', 'http://stripe.test/x', null]) {
    url = answer;
    await upgrade.click();
    await expect(plus.getByRole('alert')).toHaveText(
      'Could not open the Stripe page. Try again.',
    );
    await expect(upgrade).toBeEnabled();
  }
  expect(admin.url()).toMatch(/\/admin\/plan$/);
});

test('back from Stripe, the page shows the plan as it is now', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-back-admin', true);
  const plan = await changingPlan(admin, FREE_OVERVIEW);
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );
  await admin.route('**/api/admin/billing/checkout-session', (route) =>
    route.fulfill({
      status: 200,
      json: { url: 'https://stripe.test/stopped' },
    }),
  );
  // The way to Stripe stopped short (204: the browser stays on the page it
  // was leaving), so the page stays as it was left for Stripe, which is how
  // Back from Stripe brings it back from the back/forward cache too.
  const left = admin.waitForRequest('https://stripe.test/stopped');
  await admin.route('https://stripe.test/stopped', (route) =>
    route.fulfill({ status: 204 }),
  );

  await admin.goto('/admin/plan');
  const upgrade = admin.getByRole('button', { name: 'Upgrade to Plus' });
  await upgrade.click();
  await left;
  await expect(upgrade).toBeDisabled();

  // The admin paid there and came back with the Back button. The event is
  // simulated: whether a browser really brings the page back from the cache
  // is the browser's business, checked by hand against the Stripe sandbox.
  plan.set(PLUS_OVERVIEW);
  const reloaded = admin.waitForEvent('load');
  // As a string: this file is typed without the DOM's types.
  await admin.evaluate(
    "window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true }))",
  );
  await reloaded;
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Plus' }),
  ).toBeVisible();
  await expect(
    admin.getByRole('button', { name: 'Payment and invoices' }),
  ).toBeEnabled();
  await expect(upgrade).toHaveCount(0);
});

test('the Plus offer keeps its button when the price cannot be read', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-price-admin', true);
  await pretendCloud(admin, { status: 200, json: FREE_OVERVIEW });
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: null }),
  );

  await admin.goto('/admin/plan');
  const plus = admin.getByRole('region', { name: 'Plus', exact: true });
  await expect(
    plus.getByText('The price is shown on the payment page.'),
  ).toBeVisible();
  await expect(
    plus.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toBeEnabled();

  // The same when the request for it fails.
  await admin.unroute('**/api/admin/billing/plus-price');
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 500, json: { error: 'Something went wrong.' } }),
  );
  await admin.reload();
  await expect(
    plus.getByText('The price is shown on the payment page.'),
  ).toBeVisible();
  await expect(admin.getByRole('alert')).toHaveCount(0);
});

test('a company back on Free sees its invoices, and the price as Stripe words its tax', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-invoices-admin', true);
  await pretendCloud(admin, {
    status: 200,
    json: {
      ...FREE_OVERVIEW,
      hasInvoices: true,
      supportEmail: 'billing@example.com',
    },
  });
  let taxBehavior = 'inclusive';
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: { ...PLUS_PRICE, taxBehavior } }),
  );

  await admin.goto('/admin/plan');
  const plus = admin.getByRole('region', { name: 'Plus', exact: true });
  await expect(
    plus.getByText(
      '€20 per month, VAT included, for the whole company, not per person.',
    ),
  ).toBeVisible();
  await expect(admin.getByRole('button', { name: 'Invoices' })).toBeVisible();
  await expect(
    admin.getByText(
      "Opens a page hosted by Stripe with the company's invoices.",
    ),
  ).toBeVisible();
  await expect(
    admin.getByRole('link', { name: 'billing@example.com' }),
  ).toBeVisible();

  // Stripe's "unspecified": charged as it is, so nothing is said about tax.
  taxBehavior = 'unspecified';
  await admin.reload();
  await expect(
    plus.getByText('€20 per month, for the whole company, not per person.'),
  ).toBeVisible();
});

test('a server without Stripe offers no Plus', async ({ browser }) => {
  const admin = await activate(browser, 'plan-unavailable-admin', true);
  await pretendCloud(admin, {
    status: 200,
    json: { ...FREE_OVERVIEW, state: 'free-unavailable' },
  });
  const priceAsked: string[] = [];
  admin.on('request', (request) => {
    if (request.url().includes('plus-price')) priceAsked.push(request.url());
  });

  await admin.goto('/admin/plan');
  await expect(
    admin.getByText("Paid plans aren't available on this server yet."),
  ).toBeVisible();
  await expect(
    admin.getByRole('region', { name: 'Plus', exact: true }),
  ).toHaveCount(0);
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);
  expect(priceAsked).toEqual([]);
});

/** A company on Plus, paid up to 11 November 2026. */
const PLUS_OVERVIEW = {
  ...FREE_OVERVIEW,
  state: 'plus',
  planTier: 'plus',
  seatLimit: 1000,
  activeUsers: 137,
  pendingInvites: 3,
  periodEndsAt: '2026-11-11T12:00:00+00:00',
  hasInvoices: true,
  supportEmail: 'billing@example.com',
};

test('on Plus the Plan page shows the next payment and opens the portal', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-plus-admin', true);
  await pretendCloud(admin, { status: 200, json: PLUS_OVERVIEW });

  await admin.goto('/admin/plan');
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Plus' }),
  ).toBeVisible();
  await expect(
    admin.getByText(
      '140 of 1,000 seats used: 137 people, 3 pending invitations',
    ),
  ).toBeVisible();
  await expect(admin.getByText('Next payment: 11.11.2026')).toBeVisible();
  await expect(
    admin.getByRole('region', { name: 'Plus', exact: true }),
  ).toHaveCount(0);
  await expect(admin.getByRole('alert')).toHaveCount(0);
  await expect(
    admin.getByRole('link', { name: 'billing@example.com' }),
  ).toHaveAttribute('href', 'mailto:billing@example.com');

  const session = held({
    status: 200,
    json: { url: 'https://stripe.test/portal' },
  });
  await admin.route('**/api/admin/billing/portal-session', session.handler);
  const portal = admin.getByRole('button', { name: 'Payment and invoices' });
  await portal.click();
  await expect(portal).toBeDisabled();
  session.release();
  await admin.waitForURL('https://stripe.test/portal');
});

test('a cancelled Plus says when it ends and what happens then', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-ending-admin', true);
  await pretendCloud(admin, {
    status: 200,
    json: {
      ...PLUS_OVERVIEW,
      state: 'plus-ending',
      cancelsAt: '2026-11-11T12:00:00+00:00',
    },
  });

  await admin.goto('/admin/plan');
  await expect(
    admin.getByText(
      'Plus ends on 11.11.2026. After that the company moves to Free (up to 100 seats).',
    ),
  ).toBeVisible();
  // 140 seats are used, more than Free's 100.
  await expect(
    admin.getByText(
      "Everyone stays, but you won't be able to invite new people.",
    ),
  ).toBeVisible();
  await expect(
    admin.getByText(
      'Changed your mind? You can undo the cancellation on the payment page.',
    ),
  ).toBeVisible();
  await expect(admin.getByText(/^Next payment/)).toHaveCount(0);
  await expect(
    admin.getByRole('button', { name: 'Payment and invoices' }),
  ).toBeVisible();
  // A company without a seat limit keeps having none.
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', (route) =>
    route.fulfill({
      status: 200,
      json: {
        ...PLUS_OVERVIEW,
        state: 'plus-ending',
        seatLimit: null,
        cancelsAt: '2026-11-11T12:00:00+00:00',
      },
    }),
  );
  await admin.reload();
  await expect(
    admin.getByText(
      'Plus ends on 11.11.2026. After that the company moves to Free, still without a seat limit.',
    ),
  ).toBeVisible();
  await expect(admin.getByText(/invite new people/)).toHaveCount(0);
});

test('a failed payment is a warning with a way to fix it', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-past-due-admin', true);
  await pretendCloud(admin, {
    status: 200,
    json: { ...PLUS_OVERVIEW, state: 'plus-past-due' },
  });

  await admin.goto('/admin/plan');
  await expect(admin.getByRole('alert')).toHaveText(
    "The last payment didn't go through. Update your payment method to stay on Plus.",
  );
  await expect(admin.getByText(/^Next payment/)).toHaveCount(0);

  const refusal = held({ status: 409, json: { error: 'Contact support.' } });
  await admin.route('**/api/admin/billing/portal-session', refusal.handler);
  const update = admin.getByRole('button', { name: 'Update payment method' });
  await update.click();
  await expect(update).toBeDisabled();
  refusal.release();
  await expect(admin.getByRole('alert')).toHaveText([
    "The last payment didn't go through. Update your payment method to stay on Plus.",
    'Contact support.',
  ]);
  await expect(update).toBeEnabled();
});

test('a plan set by hand shows its name and its terms', async ({ browser }) => {
  const admin = await activate(browser, 'plan-custom-admin', true);
  await pretendCloud(admin, {
    status: 200,
    json: {
      ...FREE_OVERVIEW,
      state: 'custom',
      planTier: 'Enterprise',
      seatLimit: null,
      hasInvoices: true,
    },
  });

  await admin.goto('/admin/plan');
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Enterprise' }),
  ).toBeVisible();
  await expect(
    admin.getByText("Your plan's terms were agreed separately."),
  ).toBeVisible();
  // No limit, nothing to count against.
  await expect(admin.getByText(/seats used/)).toHaveCount(0);
  await expect(admin.getByRole('button', { name: 'Invoices' })).toBeVisible();
  await expect(
    admin.getByRole('region', { name: 'Plus', exact: true }),
  ).toHaveCount(0);
  await expect(admin.getByText(/^Questions about your plan/)).toHaveCount(0);

  // Free without a seat limit is in the same state, and is still Free.
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', (route) =>
    route.fulfill({
      status: 200,
      json: { ...FREE_OVERVIEW, state: 'custom', seatLimit: null },
    }),
  );
  await admin.reload();
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Free' }),
  ).toBeVisible();
  await expect(
    admin.getByText("Your plan's terms were agreed separately."),
  ).toHaveCount(0);
  await expect(
    admin.getByRole('region', { name: 'Plus', exact: true }),
  ).toHaveCount(0);
});

/**
 * For GitHub issue #307, where the page waits for the webhook: Playwright's
 * clock, stopped, so that thirty seconds pass in an instant by runFor() and
 * no poll fires on its own while a slow step of the test runs. The requests
 * themselves are real.
 */
async function stopClock(page: Page): Promise<void> {
  const now = Date.now();
  await page.clock.install({ time: now });
  await page.clock.pauseAt(now + 1_000);
}

/** Back from a paid Checkout, the company on `first`, and waiting. */
async function openWaiting(
  page: Page,
  first: unknown,
): Promise<{
  plan: Awaited<ReturnType<typeof changingPlan>>;
  status: Locator;
}> {
  const plan = await changingPlan(page, first);
  await stopClock(page);
  await page.goto('/admin/plan?checkout=success');
  const status = page.locator('main').getByRole('status');
  await expect(status).toHaveText(
    'Payment received. Switching your company to Plus…',
  );
  return { plan, status };
}

test('back from Checkout the page waits for Plus, and says thank you', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-return-admin', true);
  const { plan, status } = await openWaiting(admin, FREE_OVERVIEW);
  // Nothing to pay with meanwhile, and no "Your plan: Free" either.
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);
  await expect(admin.getByRole('heading', { name: /^Your plan/ })).toHaveCount(
    0,
  );

  // The page reads the plan again two seconds after each answer.
  expect(plan.reads()).toBe(1);
  await admin.clock.runFor(2_000);
  await expect.poll(plan.reads).toBe(2);

  // A reload while waiting keeps waiting: the parameter is still there.
  expect(new URL(admin.url()).search).toBe('?checkout=success');
  await admin.reload();
  await expect(status).toHaveText(
    'Payment received. Switching your company to Plus…',
  );
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);

  // The webhook arrived.
  plan.set(PLUS_OVERVIEW);
  await admin.clock.runFor(2_000);
  await expect(status).toHaveText("You're on Plus. Thank you.");
  const heading = admin.getByRole('heading', { name: 'Your plan: Plus' });
  await expect(heading).toBeFocused();
  await expect(admin.getByText('Next payment: 11.11.2026')).toBeVisible();
  await expect.poll(() => new URL(admin.url()).search).toBe('');
  expect(new URL(admin.url()).pathname).toBe('/admin/plan');

  // And stops reading.
  const reads = plan.reads();
  await admin.clock.runFor(10_000);
  expect(plan.reads()).toBe(reads);
});

test('when Plus does not come, the page stops waiting and still offers no second payment', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-gave-up-admin', true);
  const { plan, status } = await openWaiting(admin, {
    ...FREE_OVERVIEW,
    supportEmail: 'billing@example.com',
  });
  for (let second = 2; second <= 28; second += 2) {
    await admin.clock.runFor(2_000);
    await expect.poll(plan.reads).toBe(second / 2 + 1);
  }
  await admin.clock.runFor(2_000);
  await expect(status).toHaveText(
    "If you just paid: the plan hasn't updated yet. This can take a few minutes. You don't need to pay again.",
  );
  await expect.poll(() => new URL(admin.url()).search).toBe('');
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Free' }),
  ).toBeVisible();
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);
  await expect(
    admin.getByText("If it still hasn't changed in an hour, write to"),
  ).toBeVisible();
  await expect(
    admin.getByText(
      "Didn't pay? Reload the page to see the Upgrade button again.",
    ),
  ).toBeVisible();
  await expect(
    admin.getByRole('link', { name: 'billing@example.com' }),
  ).toHaveCount(1);
  // No more reading by itself.
  const reads = plan.reads();
  await admin.clock.runFor(10_000);
  expect(plan.reads()).toBe(reads);

  // "Check again" reads once; a failure is said under the button.
  const check = admin.getByRole('button', { name: 'Check again' });
  const failure = held({
    status: 500,
    json: { error: 'Something went wrong.' },
  });
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', failure.handler);
  await check.click();
  failure.release();
  await expect(admin.getByRole('alert')).toHaveText(
    'Could not check the plan. Try again.',
  );
  // Never disabled, so focus stays where it was.
  await expect(check).toBeFocused();

  // Still Free: said, so that the press isn't taken for nothing.
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', (route) =>
    route.fulfill({ status: 200, json: FREE_OVERVIEW }),
  );
  await check.click();
  await expect(status).toContainText(
    "Checked just now: the plan hasn't changed yet.",
  );
  await expect(admin.getByRole('alert')).toHaveCount(0);
  await expect(check).toBeFocused();
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);

  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', (route) =>
    route.fulfill({ status: 200, json: PLUS_OVERVIEW }),
  );
  await check.click();
  await expect(status).toHaveText("You're on Plus. Thank you.");
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Plus' }),
  ).toBeFocused();
  await expect(check).toHaveCount(0);
  await expect(admin.getByRole('alert')).toHaveCount(0);
});

test('a Checkout left without paying says nothing was charged', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-cancelled-admin', true);
  const plan = await changingPlan(admin, FREE_OVERVIEW);
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );
  await stopClock(admin);

  await admin.goto('/admin/plan?checkout=cancelled');
  await expect(admin.locator('main').getByRole('status')).toHaveText(
    "The payment wasn't completed. You haven't been charged.",
  );
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toBeEnabled();
  await expect.poll(() => new URL(admin.url()).search).toBe('');
  await admin.clock.runFor(10_000);
  expect(plan.reads()).toBe(1);
});

test('back from the portal the page reads the plan again for a while, silently', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-portal-admin', true);
  const plan = await changingPlan(admin, PLUS_OVERVIEW);
  await stopClock(admin);

  await admin.goto('/admin/plan?portal=return');
  await expect(admin.getByText('Next payment: 11.11.2026')).toBeVisible();

  // Cancelled there: the webhook comes a few seconds later.
  plan.set({
    ...PLUS_OVERVIEW,
    state: 'plus-ending',
    cancelsAt: '2026-11-11T12:00:00+00:00',
  });
  await admin.clock.runFor(2_000);
  await expect(admin.getByText(/^Plus ends on 11.11.2026/)).toBeVisible();
  await expect(admin.locator('main').getByRole('status')).toHaveText('');
  expect(new URL(admin.url()).search).toBe('?portal=return');

  await expect.poll(plan.reads).toBe(2);
  for (let second = 4; second <= 8; second += 2) {
    await admin.clock.runFor(2_000);
    await expect.poll(plan.reads).toBe(second / 2 + 1);
  }
  // Ten seconds: done, without one more read.
  await admin.clock.runFor(2_000);
  await expect.poll(() => new URL(admin.url()).search).toBe('');
  const reads = plan.reads();
  expect(reads).toBe(5);
  await admin.clock.runFor(10_000);
  expect(plan.reads()).toBe(reads);
  await expect(admin.locator('main').getByRole('status')).toHaveText('');
});

test('leaving the page while it waits stops the waiting and leaves the new address alone', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-leave-admin', true);
  const { plan } = await openWaiting(admin, FREE_OVERVIEW);
  // Within the app, as router.svelte.ts's navigate() does: the admin tabs
  // are plain links, which would load a new document and prove nothing.
  await admin.evaluate(
    "history.pushState(null, '', '/admin/invites?x=1'); window.dispatchEvent(new PopStateEvent('popstate'))",
  );
  await expect(
    admin.getByRole('heading', { name: 'Invites', exact: true }),
  ).toBeVisible();

  const reads = plan.reads();
  await admin.clock.runFor(40_000);
  expect(plan.reads()).toBe(reads);
  expect(new URL(admin.url()).pathname).toBe('/admin/invites');
  expect(new URL(admin.url()).search).toBe('?x=1');
});

test('a refusal while the page waits ends the waiting', async ({ browser }) => {
  const admin = await activate(browser, 'plan-refused-admin', true);
  const { plan, status } = await openWaiting(admin, FREE_OVERVIEW);
  // The admin role was taken away meanwhile.
  await admin.unroute('**/api/admin/billing');
  let refusals = 0;
  await admin.route('**/api/admin/billing', (route) => {
    refusals++;
    return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
  });
  await admin.clock.runFor(2_000);
  await expect(admin.getByRole('alert')).toHaveText('Access denied.');
  await expect(status).toHaveText('');
  await admin.clock.runFor(10_000);
  expect(refusals).toBe(1);
  expect(plan.reads()).toBe(1);
});

test('a wait that ends on a plan other than Plus shows that plan, without thanks', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-other-end-admin', true);
  const { plan, status } = await openWaiting(admin, FREE_OVERVIEW);
  // An operator set the plan by hand meanwhile.
  plan.set({
    ...FREE_OVERVIEW,
    state: 'custom',
    planTier: 'Enterprise',
    seatLimit: null,
  });
  await admin.clock.runFor(2_000);
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Enterprise' }),
  ).toBeFocused();
  await expect(status).toHaveText('');
  await expect.poll(() => new URL(admin.url()).search).toBe('');
});

test('a read that hangs neither holds up the end of the wait nor undoes a later one', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-hang-admin', true);
  const { status } = await openWaiting(admin, FREE_OVERVIEW);
  // The next read gets no answer for as long as the test runs.
  const hanging = held({ status: 200, json: FREE_OVERVIEW });
  const asked = admin.waitForRequest('**/api/admin/billing');
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', hanging.handler);
  await admin.clock.runFor(2_000);
  await asked;

  await admin.clock.runFor(28_000);
  await expect(status).toContainText("If you just paid: the plan hasn't");
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);

  // "Check again" hangs too, and can be pressed again; the second press
  // finds Plus. The hung reads, still Free and failed, are older: they
  // change nothing.
  const check = admin.getByRole('button', { name: 'Check again' });
  const hangingCheck = held({
    status: 500,
    json: { error: 'Something went wrong.' },
  });
  const checkAsked = admin.waitForRequest('**/api/admin/billing');
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', hangingCheck.handler);
  await check.click();
  await checkAsked;
  await expect(check).toBeEnabled();
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', (route) =>
    route.fulfill({ status: 200, json: PLUS_OVERVIEW }),
  );
  await check.click();
  await expect(status).toHaveText("You're on Plus. Thank you.");
  // The older reads were stopped when the newer ones began: their answers
  // would reach nothing, and are never sent.
  await admin.clock.runFor(2_000);
  await expect(admin.getByRole('alert')).toHaveCount(0);
  await expect(
    admin.getByRole('heading', { name: 'Your plan: Plus' }),
  ).toBeVisible();
  await expect(
    admin.getByRole('button', { name: 'Upgrade to Plus' }),
  ).toHaveCount(0);
});

test('the end of the portal wait leaves the address alone while the browser leaves for Stripe', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-portal-leaving-admin', true);
  await changingPlan(admin, { ...FREE_OVERVIEW, hasInvoices: true });
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );
  await admin.route('**/api/admin/billing/checkout-session', (route) =>
    route.fulfill({ status: 200, json: { url: 'https://stripe.test/slow' } }),
  );
  // Stripe's page doesn't come (204: the browser stays where it was), so the
  // page is still there, on its way to Stripe as far as it knows.
  const left = admin.waitForRequest('https://stripe.test/slow');
  await admin.route('https://stripe.test/slow', (route) =>
    route.fulfill({ status: 204 }),
  );
  await stopClock(admin);

  await admin.goto('/admin/plan?portal=return');
  await admin.getByRole('button', { name: 'Upgrade to Plus' }).click();
  await left;
  await admin.clock.runFor(10_000);
  expect(new URL(admin.url()).search).toBe('?portal=return');
});

test('a redirect that fails after the portal wait ended drops the parameter then', async ({
  browser,
}) => {
  const admin = await activate(browser, 'plan-portal-failed-admin', true);
  await changingPlan(admin, { ...FREE_OVERVIEW, hasInvoices: true });
  await admin.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );
  const refusal = held({ status: 503, json: { error: 'Try again later.' } });
  await admin.route('**/api/admin/billing/checkout-session', refusal.handler);
  await stopClock(admin);

  await admin.goto('/admin/plan?portal=return');
  const asked = admin.waitForRequest('**/api/admin/billing/checkout-session');
  await admin.getByRole('button', { name: 'Upgrade to Plus' }).click();
  await asked;
  await admin.clock.runFor(10_000);
  expect(new URL(admin.url()).search).toBe('?portal=return');
  refusal.release();
  await expect(admin.getByRole('alert')).toHaveText('Try again later.');
  await expect.poll(() => new URL(admin.url()).search).toBe('');
});
