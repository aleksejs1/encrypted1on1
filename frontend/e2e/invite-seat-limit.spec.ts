import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test';
import { FREE_OVERVIEW } from './helpers/billing.js';
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
 * The e2e stack runs without CLOUD_MODE, so /api/me is answered here with
 * what the case needs. The account form shows only under the "invite"
 * registration mode, set here too rather than on the company every e2e
 * account shares.
 */
async function withDeployment(page: Page, cloudMode: boolean): Promise<void> {
  await page.route('**/api/me', async (route) => {
    const response = await route.fetch();
    const me = (await response.json()) as Record<string, unknown>;
    await route.fulfill({
      response,
      json: { ...me, cloudMode, registrationMode: 'invite' },
    });
  });
}

/**
 * The e2e company has no seat limit either: the invite is answered with
 * InviteController's refusal for want of a seat.
 */
async function withNoSeatsLeft(page: Page, cloudMode: boolean): Promise<void> {
  await withDeployment(page, cloudMode);
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

/**
 * GitHub issue #310: the Plan page's seat line above the Invites tab's form.
 * The Plan page's API exists only in the Cloud deployment, so it is answered
 * here; `seats` is read on every request, so a test can change it between
 * two reads.
 */
async function withSeats(
  page: Page,
  seats: {
    seatLimit: number | null;
    activeUsers: number;
    pendingInvites: number;
  },
): Promise<{ reads: () => number }> {
  let reads = 0;
  await page.route('**/api/admin/billing', (route) => {
    reads += 1;
    return route.fulfill({ json: { ...FREE_OVERVIEW, ...seats } });
  });
  return { reads: () => reads };
}

/**
 * Lets the page take in an answer that has just arrived: a response is seen
 * here before the page's code has read it. For checks that something did
 * not change.
 */
async function settled(page: Page): Promise<void> {
  await page.evaluate(
    () => new Promise<void>((resolve) => setTimeout(resolve, 100)),
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
  const seats = { seatLimit: 10, activeUsers: 9, pendingInvites: 0 };
  await withSeats(admin, seats);
  // An expired invite of this admin's, whose Re-send is refused the same way.
  const expiredEmail = uniqueEmail('seat-cloud-expired');
  try {
    createActivationLink(expiredEmail);
    expireAsInvite(expiredEmail, adminEmail);
    await admin.goto('/admin/invites');
    const line = admin.locator('[data-seat-usage]');
    await expect(line).toHaveText('9 of 10 seats used: 9 people');
    // Someone else took the last seat meanwhile: the refusal reads the seats
    // again (GitHub issue #310).
    seats.pendingInvites = 1;
    const reread = admin.waitForResponse('**/api/admin/billing');
    await admin
      .getByRole('button', { name: `Re-send invite to ${expiredEmail}` })
      .click();
    const resent = admin.locator('[data-resend-error]');
    await expect(resent).toContainText(NO_SEATS);
    await reread;
    await expect(line).toContainText(
      'All seats are taken: new invitations are refused.',
    );
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

test('in the Cloud deployment the Invites tab shows the seats used, read again after an invite', async ({
  browser,
}) => {
  const admin = await activate(browser, uniqueEmail('seat-usage'), true);
  await withDeployment(admin, true);
  const seats = { seatLimit: 10, activeUsers: 2, pendingInvites: 1 };
  await withSeats(admin, seats);

  await admin.goto('/admin/invites');
  const line = admin.locator('[data-seat-usage]');
  await expect(line).toHaveText(
    '3 of 10 seats used: 2 people, 1 pending invitation',
  );
  await expect(line.getByRole('link')).toHaveCount(0);

  // A real invite: the e2e company has no limit of its own.
  seats.pendingInvites = 2;
  const reread = admin.waitForResponse('**/api/admin/billing');
  await admin.locator('#invite-email').fill(uniqueEmail('seat-usage-invitee'));
  await admin.getByRole('button', { name: 'Send invite', exact: true }).click();
  await reread;
  await expect(line).toHaveText(
    '4 of 10 seats used: 2 people, 2 pending invitations',
  );
});

test('an invite refused for want of a seat reads the seats again: the line says why and links to the Plan page', async ({
  browser,
}) => {
  const admin = await activate(browser, uniqueEmail('seat-usage-full'), true);
  await withNoSeatsLeft(admin, true);
  const seats = { seatLimit: 10, activeUsers: 9, pendingInvites: 0 };
  await withSeats(admin, seats);

  await admin.goto('/admin/invites');
  const line = admin.locator('[data-seat-usage]');
  await expect(line).toHaveText('9 of 10 seats used: 9 people');

  // Someone else took the last seat meanwhile.
  seats.pendingInvites = 1;
  const reread = admin.waitForResponse('**/api/admin/billing');
  await expect(await invite(admin)).toContainText(NO_SEATS);
  await reread;
  await expect(line).toContainText(
    '10 of 10 seats used: 9 people, 1 pending invitation',
  );
  await expect(line).toContainText(
    'All seats are taken: new invitations are refused.',
  );
  await expect(line.getByRole('link', { name: 'View plan' })).toHaveAttribute(
    'href',
    '/admin/plan',
  );
  await expect(admin.locator('#invite-email')).toBeVisible();
});

test('the Invites tab shows no seat line for a company without a limit, or on a self-hosted instance', async ({
  browser,
}) => {
  const cloud = await activate(browser, uniqueEmail('seat-usage-none'), true);
  await withDeployment(cloud, true);
  await withSeats(cloud, {
    seatLimit: null,
    activeUsers: 2,
    pendingInvites: 0,
  });
  const read = cloud.waitForResponse('**/api/admin/billing');
  await cloud.goto('/admin/invites');
  await read;
  await settled(cloud);
  await expect(cloud.locator('#invite-email')).toBeVisible();
  await expect(cloud.locator('[data-seat-usage]')).toBeEmpty();

  const selfHosted = await activate(
    browser,
    uniqueEmail('seat-usage-self'),
    true,
  );
  await withDeployment(selfHosted, false);
  const none = await withSeats(selfHosted, {
    seatLimit: 10,
    activeUsers: 2,
    pendingInvites: 0,
  });
  // The seats would be asked for together with the list.
  const listed = selfHosted.waitForResponse('**/api/admin/invites');
  await selfHosted.goto('/admin/invites');
  await listed;
  await settled(selfHosted);
  await expect(selfHosted.locator('[data-seat-usage]')).toBeEmpty();
  expect(none.reads()).toBe(0);
});
