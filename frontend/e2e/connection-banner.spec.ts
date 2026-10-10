import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

/**
 * The connection banner (GitHub issue #242), against the real stack: what the
 * app shows while its server can't be reached, and what it sends by itself
 * once it can again.
 */

const PASSWORD = 'correct horse battery staple 123';

const LOST_ON_MEETING =
  'Connection lost. Unpublished answers and private notes in this tab are kept locally; other actions will work once back online.';
const LOST_ELSEWHERE =
  'Connection lost. Actions will work again once back online.';
const BACK = 'Back online.';

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

/** Creates a meeting from `creator` (the employee) with `counterpartEmail`, returns its URL. */
async function createAnketa(
  creator: Page,
  counterpartEmail: string,
): Promise<string> {
  await creator.goto('/anketas/new');
  await creator
    .getByPlaceholder('Type a name or email to search…')
    .fill(counterpartEmail);
  await creator.getByRole('button', { name: counterpartEmail }).click();
  await creator
    .locator('label.radio', { hasText: "No, I'm the employee" })
    .click();
  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const input = creator.locator('#meeting-date');
  await input.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await input.blur();
  await creator.getByRole('button', { name: 'Create 1:1' }).click();
  await creator.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  return creator.url();
}

function banner(page: Page): Locator {
  return page.locator('.connection-banner');
}

function notesPanel(page: Page): Locator {
  return page.getByRole('complementary', { name: 'My private notes' });
}

function topicsCard(page: Page): Locator {
  return page.locator('section.card', {
    has: page.getByRole('heading', { name: 'Topics to discuss' }),
  });
}

/**
 * The server stops answering this page while its network interface stays up
 * (a dead VPN, a server that is down): the case the browser's own offline
 * event never reports.
 */
async function cutServer(page: Page): Promise<void> {
  await page.route(/\/(api|health)(\/|$)/, (route) => route.abort());
}

async function restoreServer(page: Page): Promise<void> {
  await page.unroute(/\/(api|health)(\/|$)/);
}

test('a meeting page says the connection is lost, and on reconnect saves the draft and the notes and catches up', async ({
  browser,
}) => {
  // The notes' own retries have to run out first (2s + 5s + 15s).
  test.setTimeout(120_000);
  const employeeEmail = uniqueEmail('conn-emp');
  const managerEmail = uniqueEmail('conn-mgr');
  const employee = await activate(browser, employeeEmail);
  const manager = await activate(browser, managerEmail);
  const anketaUrl = await createAnketa(employee, managerEmail);
  await manager.goto(anketaUrl);
  await expect(topicsCard(manager)).toBeVisible();
  await expect(notesPanel(employee)).toContainText('Only you');
  await expect(banner(employee)).toBeEmpty();

  await cutServer(employee);
  // The live-update poll is the first request to fail.
  await expect(banner(employee)).toHaveText(LOST_ON_MEETING);

  // The banner is fixed to the top of the screen, and stays there however
  // far down the page the user is typing.
  await employee.locator('section.card').last().scrollIntoViewIfNeeded();
  await expect(banner(employee)).toBeInViewport();

  // Typed while offline: neither can be saved.
  const draftText = `E2E-OFFLINE-DRAFT-${Date.now()}`;
  const mySide = employee.locator('.side-card').first();
  await mySide.locator('textarea').first().fill(draftText);
  await expect(mySide.getByText('Could not save draft.')).toBeVisible();
  const noteText = `E2E-OFFLINE-NOTE-${Date.now()}`;
  await notesPanel(employee).getByRole('textbox').fill(noteText);
  // The automatic retries are used up: without the reconnect, this waits for
  // a click.
  await expect(
    notesPanel(employee).getByRole('button', { name: 'Retry' }),
  ).toBeVisible({ timeout: 40_000 });
  await expect(banner(employee)).toHaveText(LOST_ON_MEETING);

  // Meanwhile the manager adds a topic.
  const topic = `E2E-OFFLINE-TOPIC-${Date.now()}`;
  await topicsCard(manager).getByPlaceholder('Add a topic…').fill(topic);
  await topicsCard(manager).getByRole('button', { name: 'Add' }).click();
  await expect(topicsCard(manager).getByText(topic)).toBeVisible();
  await expect(topicsCard(employee).getByText(topic)).toHaveCount(0);

  const draftSaved = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/draft') &&
      response.ok(),
  );
  const noteSaved = employee.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.ok(),
  );
  await restoreServer(employee);

  // Nothing is typed or clicked from here on.
  await expect(banner(employee)).toHaveText(BACK, { timeout: 20_000 });
  await draftSaved;
  await noteSaved;
  await expect(
    notesPanel(employee).getByText('Saved', { exact: true }),
  ).toBeVisible();
  // The live-update poll runs again.
  await expect(topicsCard(employee).getByText(topic)).toBeVisible();
  await expect(banner(employee)).toBeEmpty();

  // Both really reached the server: a fresh login in another browser has no
  // local backup to show them from.
  const context = await browser.newContext();
  const fresh = await context.newPage();
  await fresh.goto(anketaUrl);
  await fresh.locator('#login-email').fill(employeeEmail);
  await fresh.locator('#login-password').fill(PASSWORD);
  await fresh.getByRole('button', { name: 'Log in' }).click();
  await expect(
    fresh.locator('.side-card').first().locator('textarea').first(),
  ).toHaveValue(draftText);
  await expect(notesPanel(fresh).getByRole('textbox')).toHaveValue(noteText);
});

test("a reverse proxy's 502 counts as a lost connection, and its error page is not read as being back", async ({
  browser,
}) => {
  const employeeEmail = uniqueEmail('conn-502-emp');
  const managerEmail = uniqueEmail('conn-502-mgr');
  const employee = await activate(browser, employeeEmail);
  await activate(browser, managerEmail);
  await createAnketa(employee, managerEmail);
  await expect(topicsCard(employee)).toBeVisible();

  let probes = 0;
  await employee.route(/\/(api|health)(\/|$)/, (route) => {
    if (route.request().url().endsWith('/health')) probes += 1;
    return route.fulfill({
      status: 502,
      contentType: 'text/html',
      body: '<html><body>Bad Gateway</body></html>',
    });
  });
  await expect(banner(employee)).toHaveText(LOST_ON_MEETING);
  // The probe got an answer, just not the app's.
  await expect.poll(() => probes, { timeout: 15_000 }).toBeGreaterThan(0);
  await expect(banner(employee)).toHaveText(LOST_ON_MEETING);

  await employee.unroute(/\/(api|health)(\/|$)/);
  await expect(banner(employee)).toHaveText(BACK, { timeout: 20_000 });
  await expect(banner(employee)).toBeEmpty();
});

test('other pages promise nothing about what was typed, and the browser going offline is enough there', async ({
  browser,
}) => {
  const page = await activate(browser, uniqueEmail('conn-list'));
  await expect(banner(page)).toBeEmpty();

  await page.context().setOffline(true);
  await expect(banner(page)).toHaveText(LOST_ELSEWHERE);
  await page.context().setOffline(false);
  await expect(banner(page)).toHaveText(BACK, { timeout: 20_000 });
  await expect(banner(page)).toBeEmpty();

  // The create form is under /anketas/ too, and keeps nothing locally either.
  await page.goto('/anketas/new');
  await expect(page.locator('#meeting-date')).toBeVisible();
  await page.context().setOffline(true);
  await expect(banner(page)).toHaveText(LOST_ELSEWHERE);
  await page.context().setOffline(false);
  await expect(banner(page)).toHaveText(BACK, { timeout: 20_000 });
});
