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
 * The "My private notes" list in Reports (GitHub issue #243), against the
 * real stack with real crypto: the notes are written on two meetings' pages,
 * then listed, filtered and searched on /report/notes.
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

/** Creates a meeting from `creator` with `counterpartEmail`, saves `notes` on it, returns its URL. */
async function meetingWithNotes(
  creator: Page,
  counterpartEmail: string,
  daysAhead: number,
  notes: string,
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
  meetingDate.setDate(meetingDate.getDate() + daysAhead);
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const input = creator.locator('#meeting-date');
  await input.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await input.blur();
  await creator.getByRole('button', { name: 'Create 1:1' }).click();
  await creator.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  const url = creator.url();

  const panel = creator.getByRole('complementary', {
    name: 'My private notes',
  });
  const saved = creator.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.ok(),
  );
  await panel.getByRole('textbox').fill(notes);
  await saved;
  await expect(panel.getByText('Saved', { exact: true })).toBeVisible();
  return url;
}

function noteCard(page: Page, colleagueEmail: string): Locator {
  return page.locator('article', { hasText: colleagueEmail });
}

test('lists my notes from every meeting, hidden until shown, with a colleague filter and search', async ({
  browser,
}) => {
  const aliceEmail = uniqueEmail('report-notes-alice');
  const bobEmail = uniqueEmail('report-notes-bob');
  const me = await activate(browser, uniqueEmail('report-notes-me'));
  await activate(browser, aliceEmail);
  await activate(browser, bobEmail);

  const aliceNote = 'Wants to lead the billing migration';
  const bobNote = 'Asked about a conference budget';
  const aliceUrl = await meetingWithNotes(me, aliceEmail, 3, aliceNote);
  await meetingWithNotes(me, bobEmail, 5, bobNote);

  await me.goto('/report');
  await me.getByRole('link', { name: 'My private notes' }).click();
  await me.waitForURL('/report/notes');

  // Both meetings are listed, the later one first, and no note's text is in
  // the page.
  const cards = me.locator('article');
  await expect(cards).toHaveCount(2);
  await expect(cards.nth(0)).toContainText(bobEmail);
  await expect(cards.nth(1)).toContainText(aliceEmail);
  await expect(me.getByText(aliceNote)).toHaveCount(0);
  await expect(me.getByText(bobNote)).toHaveCount(0);

  // Showing one note leaves the other out of the page.
  await noteCard(me, aliceEmail)
    .getByRole('button', { name: 'Show note' })
    .click();
  await expect(noteCard(me, aliceEmail).getByText(aliceNote)).toBeVisible();
  await expect(me.getByText(bobNote)).toHaveCount(0);
  await noteCard(me, aliceEmail)
    .getByRole('button', { name: 'Hide note' })
    .click();
  await expect(me.getByText(aliceNote)).toHaveCount(0);

  // The colleague filter.
  await me.getByLabel('Counterpart').selectOption({ label: aliceEmail });
  await expect(cards).toHaveCount(1);
  await expect(cards).toContainText(aliceEmail);
  await me.getByLabel('Counterpart').selectOption({ label: 'Everyone' });
  await expect(cards).toHaveCount(2);

  // Search finds a note by its text and never shows the text by itself.
  await me.getByLabel('Search').fill('CONFERENCE');
  await expect(cards).toHaveCount(1);
  await expect(cards).toContainText(bobEmail);
  await expect(me.getByText(bobNote)).toHaveCount(0);
  await me.getByLabel('Search').fill('no such words anywhere');
  await expect(cards).toHaveCount(0);
  await expect(me.getByText('No notes match.')).toBeVisible();
  await me.getByLabel('Search').fill('');

  // A shown note that a filter takes out of the list is hidden again: it
  // doesn't come back by itself with the filter.
  await noteCard(me, bobEmail)
    .getByRole('button', { name: 'Show note' })
    .click();
  await expect(me.getByText(bobNote)).toBeVisible();
  await me.getByLabel('Counterpart').selectOption({ label: aliceEmail });
  await expect(cards).toHaveCount(1);

  // "Show all notes" shows the listed notes: only Alice's.
  await me.getByRole('button', { name: 'Show all notes' }).click();
  await expect(me.getByText(aliceNote)).toBeVisible();
  await me.getByLabel('Counterpart').selectOption({ label: 'Everyone' });
  await expect(cards).toHaveCount(2);
  await expect(me.getByText(aliceNote)).toBeVisible();
  await expect(me.getByText(bobNote)).toHaveCount(0);

  // With one of the two shown the button still offers the rest, then hides
  // them all.
  await me.getByRole('button', { name: 'Show all notes' }).click();
  await expect(me.getByText(bobNote)).toBeVisible();
  await me.getByRole('button', { name: 'Hide all notes' }).click();
  await expect(me.getByText(aliceNote)).toHaveCount(0);
  await expect(me.getByText(bobNote)).toHaveCount(0);

  // A reload opens the page with everything hidden again.
  await me.getByRole('button', { name: 'Show all notes' }).click();
  await expect(me.getByText(bobNote)).toBeVisible();
  await me.reload();
  await expect(cards).toHaveCount(2);
  await expect(me.getByText(aliceNote)).toHaveCount(0);
  await expect(me.getByText(bobNote)).toHaveCount(0);

  await noteCard(me, aliceEmail)
    .getByRole('link', { name: 'Open 1:1' })
    .click();
  await me.waitForURL(aliceUrl);
});

test('a note written before a password reset is listed as unreadable', async ({
  browser,
}) => {
  const myEmail = uniqueEmail('report-notes-reset-me');
  const aliceEmail = uniqueEmail('report-notes-reset-alice');
  const me = await activate(browser, myEmail);
  await activate(browser, aliceEmail);
  const note = 'written under the old keypair';
  await meetingWithNotes(me, aliceEmail, 3, note);

  // A note typed and then cleared stays on the server as an encrypted empty
  // text, which the reset makes unopenable too. It must not be listed.
  const bobEmail = uniqueEmail('report-notes-reset-bob');
  await activate(browser, bobEmail);
  await meetingWithNotes(me, bobEmail, 5, 'typed, then cleared');
  const cleared = me.waitForResponse(
    (response) =>
      response.request().method() === 'PUT' &&
      response.url().endsWith('/private-notes') &&
      response.ok(),
  );
  await me
    .getByRole('complementary', { name: 'My private notes' })
    .getByRole('textbox')
    .fill('');
  await cleared;

  const newPassword = 'a brand new passphrase 456';
  await me.goto(`/reset-password/${createPasswordResetLink(myEmail)}`);
  await me.locator('#reset-password').fill(newPassword);
  await me.locator('#reset-confirm').fill(newPassword);
  await me
    .getByText(
      'I understand my existing 1:1s will be unreadable until access is restored.',
    )
    .click();
  await me.getByRole('button', { name: 'Reset password' }).click();
  await me.waitForURL('/');

  // The meeting's answers wait for Alice to re-share its key; the note is
  // listed regardless, with its meeting, and with nothing to show.
  await me.goto('/report/notes');
  await expect(me.locator('article')).toHaveCount(1);
  const card = noteCard(me, aliceEmail);
  await expect(card).toContainText("These notes can't be opened");
  await expect(card.getByRole('link', { name: 'Open 1:1' })).toBeVisible();
  await expect(card.getByRole('button', { name: 'Show note' })).toHaveCount(0);
  await expect(me.getByRole('button', { name: 'Show all notes' })).toHaveCount(
    0,
  );
  await expect(me.getByText(note)).toHaveCount(0);
});
