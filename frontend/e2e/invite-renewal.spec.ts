import { test, expect, type Browser, type Page } from '@playwright/test';
import {
  createActivationLink,
  expireAsInvite,
  uniqueEmail,
} from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

async function activate(
  browser: Browser,
  email: string,
  token: string,
): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  await page.goto(`/activate/${token}`);
  await expect(page.getByText(email)).toBeVisible();
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/**
 * GitHub issue #169: an invitee on an expired link asks for a new invite, and
 * the admin re-sends it from the invites list.
 */
test('an expired invite can be renewed from the link and re-sent by an admin', async ({
  browser,
}) => {
  const adminEmail = uniqueEmail('renewal-admin');
  const admin = await activate(
    browser,
    adminEmail,
    createActivationLink(adminEmail, true),
  );
  const inviteeEmail = uniqueEmail('renewal-invitee');
  const token = createActivationLink(inviteeEmail);
  expireAsInvite(inviteeEmail, adminEmail);

  const invitee = await (await browser.newContext()).newPage();
  await invitee.goto(`/activate/${token}`);
  await expect(
    invitee.getByText('This activation link has expired.', { exact: false }),
  ).toBeVisible();
  await expect(invitee.locator('#act-password')).toHaveCount(0);
  await invitee.getByRole('button', { name: 'Request new invitation' }).click();
  // Not the connection banner's live region, which is always on the page.
  const confirmation = invitee
    .getByRole('status')
    .filter({ hasText: 'Request sent.' });
  await expect(confirmation).toContainText('Request sent.');
  // The button is gone; focus moves to the outcome instead of <body>.
  await expect(confirmation).toBeFocused();
  await expect(
    invitee.getByRole('button', { name: 'Request new invitation' }),
  ).toHaveCount(0);

  // The request survives a reload, so the button doesn't come back.
  await invitee.reload();
  await expect(confirmation).toBeVisible();

  await admin.goto('/admin/invites');
  const row = admin.getByRole('row').filter({ hasText: inviteeEmail });
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Expired');
  await expect(row).toContainText('Renewal requested');
  await row
    .getByRole('button', { name: `Re-send invite to ${inviteeEmail}` })
    .click();
  const resent = admin
    .getByRole('status')
    .filter({ hasText: `New invitation sent to ${inviteeEmail}.` });
  await expect(resent).toBeVisible();
  await expect(resent).toBeFocused();

  // The new pending invite sits above the old row, which no longer offers
  // anything.
  const rows = admin.getByRole('row').filter({ hasText: inviteeEmail });
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toContainText('Pending');
  await expect(rows.nth(1)).toContainText('Expired');
  await expect(rows.nth(1)).not.toContainText('Renewal requested');
  // Scoped to this invitee: the e2e company is shared with other runs.
  await expect(rows.getByRole('button')).toHaveCount(0);

  // The old link now points the invitee at the newer email.
  await invitee.reload();
  await expect(
    invitee.getByText('A newer invitation has already been sent to you.', {
      exact: false,
    }),
  ).toBeVisible();
});

test('a used activation link says the account is already active', async ({
  browser,
}) => {
  const email = uniqueEmail('renewal-used');
  const token = createActivationLink(email);
  await activate(browser, email, token);

  const page = await (await browser.newContext()).newPage();
  await page.goto(`/activate/${token}`);

  await expect(
    page.getByText('Your account is already active.', { exact: false }),
  ).toBeVisible();
  await page.getByRole('link', { name: 'Log in' }).click();
  await expect(page.locator('#login-email')).toBeVisible();
});

test('an unknown activation link says so', async ({ browser }) => {
  const page = await (await browser.newContext()).newPage();
  await page.goto(`/activate/${'0'.repeat(64)}`);

  await expect(
    page.getByText("This link isn't valid.", { exact: false }),
  ).toBeVisible();
});
