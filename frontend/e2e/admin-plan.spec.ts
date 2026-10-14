import { test, expect, type Browser, type Page } from '@playwright/test';
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
  await expect(admin.getByRole('heading', { name: 'Plan' })).toBeVisible();
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
  await expect(admin.getByRole('heading', { name: 'Plan' })).toBeVisible();
});
