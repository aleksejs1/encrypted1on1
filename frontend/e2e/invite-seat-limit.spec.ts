import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test';
import {
  createActivationLink,
  expireAsInvite,
  removeInvite,
  uniqueEmail,
} from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  // The routed /api/me fetches the real answer first: one still on its way
  // when the context closes would fail the test.
  await Promise.all(
    browser.contexts().map(async (context) => {
      for (const page of context.pages()) {
        await page.unrouteAll({ behavior: 'ignoreErrors' });
      }
      await context.close();
    }),
  );
});

/** A new account in its own browser context, activated and on the home page. */
async function activate(
  browser: Browser,
  email: string,
  admin: boolean,
): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  await page.goto(`/activate/${createActivationLink(email, admin)}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

const NO_SEATS = 'The company has no seats left on its current plan.';
const ASK_ADMIN = "Ask your company's administrator to upgrade the plan.";

/**
 * The e2e company has no seat limit and runs without CLOUD_MODE, so both are
 * answered here: /api/me with what the case needs, the invite with
 * InviteController's refusal for want of a seat. The account form shows
 * only under the "invite" registration mode, set here too rather than on
 * the company every e2e account shares.
 */
async function withNoSeatsLeft(page: Page, cloudMode: boolean): Promise<void> {
  await page.route('**/api/me', async (route) => {
    const response = await route.fetch();
    const me = (await response.json()) as Record<string, unknown>;
    await route.fulfill({
      response,
      json: { ...me, cloudMode, registrationMode: 'invite' },
    });
  });
  await page.route('**/api/invites', (route) =>
    route.request().method() !== 'POST'
      ? route.continue()
      : route.fulfill({
          status: 400,
          json: {
            error: NO_SEATS,
            code: 'seat_limit_reached',
          },
        }),
  );
}

/** Sends an invite and returns the form's alert (the account page has others). */
async function invite(page: Page): Promise<Locator> {
  await page.locator('#invite-email').fill(uniqueEmail('seat-invitee'));
  await page.getByRole('button', { name: 'Send invite', exact: true }).click();
  return page
    .locator('form', { has: page.locator('#invite-email') })
    .getByRole('alert');
}

/** GitHub issue #309: the refusal says what to do next, to whom it can. */
test('in the Cloud deployment an admin out of seats gets a link to the Plan page', async ({
  browser,
}) => {
  const adminEmail = uniqueEmail('seat-cloud-admin');
  const admin = await activate(browser, adminEmail, true);
  await withNoSeatsLeft(admin, true);
  // An expired invite of this admin's, whose Re-send is refused the same way.
  const expiredEmail = uniqueEmail('seat-cloud-expired');
  try {
    createActivationLink(expiredEmail);
    expireAsInvite(expiredEmail, adminEmail);
    await admin.goto('/admin/invites');
    await admin
      .getByRole('button', { name: `Re-send invite to ${expiredEmail}` })
      .click();
    const resent = admin.locator('[data-resend-error]');
    await expect(resent).toContainText(NO_SEATS);
    await expect(
      resent.getByRole('link', { name: 'View plan' }),
    ).toHaveAttribute('href', '/admin/plan');
  } finally {
    // The refused re-send leaves the invite expired, with its Re-send button.
    removeInvite(expiredEmail);
  }

  const alert = await invite(admin);
  await expect(alert).toContainText(NO_SEATS);
  await expect(alert).not.toContainText(ASK_ADMIN);
  // Only where it leads: the Plan page itself needs the Cloud deployment's API.
  await expect(alert.getByRole('link', { name: 'View plan' })).toHaveAttribute(
    'href',
    '/admin/plan',
  );
});

test('in the Cloud deployment an employee out of seats is told to ask an admin', async ({
  browser,
}) => {
  const employee = await activate(
    browser,
    uniqueEmail('seat-cloud-employee'),
    false,
  );
  await withNoSeatsLeft(employee, true);

  await employee.goto('/account');
  const alert = await invite(employee);
  await expect(alert).toHaveText(`${NO_SEATS} ${ASK_ADMIN}`);
  await expect(alert.getByRole('link')).toHaveCount(0);
});

test('a self-hosted instance shows only the error, to an admin and an employee', async ({
  browser,
}) => {
  const admin = await activate(browser, uniqueEmail('seat-self-admin'), true);
  await withNoSeatsLeft(admin, false);
  await admin.goto('/admin/invites');
  await expect(await invite(admin)).toHaveText(NO_SEATS);

  const employee = await activate(
    browser,
    uniqueEmail('seat-self-employee'),
    false,
  );
  await withNoSeatsLeft(employee, false);
  await employee.goto('/account');
  await expect(await invite(employee)).toHaveText(NO_SEATS);
});
