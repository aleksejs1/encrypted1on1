import {
  chromium,
  expect,
  test,
  type BrowserContext,
  type Page,
  type TestInfo,
} from '@playwright/test';
import {
  createActivationLink,
  createPasswordResetLink,
  uniqueEmail,
} from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';
const NEW_PASSWORD = 'orange giraffe umbrella cactus 789';

/**
 * GitHub issue #205, "Remember this browser": a login with the checkbox keeps
 * the master key in IndexedDB and gets a 30-day session cookie, so a new tab
 * or a restarted browser opens a meeting without the password. Real crypto,
 * real backend; a browser restart is a Chromium profile directory closed and
 * launched again, which is also what drops an ordinary (until-the-browser-
 * closes) session cookie.
 *
 * One test per row of the invalidation table in
 * docs/decisions/2026-10-04-remember-this-browser.md that needs a browser.
 */

// Every context a test opens, closed after it — otherwise their open meeting
// pages keep polling the e2e backend for the rest of the suite.
const contexts: BrowserContext[] = [];
test.afterEach(async () => {
  await Promise.all(contexts.splice(0).map((context) => context.close()));
});

/** Opens (or reopens) the test's own browser profile. */
async function launchProfile(
  testInfo: TestInfo,
  name = 'profile',
): Promise<BrowserContext> {
  const context = await chromium.launchPersistentContext(
    testInfo.outputPath(name),
    { baseURL: testInfo.project.use.baseURL },
  );
  contexts.push(context);
  return context;
}

/** Closes the profile's browser and starts it again. */
async function restart(
  context: BrowserContext,
  testInfo: TestInfo,
  name = 'profile',
): Promise<BrowserContext> {
  contexts.splice(contexts.indexOf(context), 1);
  await context.close();
  return launchProfile(testInfo, name);
}

