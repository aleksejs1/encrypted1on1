import {
  test,
  expect,
  type Browser,
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
  let overview: unknown = FREE_OVERVIEW;
  await pretendCloud(admin, { status: 200, json: FREE_OVERVIEW });
  await admin.unroute('**/api/admin/billing');
  await admin.route('**/api/admin/billing', (route) =>
    route.fulfill({ status: 200, json: overview }),
  );
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
  overview = PLUS_OVERVIEW;
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
