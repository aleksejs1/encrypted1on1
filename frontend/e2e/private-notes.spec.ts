import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test';
import {
  createActivationLink,
  createPasswordResetLink,
  uniqueEmail,
} from './helpers/provision.js';

/**
 * Private notes (GitHub issues #132, #137), against the real stack with real
 * crypto: the notes are encrypted in the browser, and the server only ever
 * stores ciphertext.
 */

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

async function activate(browser: Browser, email: string): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto(`/activate/${createActivationLink(email)}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

async function logIn(page: Page, email: string, password = PASSWORD) {
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.locator('#login-email')).toHaveCount(0);
}

/** Creates an anketa from `creator` with `counterpartEmail`, returns its URL. */
async function createAnketa(
  creator: Page,
  counterpartEmail: string,
): Promise<string> {
  await creator.goto('/anketas/new');
  await creator
    .getByPlaceholder('Type a name or email to search…')
    .fill(counterpartEmail);
  await creator.getByRole('button', { name: counterpartEmail }).click();
  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const input = creator.locator('#meeting-date');
  await input.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await input.blur();
  await creator.getByRole('button', { name: 'Create anketa' }).click();
  await creator.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  return creator.url();
}

async function makePair(browser: Browser, label: string) {
  const employeeEmail = uniqueEmail(`${label}-emp`);
  const managerEmail = uniqueEmail(`${label}-mgr`);
  const employee = await activate(browser, employeeEmail);
  const manager = await activate(browser, managerEmail);
  const anketaUrl = await createAnketa(employee, managerEmail);
  return { employee, employeeEmail, manager, managerEmail, anketaUrl };
}

function notesPanel(page: Page): Locator {
  return page.getByRole('complementary', { name: 'My private notes' });
}

function notesText(page: Page): Locator {
  return notesPanel(page).getByRole('textbox');
}

/** Types into the panel and waits for the resulting save to be acknowledged. */
async function typeAndSave(page: Page, text: string): Promise<void> {
  const saved = page.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.ok(),
  );
  await notesText(page).fill(text);
  await saved;
  await expect(
    notesPanel(page).getByText('Saved', { exact: true }),
  ).toBeVisible();
}

test('notes round-trip, and the counterpart receives nothing of them', async ({
  browser,
}) => {
  const { employee, manager, anketaUrl } = await makePair(browser, 'notes-rt');
  const secret = `manager-only ${Date.now()}`;

  await manager.goto(anketaUrl);
  await expect(notesPanel(manager)).toContainText('Only you');
  await typeAndSave(manager, secret);
  await manager.reload();
  await expect(notesText(manager)).toHaveValue(secret);

  // The employee's own notes are empty, and nothing the employee's browser
  // receives mentions the manager's notes, not even the field names.
  const notesResponse = employee.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      response.url().endsWith('/private-notes'),
  );
  const detailResponse = employee.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      response.url().endsWith(anketaUrl.slice(anketaUrl.lastIndexOf('/'))),
  );
  await employee.goto(anketaUrl);
  expect(await (await notesResponse).text()).toBe('null');
  const detail = await (await detailResponse).text();
  expect(detail).not.toContain('notesBlob');
  expect(detail).not.toContain('encryptedNotesKey');
  await expect(notesText(employee)).toHaveValue('');
});

test('notes stay editable after the anketa is archived', async ({
  browser,
}) => {
  const { employee, anketaUrl } = await makePair(browser, 'notes-archived');
  await employee.goto(anketaUrl);
  // force: the checkbox's styled label intercepts the pointer, as in
  // dual-actor-anketa.spec.ts.
  await employee
    .getByRole('checkbox', { name: "Don't create the next meeting" })
    .check({ force: true });
  await employee.getByRole('button', { name: 'Archive' }).click();
  await expect(employee.getByRole('button', { name: 'Archive' })).toHaveCount(
    0,
  );

  await typeAndSave(employee, 'written up after the meeting');
  await employee.reload();

  await expect(notesText(employee)).toHaveValue('written up after the meeting');
});

test('hiding the panel takes the notes text out of the page', async ({
  browser,
}) => {
  const { employee, anketaUrl } = await makePair(browser, 'notes-hide');
  await employee.goto(anketaUrl);
  await typeAndSave(employee, 'not for screen sharing');

  await notesPanel(employee)
    .getByRole('button', { name: 'Hide notes' })
    .click();

  await expect(notesText(employee)).toHaveCount(0);
  expect(await employee.content()).not.toContain('not for screen sharing');
  // The privacy line stays, and the choice survives a reload.
  await expect(notesPanel(employee)).toContainText('Only you can see these');
  await employee.reload();
  await expect(
    notesPanel(employee).getByRole('button', { name: 'Show notes' }),
  ).toBeVisible();
  await expect(notesText(employee)).toHaveCount(0);

  await notesPanel(employee)
    .getByRole('button', { name: 'Show notes' })
    .click();
  await expect(notesText(employee)).toHaveValue('not for screen sharing');
});

test('two tabs editing the same notes: "Keep both" loses nothing', async ({
  browser,
}) => {
  const { employee, anketaUrl } = await makePair(browser, 'notes-two-tabs');
  await employee.goto(anketaUrl);
  await typeAndSave(employee, 'base');

  // A second tab of the same browser session has to unlock on its own.
  const tabB = await employee.context().newPage();
  await tabB.goto(anketaUrl);
  await tabB.locator('#unlock-password').fill(PASSWORD);
  await tabB.getByRole('button', { name: 'Unlock' }).click();
  await expect(notesText(tabB)).toHaveValue('base');

  await typeAndSave(employee, 'from A');
  const conflict = tabB.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.status() === 409,
  );
  await notesText(tabB).fill('from B');
  await conflict;

  const banner = notesPanel(tabB).getByRole('alert');
  await expect(banner).toContainText(
    'You edited these notes in another tab or device.',
  );
  await banner.getByRole('button', { name: 'Keep both' }).click();
  await expect(notesText(tabB)).toHaveValue('from A\n\n---\n\nfrom B');
  await expect(
    notesPanel(tabB).getByText('Saved', { exact: true }),
  ).toBeVisible();

  await employee.reload();
  await expect(notesText(employee)).toHaveValue('from A\n\n---\n\nfrom B');
});

test('text typed on first use survives a logout and is saved after logging back in', async ({
  browser,
}) => {
  const { employee, employeeEmail, anketaUrl } = await makePair(
    browser,
    'notes-first-use',
  );
  await employee.goto(anketaUrl);
  await expect(notesText(employee)).toBeEditable();

  // No row can be created: every save fails.
  const pattern = '**/api/anketas/*/private-notes';
  await employee.route(pattern, (route) =>
    route.request().method() === 'PUT' ? route.abort() : route.continue(),
  );
  await notesText(employee).fill('typed before logging out');
  await employee.getByRole('button', { name: 'Log out' }).click();
  await expect(employee.locator('#login-email')).toBeVisible();
  await employee.unroute(pattern);

  const saved = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.ok(),
  );
  await logIn(employee, employeeEmail);
  await employee.goto(anketaUrl);
  await expect(notesText(employee)).toHaveValue('typed before logging out');
  await saved;

  // Really on the server, not only in this tab's backup.
  const other = await (await browser.newContext()).newPage();
  await other.goto('/');
  await logIn(other, employeeEmail);
  await other.goto(anketaUrl);
  await expect(notesText(other)).toHaveValue('typed before logging out');
});

test('after a password reset the notes are unreadable, and new notes can be started', async ({
  browser,
}) => {
  const { employee, employeeEmail, manager, anketaUrl } = await makePair(
    browser,
    'notes-reset',
  );
  await employee.goto(anketaUrl);
  await typeAndSave(employee, 'written under the old keypair');

  const newPassword = 'a brand new passphrase 456';
  await employee.goto(
    `/reset-password/${createPasswordResetLink(employeeEmail)}`,
  );
  await expect(employee.getByText('private meeting notes')).toBeVisible();
  await employee.locator('#reset-password').fill(newPassword);
  await employee.locator('#reset-confirm').fill(newPassword);
  await employee
    .getByText(
      'I understand my existing anketas will be unreadable until access is restored.',
    )
    .click();
  await employee.getByRole('button', { name: 'Reset password' }).click();
  await employee.waitForURL('/');

  // The anketa itself only opens again once the counterpart re-shares its
  // key (the existing reset flow, password-reset.spec.ts). The notes are
  // under the employee's own old keypair, which nobody can re-share.
  await manager.goto('/');
  await manager.getByRole('button', { name: 'Re-share now' }).click();
  await expect(
    manager.getByRole('button', { name: 'Re-share now' }),
  ).toHaveCount(0);

  await employee.goto(anketaUrl);
  await expect(notesPanel(employee)).toContainText(
    "These notes can't be opened",
  );
  await expect(notesText(employee)).toHaveCount(0);

  const saved = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.ok(),
  );
  await notesPanel(employee)
    .getByRole('button', { name: 'Start new notes' })
    .click();
  await saved;
  await typeAndSave(employee, 'written under the new keypair');
  await employee.reload();
  await expect(notesText(employee)).toHaveValue(
    'written under the new keypair',
  );
});

test("navigating to another anketa saves this one's unsaved text into this one", async ({
  browser,
}) => {
  const {
    employee,
    managerEmail,
    anketaUrl: firstUrl,
  } = await makePair(browser, 'notes-navigate');
  const secondUrl = await createAnketa(employee, managerEmail);
  await employee.goto(secondUrl);
  await typeAndSave(employee, 'second anketa notes');

  await employee.goto(firstUrl);
  await expect(notesText(employee)).toHaveValue('');
  const savedFirst = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().includes(firstUrl.slice(firstUrl.lastIndexOf('/'))) &&
      response.ok(),
  );
  await notesText(employee).fill('first anketa, unsaved');

  // In-app navigation to the other anketa, before the debounce fires: the
  // same page component is reused, and the notes panel is replaced.
  await employee.evaluate(async (url) => {
    const load = (path: string) => import(/* @vite-ignore */ path);
    const router = (await load('/src/router.svelte.ts')) as {
      navigate: (to: string) => void;
    };
    router.navigate(url);
  }, new URL(secondUrl).pathname);
  await savedFirst;
  await expect(notesText(employee)).toHaveValue('second anketa notes');

  await employee.goto(firstUrl);
  await expect(notesText(employee)).toHaveValue('first anketa, unsaved');
});

test('closing the tab with unsaved notes warns before unloading', async ({
  browser,
}) => {
  const { employee, anketaUrl } = await makePair(browser, 'notes-unload');
  await employee.goto(anketaUrl);
  await expect(notesText(employee)).toBeEditable();
  // The save never completes, so the text stays unsaved.
  await employee.route('**/api/anketas/*/private-notes', (route) =>
    route.request().method() === 'PUT'
      ? new Promise(() => {})
      : route.continue(),
  );
  await notesText(employee).fill('still unsaved');

  const dialog = employee.waitForEvent('dialog');
  await employee.close({ runBeforeUnload: true });

  expect((await dialog).type()).toBe('beforeunload');
});

test('text left unsaved by in-app navigation still warns on closing after a refresh', async ({
  browser,
}) => {
  const { employee, anketaUrl } = await makePair(browser, 'notes-leftover');
  await employee.goto(anketaUrl);
  await expect(notesText(employee)).toBeEditable();
  await employee.route('**/api/anketas/*/private-notes', (route) =>
    route.request().method() === 'PUT' ? route.abort() : route.continue(),
  );
  await notesText(employee).fill('only in this tab');

  // In-app navigation away: the panel's last save fails, so the text stays
  // only in the tab's backup.
  const failedSave = employee.waitForEvent('requestfailed', (request) =>
    request.url().endsWith('/private-notes'),
  );
  await employee.evaluate(async () => {
    const load = (path: string) => import(/* @vite-ignore */ path);
    const router = (await load('/src/router.svelte.ts')) as {
      navigate: (to: string) => void;
    };
    router.navigate('/');
  });
  await failedSave;

  // A refresh (confirming the warning it raises) keeps the warning armed.
  employee.once('dialog', (dialog) => void dialog.accept());
  await employee.reload();
  await expect(employee.getByRole('button', { name: 'Log out' })).toBeVisible();

  const dialog = employee.waitForEvent('dialog');
  await employee.close({ runBeforeUnload: true });
  expect((await dialog).type()).toBe('beforeunload');
});
