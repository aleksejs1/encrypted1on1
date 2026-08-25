import { test, expect, type Browser, type Page } from '@playwright/test';
import {
  createActivationLink,
  createPasswordResetLink,
  uniqueEmail,
} from './helpers/provision.js';

const OLD_PASSWORD = 'correct horse battery staple 123';
const NEW_PASSWORD = 'orange giraffe umbrella cactus 789';

async function activate(
  browser: Browser,
  token: string,
  password: string,
): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`/activate/${token}`);
  await page.locator('#act-password').fill(password);
  await page.locator('#act-confirm').fill(password);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/**
 * The other half of the crypto model dual-actor-anketa.spec.ts exercises:
 * what happens when a user genuinely forgets their password. Password reset
 * doesn't recover the old private key (it's wrapped under a master key
 * derived from a password nobody remembers any more) — it issues a brand
 * new keypair, which means every anketa sealed under the old public key
 * goes unreadable until the counterpart re-seals it (AnketaController::
 * reshareKey()). This spec drives that whole real round trip through the
 * actual UI, with real WebAssembly libsodium on both sides: reset, old
 * password rejected, new password accepted from a genuinely fresh session,
 * counterpart sees the "re-share" banner and fixes it, and the reset user
 * can decrypt the pre-existing anketa content again afterwards.
 */
test('password reset issues a new keypair; counterpart re-share restores anketa access', async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('reset-employee');
  const managerEmail = uniqueEmail('reset-manager');
  const employeeToken = createActivationLink(employeeEmail);
  const managerToken = createActivationLink(managerEmail);

  const employee = await activate(browser, employeeToken, OLD_PASSWORD);
  const manager = await activate(browser, managerToken, OLD_PASSWORD);

  // Employee creates an anketa with the manager and publishes a marker —
  // real ciphertext, sealed with a real anketaKey, that needs to survive
  // the employee's keypair changing underneath it.
  await employee.goto('/anketas/new');
  const counterpartInput = employee.getByPlaceholder(
    'Type a name or email to search…',
  );
  await counterpartInput.fill(managerEmail);
  await employee.getByRole('button', { name: managerEmail }).click();

  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  // DateInput (frontend/src/design/DateInput.svelte) is a text field
  // parsed per the user's date-format preference, not a native
  // `<input type="date">` — DEFAULT_DATE_FORMAT is 'dmy_dot' (DD.MM.YYYY).
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const meetingDateInput = employee.locator('#meeting-date');
  await meetingDateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  // DateInput only parses on blur (commitText()) — the "Create anketa"
  // button starts out disabled, and a disabled button can't take focus to
  // blur this field for us, so it must be done explicitly first.
  await meetingDateInput.blur();
  await employee.getByRole('button', { name: 'Create anketa' }).click();
  await employee.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const anketaUrl = employee.url();

  const marker = `E2E-RESET-MARKER-${Date.now()}`;
  const employeeMySide = employee.locator('.side-card').first();
  await employeeMySide.locator('textarea').first().fill(marker);
  await employeeMySide.getByRole('button', { name: 'Publish' }).click();
  await expect(employeeMySide.getByText('Published')).toBeVisible();

  // Manager confirms pre-reset access — proves the manager holds a genuinely
  // valid sealedKey (needed later to re-seal for the employee's new key).
  await manager.goto(anketaUrl);
  const managerCounterpartSide = manager.locator('.side-card').nth(1);
  await expect(managerCounterpartSide.locator('textarea').first()).toHaveValue(
    marker,
  );

  // AnketaController::isKeyOutdated() compares publicKeyUpdatedAt against
  // the anketa's sealedKeyUpdatedAt with a strict `>`, and both are stored
  // with only second-level precision — a real password reset happens days
  // or weeks after anketa creation, but this automated flow runs fast
  // enough to land the reset in the very same second without this pause,
  // which would make the "outdated key" check below a false negative.
  await employee.waitForTimeout(1100);

  // Employee requests a reset — reachable while still logged in, exactly
  // like a real forgetful user would hit it from the login page.
  await employee.goto('/forgot-password');
  await employee.locator('#forgot-email').fill(employeeEmail);
  await employee.getByRole('button', { name: 'Send reset link' }).click();
  await expect(
    employee.getByText(
      "If that email has an account, we've sent a link to reset the password.",
    ),
  ).toBeVisible();

  // No Mailpit in the e2e stack (docker-compose.e2e.yml) — same CLI-bypass
  // pattern createActivationLink() already uses for account creation.
  const resetToken = createPasswordResetLink(employeeEmail);

  await employee.goto(`/reset-password/${resetToken}`);
  await expect(employee.getByText(employeeEmail)).toBeVisible();
  await employee.locator('#reset-password').fill(NEW_PASSWORD);
  await employee.locator('#reset-confirm').fill(NEW_PASSWORD);
  await employee
    .getByText(
      'I understand my existing anketas will be unreadable until access is restored.',
    )
    .click();
  await employee.getByRole('button', { name: 'Reset password' }).click();
  await employee.waitForURL('/');

  // The old password must now be genuinely rejected — a fresh session, not
  // the reset tab's already-authenticated state, actually exercising the
  // new authHash server-side.
  const oldLoginCtx = await browser.newContext();
  const oldLoginPage = await oldLoginCtx.newPage();
  await oldLoginPage.goto('/');
  await oldLoginPage.locator('#login-email').fill(employeeEmail);
  await oldLoginPage.locator('#login-password').fill(OLD_PASSWORD);
  await oldLoginPage.getByRole('button', { name: 'Log in' }).click();
  await expect(oldLoginPage.getByRole('alert')).toBeVisible();
  await expect(oldLoginPage.locator('#login-email')).toBeVisible();

  // The new password works from a genuinely fresh session too — a real
  // logout/login round trip through the new authHash and a real unwrap of
  // encryptedPrivateKey, not just the reset page's in-memory carry-over.
  const newLoginCtx = await browser.newContext();
  const newLoginPage = await newLoginCtx.newPage();
  await newLoginPage.goto('/');
  await newLoginPage.locator('#login-email').fill(employeeEmail);
  await newLoginPage.locator('#login-password').fill(NEW_PASSWORD);
  await newLoginPage.getByRole('button', { name: 'Log in' }).click();
  await newLoginPage.waitForURL('/');
  await expect(newLoginPage.locator('#login-email')).toHaveCount(0);

  // Manager sees the outdated-key banner and re-shares. Asserting on the
  // "Re-shared successfully." confirmation text itself is unreliable here:
  // reshareAll() (AnketaList.svelte) sets reshareResult and immediately
  // reassigns the `anketas` promise in the same synchronous update, which
  // Svelte batches into one re-render — the banner (gated on
  // `outdated.length > 0`) unmounts as soon as the refetched list no longer
  // contains an outdated entry, before the success text is ever painted.
  // The durable, real signal is the banner disappearing once the list
  // reflects the re-share.
  await manager.goto('/');
  await expect(
    manager.getByRole('button', { name: 'Re-share now' }),
  ).toBeVisible();
  await manager.getByRole('button', { name: 'Re-share now' }).click();
  await expect(
    manager.getByRole('button', { name: 'Re-share now' }),
  ).toHaveCount(0);

  // Employee, now on their new keypair via a genuinely fresh session, can
  // decrypt the pre-existing anketa content again.
  await newLoginPage.goto(anketaUrl);
  const employeeMySideAfterReset = newLoginPage.locator('.side-card').first();
  await expect(
    employeeMySideAfterReset.locator('textarea').first(),
  ).toHaveValue(marker);
});