async function activate(context: BrowserContext, email: string): Promise<Page> {
  const page = await context.newPage();
  await page.goto(`/activate/${createActivationLink(email)}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

async function logOut(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Log out' }).click();
  await expect(page.locator('#login-email')).toBeVisible();
}

async function logIn(
  page: Page,
  email: string,
  password: string,
  remember: boolean,
): Promise<void> {
  await page.goto('/');
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(password);
  if (remember) {
    await page.getByLabel('Remember this browser for 30 days').check();
  }
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.locator('.user-email')).toHaveText(email);
}

/** An account that exists and is logged out of the profile, ready to log in. */
async function accountIn(
  context: BrowserContext,
  label: string,
): Promise<{ email: string; page: Page }> {
  const email = uniqueEmail(label);
  const page = await activate(context, email);
  await logOut(page);
  return { email, page };
}

async function changePassword(page: Page): Promise<void> {
  await page.goto('/account');
  await page.locator('#current-password').fill(PASSWORD);
  await page.locator('#new-password').fill(NEW_PASSWORD);
  await page.locator('#confirm-password').fill(NEW_PASSWORD);
  await page.getByRole('button', { name: 'Change password' }).click();
  await expect(page.getByText('Password changed.')).toBeVisible();
}

/**
 * Whether the profile holds a remembered key, asked through the app's own
 * module (the same Vite-served instance the page uses).
 */
function hasRememberedKey(page: Page): Promise<boolean> {
  return page.evaluate(async () => {
    const load = (path: string) => import(/* @vite-ignore */ path);
    const { loadRememberedMasterKey } = (await load(
      '/src/crypto/rememberedKey.ts',
    )) as { loadRememberedMasterKey: () => Promise<Uint8Array | null> };
    return (await loadRememberedMasterKey()) !== null;
  });
}

async function expectUnlocked(page: Page, email: string): Promise<void> {
  await expect(page.locator('.user-email')).toHaveText(email);
  await expect(page.locator('#unlock-password')).toHaveCount(0);
  await expect(page.locator('#login-email')).toHaveCount(0);
}

test('a remembered browser opens a meeting after a restart without the password', async ({
  browser,
}, testInfo) => {
  let profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-employee');

  // The counterpart, in an ordinary separate browser.
  const managerContext = await browser.newContext();
  contexts.push(managerContext);
  const managerEmail = uniqueEmail('remember-manager');
  await activate(managerContext, managerEmail);

  await logIn(page, email, PASSWORD, true);
  expect(await hasRememberedKey(page)).toBe(true);

  // A meeting with a draft only this user's keys can decrypt.
  await page.goto('/anketas/new');
  await page
    .getByPlaceholder('Type a name or email to search…')
    .fill(managerEmail);
  await page.getByRole('button', { name: managerEmail }).click();
  await page.locator('label.radio', { hasText: 'leads this 1:1' }).click();
  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  // DateInput is a DD.MM.YYYY text field that only parses on blur — see
  // password-reset.spec.ts for the full explanation.
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const dateInput = page.locator('#meeting-date');
  await dateInput.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await dateInput.blur();
  await page.getByRole('button', { name: 'Create 1:1' }).click();
  await page.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const meetingUrl = page.url();
  const marker = `E2E-REMEMBER-MARKER-${Date.now()}`;
  const mySide = page.locator('.side-card').first();
  await mySide.locator('textarea').first().fill(marker);
  await expect(mySide.getByText('Saved.')).toBeVisible();

  // A second tab of the same browser: no "Unlock this tab".
  const secondTab = await profile.newPage();
  await secondTab.goto(meetingUrl);
  await expectUnlocked(secondTab, email);

  profile = await restart(profile, testInfo);
  const afterRestart = await profile.newPage();
  await afterRestart.goto(meetingUrl);

  await expectUnlocked(afterRestart, email);
  await expect(
    afterRestart.locator('.side-card').first().locator('textarea').first(),
  ).toHaveValue(marker);
});

test('without the checkbox nothing is remembered: a new tab asks for the password, a restart for a login', async ({}, testInfo) => {
  let profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'not-remembered');

  await logIn(page, email, PASSWORD, false);
  expect(await hasRememberedKey(page)).toBe(false);

  const secondTab = await profile.newPage();
  await secondTab.goto('/');
  await expect(secondTab.locator('#unlock-password')).toBeVisible();

  profile = await restart(profile, testInfo);
  const afterRestart = await profile.newPage();
  await afterRestart.goto('/');
  await expect(afterRestart.locator('#login-email')).toBeVisible();
});

test('logging in without the checkbox removes the key an earlier login left', async ({}, testInfo) => {
  const profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-then-not');
  await logIn(page, email, PASSWORD, true);
  // The session goes without a logout in this browser (cookies cleared), so
  // the key is still there when the next login happens.
  await profile.clearCookies();
  await page.goto('/');
  await expect(page.locator('#login-email')).toBeVisible();
  expect(await hasRememberedKey(page)).toBe(true);

  await logIn(page, email, PASSWORD, false);

  expect(await hasRememberedKey(page)).toBe(false);
});

test('logging out forgets the key, and a restart shows the login', async ({}, testInfo) => {
  let profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-logout');
  await logIn(page, email, PASSWORD, true);
  expect(await hasRememberedKey(page)).toBe(true);

  await logOut(page);
  expect(await hasRememberedKey(page)).toBe(false);

  profile = await restart(profile, testInfo);
  const afterRestart = await profile.newPage();
  await afterRestart.goto('/');
  await expect(afterRestart.locator('#login-email')).toBeVisible();
});

test('deleting the account forgets the key', async ({}, testInfo) => {
  const profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-delete');
  await logIn(page, email, PASSWORD, true);

  await page.goto('/account');
  await page.locator('#delete-current-password').fill(PASSWORD);
  await page.locator('label.ack-checkbox').click();
  await page.getByRole('button', { name: 'Delete my account' }).click();

  await expect(page.locator('#login-email')).toBeVisible();
  expect(await hasRememberedKey(page)).toBe(false);
});

test('changing the password in a remembered browser keeps it remembered, under the new key', async ({}, testInfo) => {
  let profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-pwchange-here');
  await logIn(page, email, PASSWORD, true);
  // A second tab unlocked before the change: it holds the old key.
  const oldKeyTab = await profile.newPage();
  await oldKeyTab.goto('/');
  await expectUnlocked(oldKeyTab, email);

  await changePassword(page);

  // That tab reloads: its own key no longer unwraps anything, the remembered
  // one does, and the remembered one is still there afterwards.
  await oldKeyTab.reload();
  await expectUnlocked(oldKeyTab, email);
  expect(await hasRememberedKey(oldKeyTab)).toBe(true);

  profile = await restart(profile, testInfo);
  const afterRestart = await profile.newPage();
  await afterRestart.goto('/');
  await expectUnlocked(afterRestart, email);
});

test('a password change elsewhere ends the remembered session; logging in with the new password remembers again', async ({
  browser,
}, testInfo) => {
  let profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-pwchange-away');
  await logIn(page, email, PASSWORD, true);

  // "Lost this device? Change your password", from another browser.
  const otherContext = await browser.newContext();
  contexts.push(otherContext);
  const otherPage = await otherContext.newPage();
  await logIn(otherPage, email, PASSWORD, false);
  await changePassword(otherPage);

  // The open tab of the remembered browser is out on its next request.
  await page.goto('/');
  await expect(page.locator('#login-email')).toBeVisible();

  // So is the browser after a restart, although its cookie and key are
  // both still on disk.
  profile = await restart(profile, testInfo);
  const afterRestart = await profile.newPage();
  await afterRestart.goto('/');
  await expect(afterRestart.locator('#login-email')).toBeVisible();

  await logIn(afterRestart, email, NEW_PASSWORD, true);
  const newTab = await profile.newPage();
  await newTab.goto('/');
  await expectUnlocked(newTab, email);
});

test('a password reset elsewhere ends the remembered session', async ({
  browser,
}, testInfo) => {
  const profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-reset-away');
  await logIn(page, email, PASSWORD, true);

  const otherContext = await browser.newContext();
  contexts.push(otherContext);
  const otherPage = await otherContext.newPage();
  await otherPage.goto(`/reset-password/${createPasswordResetLink(email)}`);
  await otherPage.locator('#reset-password').fill(NEW_PASSWORD);
  await otherPage.locator('#reset-confirm').fill(NEW_PASSWORD);
  await otherPage.locator('label.ack-checkbox').click();
  await otherPage.getByRole('button', { name: 'Reset password' }).click();
  await otherPage.waitForURL('/');

  const newTab = await profile.newPage();
  await newTab.goto('/');
  await expect(newTab.locator('#login-email')).toBeVisible();
});

test('a password reset in a remembered browser forgets the old key', async ({}, testInfo) => {
  const profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-reset-here');
  await logIn(page, email, PASSWORD, true);

  await page.goto(`/reset-password/${createPasswordResetLink(email)}`);
  await page.locator('#reset-password').fill(NEW_PASSWORD);
  await page.locator('#reset-confirm').fill(NEW_PASSWORD);
  await page.locator('label.ack-checkbox').click();
  await page.getByRole('button', { name: 'Reset password' }).click();
  await page.waitForURL('/');

  expect(await hasRememberedKey(page)).toBe(false);
  // The session the reset opens is an ordinary one: a new tab asks for the
  // password.
  const newTab = await profile.newPage();
  await newTab.goto('/');
  await expect(newTab.locator('#unlock-password')).toBeVisible();
});

test('a remembered key that no longer unwraps asks for the password once, which puts the right key in its place', async ({}, testInfo) => {
  const profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-stale-key');
  await logIn(page, email, PASSWORD, true);
  // The remembered key is replaced by one that opens nothing, through the
  // app's own module (the same Vite-served instance the page uses).
  await page.evaluate(async () => {
    const load = (path: string) => import(/* @vite-ignore */ path);
    const { replaceRememberedMasterKey } = (await load(
      '/src/crypto/rememberedKey.ts',
    )) as {
      replaceRememberedMasterKey: (
        key: Uint8Array,
        owner: string,
      ) => Promise<void>;
    };
    const me = (await (await fetch('/api/me')).json()) as { publicKey: string };
    await replaceRememberedMasterKey(new Uint8Array(32).fill(1), me.publicKey);
  });

  const newTab = await profile.newPage();
  await newTab.goto('/');
  await expect(newTab.locator('#unlock-password')).toBeVisible();

  await newTab.locator('#unlock-password').fill(PASSWORD);
  await newTab.getByRole('button', { name: 'Unlock' }).click();
  await expectUnlocked(newTab, email);

  const thirdTab = await profile.newPage();
  await thirdTab.goto('/');
  await expectUnlocked(thirdTab, email);
});

test('unlocking a tab in an ordinary session leaves nothing on disk', async ({}, testInfo) => {
  const profile = await launchProfile(testInfo);
  const { email, page } = await accountIn(profile, 'remember-unlock-race');
  await logIn(page, email, PASSWORD, false);
  // An ordinary session: a second tab asks for the password, and unlocking
  // it must leave nothing on disk.
  const secondTab = await profile.newPage();
  await secondTab.goto('/');
  await secondTab.locator('#unlock-password').fill(PASSWORD);
  await secondTab.getByRole('button', { name: 'Unlock' }).click();
  await expectUnlocked(secondTab, email);

  expect(await hasRememberedKey(secondTab)).toBe(false);
});
