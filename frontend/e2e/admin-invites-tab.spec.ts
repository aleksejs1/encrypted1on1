import { test, expect } from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

/**
 * GitHub issue #280: the invite form and the invite settings are on the admin
 * panel's Invites tab, above the list, and the Users tab links there. Every e2e
 * account shares one company, so the settings are saved as they are, not changed.
 */
test('an admin invites from the Invites tab, and the list shows it at once', async ({
  browser,
}) => {
  const admin = await (await browser.newContext()).newPage();
  await admin.goto(
    `/activate/${createActivationLink(uniqueEmail('inv-tab-admin'), true)}`,
  );
  await admin.locator('#act-password').fill(PASSWORD);
  await admin.locator('#act-confirm').fill(PASSWORD);
  await admin.getByRole('button', { name: 'Activate' }).click();
  await admin.waitForURL('/');

  // The Users tab has neither card any more, only a link.
  await admin.goto('/admin');
  await expect(admin.getByRole('table').first()).toBeVisible();
  await expect(admin.locator('#invite-email')).toHaveCount(0);
  await expect(admin.locator('#registration-mode')).toHaveCount(0);
  await admin.getByRole('link', { name: 'Invite a colleague' }).click();
  await admin.waitForURL('/admin/invites');

  // The two cards sit side by side above the list.
  const formBox = await admin.locator('form.card').boundingBox();
  const settingsBox = await admin
    .locator('.card', { has: admin.locator('#registration-mode') })
    .boundingBox();
  if (!formBox || !settingsBox) throw new Error('a card is not on the page');
  expect(Math.abs(formBox.y - settingsBox.y)).toBeLessThan(1);
  expect(settingsBox.x).toBeGreaterThan(formBox.x + formBox.width);

  // Sending an invite reloads the list under the form.
  const inviteeEmail = uniqueEmail('inv-tab-invitee');
  const row = admin.getByRole('row').filter({ hasText: inviteeEmail });
  await expect(row).toHaveCount(0);
  await admin.locator('#invite-email').fill(inviteeEmail);
  await admin.getByRole('button', { name: 'Send invite', exact: true }).click();
  await expect(admin.getByText('Invite sent.')).toBeVisible();
  await expect(row).toHaveCount(1);
  await expect(row).toContainText('Pending');

  // The settings card holds the company's values, and Save sends them. The PUT is
  // answered here, not by the server: every e2e account shares this company.
  const mode = admin.locator('#registration-mode');
  await expect(mode).toBeEnabled();
  const before = {
    registrationMode: await mode.inputValue(),
    allowedEmailDomain: await admin
      .locator('#allowed-email-domain')
      .inputValue(),
  };
  let sent: unknown = null;
  await admin.route('**/api/admin/company-settings', (route) => {
    sent = route.request().postDataJSON();
    return route.fulfill({ json: sent });
  });
  await admin.getByRole('button', { name: 'Save', exact: true }).click();
  await expect(admin.getByText('Saved.')).toBeVisible();
  expect(sent).toEqual(before);
});
