import { test, expect, type Page } from '@playwright/test';
import { FREE_OVERVIEW, PLUS_PRICE } from './helpers/billing.js';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

/** How far the page scrolls sideways: 0 when it fits the screen. */
function sidewaysOverflow(page: Page): Promise<number> {
  return page.evaluate(
    'document.documentElement.scrollWidth - window.innerWidth',
  );
}

/**
 * GitHub issue #330: on a phone in Russian the admin tab strip was wider than
 * the screen and the whole page scrolled sideways. The Cloud deployment's five
 * tabs, as admin-plan.spec.ts pretends it: this stack runs without CLOUD_MODE.
 */
test('the admin and report tab strips fit a phone screen', async ({
  browser,
}) => {
  const page = await (
    await browser.newContext({ viewport: { width: 375, height: 740 } })
  ).newPage();
  await page.route('**/api/me', async (route) => {
    const response = await route.fetch();
    const me = (await response.json()) as Record<string, unknown>;
    await route.fulfill({ response, json: { ...me, cloudMode: true } });
  });
  await page.route('**/api/admin/billing', (route) =>
    route.fulfill({ status: 200, json: FREE_OVERVIEW }),
  );
  await page.route('**/api/admin/billing/plus-price', (route) =>
    route.fulfill({ status: 200, json: PLUS_PRICE }),
  );

  await page.goto(
    `/activate/${createActivationLink(uniqueEmail('tabs-phone'), true)}?lang=ru`,
  );
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.locator('form button[type="submit"]').click();
  await page.waitForURL('/');

  for (const path of [
    '/admin',
    '/admin/reports',
    '/admin/invites',
    '/admin/templates',
    '/admin/templates/new',
    '/admin/plan',
    '/report',
    '/report/notes',
  ]) {
    await page.goto(path);
    await expect(page.locator('nav.tab-strip')).toBeVisible();
    expect(await sidewaysOverflow(page), path).toBeLessThanOrEqual(0);
  }
  // Russian, the longest labels, with all five tabs.
  await page.goto('/admin/plan');
  await expect(page.locator('nav.tab-strip .tab')).toHaveText([
    'Пользователи',
    'Отчёты',
    'Приглашения',
    'Шаблоны',
    'Тариф',
  ]);
});
