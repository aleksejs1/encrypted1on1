import { readFile } from 'node:fs/promises';
import {
  test,
  expect,
  type Browser,
  type BrowserContext,
  type Page,
} from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const OLD_PASSWORD = 'correct horse battery staple 123';
const NEW_PASSWORD = 'orange giraffe umbrella cactus 789';

// Every context a test opens, closed after it — otherwise their open anketa
// pages keep polling the e2e backend for the rest of the suite.
const contexts: BrowserContext[] = [];
async function newPage(browser: Browser): Promise<Page> {
  const context = await browser.newContext();
  contexts.push(context);
  return context.newPage();
}
test.afterEach(async () => {
  await Promise.all(contexts.splice(0).map((context) => context.close()));
});

async function activate(browser: Browser, email: string): Promise<Page> {
  const page = await newPage(browser);
  await page.goto(`/activate/${createActivationLink(email)}`);
  await page.locator('#act-password').fill(OLD_PASSWORD);
  await page.locator('#act-confirm').fill(OLD_PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/** A fresh browser context (own cookies, own sessionStorage) logged in from scratch. */
async function logIn(
  browser: Browser,
  email: string,
  password: string,
): Promise<Page> {
  const page = await newPage(browser);
  await page.goto('/');
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(password);
  await page.getByRole('button', { name: 'Log in' }).click();
  await expect(page.locator('#login-email')).toHaveCount(0);
  return page;
}

async function createAnketaWith(page: Page, counterpartEmail: string) {
  await page.goto('/anketas/new');
  await page
    .getByPlaceholder('Type a name or email to search…')
    .fill(counterpartEmail);
  await page.getByRole('button', { name: counterpartEmail }).click();
  const meetingDate = new Date();
  meetingDate.setDate(meetingDate.getDate() + 3);
  // DateInput is a DD.MM.YYYY text field that only parses on blur — see
  // password-reset.spec.ts for the full explanation.
  const dd = String(meetingDate.getDate()).padStart(2, '0');
  const mm = String(meetingDate.getMonth() + 1).padStart(2, '0');
  const input = page.locator('#meeting-date');
  await input.fill(`${dd}.${mm}.${meetingDate.getFullYear()}`);
  await input.blur();
  await page.getByRole('button', { name: 'Create anketa' }).click();
  await page.waitForURL(/\/anketas\/[0-9a-f-]+$/);
  return page.url();
}

async function typeDraft(page: Page, text: string) {
  const mySide = page.locator('.side-card').first();
  await mySide.locator('textarea').first().fill(text);
  await expect(mySide.getByText('Saved.')).toBeVisible();
}

async function changePassword(page: Page) {
  await page.goto('/account');
  await page.locator('#current-password').fill(OLD_PASSWORD);
  await page.locator('#new-password').fill(NEW_PASSWORD);
  await page.locator('#confirm-password').fill(NEW_PASSWORD);
  await page.getByRole('button', { name: 'Change password' }).click();
  await expect(page.getByText('Password changed.')).toBeVisible();
}

/**
 * Replaces the stored draft from inside the page, through the app's own
 * modules (the same Vite-served instances the page uses, so the same cached
 * CSRF token and unlocked identity). `'legacy'` re-encrypts the current draft
 * the way drafts were stored before GitHub issue #129 — under the tab's
 * password-derived master key; a string stores that raw blob instead. The
 * tab's local draft backup is dropped too, so the next load reads only the
 * server-side draft.
 */
async function replaceStoredDraft(
  page: Page,
  anketaUrl: string,
  replacement: 'legacy' | { rawBlob: string },
) {
  await page.evaluate(
    async ({ anketaId, replacement }) => {
      const load = (path: string) => import(/* @vite-ignore */ path);
      const client = (await load('/src/api/client.ts')) as {
        apiGet: <T>(path: string) => Promise<T>;
        apiPut: (path: string, body: unknown) => Promise<unknown>;
      };
      let blob: string;
      if (replacement === 'legacy') {
        const { ensureUnlocked } = (await load(
          '/src/crypto/identity.svelte.ts',
        )) as { ensureUnlocked: () => Promise<{ privateKey: Uint8Array }> };
        const { deriveDraftKey } = (await load('/src/crypto/keypair.ts')) as {
          deriveDraftKey: (privateKey: Uint8Array) => Promise<Uint8Array>;
        };
        const { loadMasterKey } = (await load('/src/crypto/session.ts')) as {
          loadMasterKey: () => Promise<Uint8Array | null>;
        };
        const { decryptBlob, encryptBlob } = (await load(
          '/src/crypto/anketaKey.ts',
        )) as {
          decryptBlob: (b: string, k: Uint8Array) => Promise<{ data: unknown }>;
          encryptBlob: (d: unknown, k: Uint8Array) => Promise<string>;
        };
        const current = await client.apiGet<{ employeeBlob: string }>(
          `/api/anketas/${anketaId}`,
        );
        const draftKey = await deriveDraftKey(
          (await ensureUnlocked()).privateKey,
        );
        const answers = (await decryptBlob(current.employeeBlob, draftKey))
          .data;
        const masterKey = await loadMasterKey();
        if (!masterKey) throw new Error('tab is not unlocked');
        blob = await encryptBlob(answers, masterKey);
      } else {
        blob = replacement.rawBlob;
      }
      await client.apiPut(`/api/anketas/${anketaId}/draft`, { blob });
      sessionStorage.removeItem(`e1o1:draft-backup:${anketaId}`);
    },
    { anketaId: anketaUrl.split('/').pop()!, replacement },
  );
}

async function storedDraftBlob(page: Page, anketaUrl: string) {
  const response = await page.request.get(
    `/api/anketas/${anketaUrl.split('/').pop()}`,
  );
  return ((await response.json()) as { employeeBlob: string | null })
    .employeeBlob;
}

async function pair(browser: Browser, label: string) {
  const employeeEmail = uniqueEmail(`${label}-employee`);
  const managerEmail = uniqueEmail(`${label}-manager`);
  const employee = await activate(browser, employeeEmail);
  await activate(browser, managerEmail);
  const anketaUrl = await createAnketaWith(employee, managerEmail);
  return { employee, employeeEmail, anketaUrl };
}

/**
 * GitHub issue #129: unpublished drafts used to be encrypted with the
 * password-derived master key, so an in-app password change left them
 * undecryptable and the whole anketa page failing to load. They're now
 * encrypted with a key derived from the (unchanged) private key. Real crypto,
 * real UI.
 */
test('an unpublished draft survives an in-app password change', async ({
  browser,
}) => {
  const { employee, employeeEmail, anketaUrl } = await pair(
    browser,
    'pwchange-draft',
  );
  const marker = `E2E-DRAFT-MARKER-${Date.now()}`;
  await typeDraft(employee, marker);

  await changePassword(employee);

  // A brand-new session, so this tab's local draft backup
  // (anketa/draftBackup.ts) can't be what makes the draft readable — only the
  // server-side draft.
  const fresh = await logIn(browser, employeeEmail, NEW_PASSWORD);
  await fresh.goto(anketaUrl);
  const mySide = fresh.locator('.side-card').first();
  await expect(mySide.locator('textarea').first()).toHaveValue(marker);
  await expect(mySide.getByRole('alert')).toHaveCount(0);
});

test('a tab left open through a password change keeps saving readable drafts', async ({
  browser,
}) => {
  const { employee, employeeEmail, anketaUrl } = await pair(
    browser,
    'pwchange-stale',
  );
  // Unlocked with the old password and already on the anketa before the
  // change, so it never re-derives anything afterwards.
  const staleTab = await logIn(browser, employeeEmail, OLD_PASSWORD);
  await staleTab.goto(anketaUrl);
  await expect(
    staleTab.locator('.side-card').first().locator('textarea').first(),
  ).toBeVisible();
  await changePassword(employee);
  const marker = `E2E-STALE-DRAFT-${Date.now()}`;
  await typeDraft(staleTab, marker);

  const fresh = await logIn(browser, employeeEmail, NEW_PASSWORD);
  await fresh.goto(anketaUrl);
  await expect(
    fresh.locator('.side-card').first().locator('textarea').first(),
  ).toHaveValue(marker);
});

test('a draft saved under the master key before the fix still opens, and moves to the draft key', async ({
  browser,
}) => {
  const { employee, employeeEmail, anketaUrl } = await pair(
    browser,
    'pwchange-legacy',
  );
  const marker = `E2E-LEGACY-DRAFT-${Date.now()}`;
  await typeDraft(employee, marker);
  await replaceStoredDraft(employee, anketaUrl, 'legacy');
  const legacyBlob = await storedDraftBlob(employee, anketaUrl);

  await employee.reload();
  const mySide = employee.locator('.side-card').first();
  await expect(mySide.locator('textarea').first()).toHaveValue(marker);
  // Opening it autosaves it straight back, now under the draft key — which is
  // what lets it survive the password change below.
  await expect(mySide.getByText('Saved.')).toBeVisible();
  expect(await storedDraftBlob(employee, anketaUrl)).not.toBe(legacyBlob);

  await changePassword(employee);
  const fresh = await logIn(browser, employeeEmail, NEW_PASSWORD);
  await fresh.goto(anketaUrl);
  await expect(
    fresh.locator('.side-card').first().locator('textarea').first(),
  ).toHaveValue(marker);
});

test('an undecryptable draft degrades to a notice, is never autosaved over unprompted, and is flagged in the export', async ({
  browser,
}) => {
  const { employee, anketaUrl } = await pair(browser, 'pwchange-unreadable');
  // Stands in for a draft saved before a forgotten-password reset (a fresh
  // keypair, so a different draft key) — any blob neither key opens.
  const unreadable = 'E2E-UNREADABLE-DRAFT';
  await replaceStoredDraft(employee, anketaUrl, { rawBlob: unreadable });

  await employee.reload();
  const mySide = employee.locator('.side-card').first();
  await expect(mySide.getByRole('alert')).toContainText(
    "Your unpublished draft couldn't be opened",
  );
  await expect(mySide.locator('textarea').first()).toHaveValue('');
  // Longer than the 1s autosave debounce — merely opening the page must not
  // replace the stored draft with the blank form.
  await employee.waitForTimeout(2000);
  expect(await storedDraftBlob(employee, anketaUrl)).toBe(unreadable);

  await employee.goto('/account');
  const [download] = await Promise.all([
    employee.waitForEvent('download'),
    employee.getByRole('button', { name: 'Export as JSON' }).click(),
  ]);
  const exported = JSON.parse(
    await readFile((await download.path())!, 'utf8'),
  ) as {
    anketas: { id: string; myAnswers: unknown; myDraftUnreadable: boolean }[];
  };
  const row = exported.anketas.find((a) => a.id === anketaUrl.split('/').pop());
  expect(row?.myAnswers).toBeNull();
  expect(row?.myDraftUnreadable).toBe(true);

  // Typing replaces it, and the notice goes away.
  await employee.goto(anketaUrl);
  await typeDraft(employee, 'a fresh start');
  await expect(mySide.getByRole('alert')).toHaveCount(0);
  expect(await storedDraftBlob(employee, anketaUrl)).not.toBe(unreadable);
});

test('a master-key draft nobody reopened is moved to the draft key by the password change itself', async ({
  browser,
}) => {
  const { employee, employeeEmail, anketaUrl } = await pair(
    browser,
    'pwchange-legacy-unopened',
  );
  const marker = `E2E-LEGACY-UNOPENED-${Date.now()}`;
  await typeDraft(employee, marker);
  await replaceStoredDraft(employee, anketaUrl, 'legacy');

  // No reload of the anketa in between — only the password change can have
  // moved it.
  await changePassword(employee);

  const fresh = await logIn(browser, employeeEmail, NEW_PASSWORD);
  await fresh.goto(anketaUrl);
  const mySide = fresh.locator('.side-card').first();
  await expect(mySide.locator('textarea').first()).toHaveValue(marker);
  await expect(mySide.getByRole('alert')).toHaveCount(0);
});
