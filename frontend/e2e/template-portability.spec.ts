import { readFile } from 'node:fs/promises';
import { test, expect, type Browser, type Page } from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

// Like admin-templates.spec.ts: every e2e account shares one company and
// templates can't be deleted, so each run adds templates there for good (two
// per test here). Both tests rely on that: an import into the same company
// meets its own original's name.

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

async function activate(browser: Browser, admin: boolean): Promise<Page> {
  const token = createActivationLink(
    uniqueEmail(admin ? 'admin' : 'member'),
    admin,
  );
  const context = await browser.newContext({
    permissions: ['clipboard-read', 'clipboard-write'],
  });
  const page = await context.newPage();
  await page.goto(`/activate/${token}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/** A saved template with one custom question; returns its editor URL. */
async function createTemplate(
  admin: Page,
  name: string,
  question: string,
): Promise<string> {
  await admin.goto('/admin/templates/new');
  await admin.getByLabel('Name', { exact: true }).fill(name);
  const employee = admin.locator('section.side').first();
  await employee.getByRole('button', { name: 'Add a custom question' }).click();
  await employee
    .locator('li.block')
    .last()
    .getByRole('textbox', { name: 'Question', exact: true })
    .fill(question);
  await admin.getByRole('button', { name: 'Save' }).click();
  await admin.waitForURL(/\/admin\/templates\/[0-9a-f-]+$/);
  return admin.url();
}

/**
 * GitHub issue #163: a template exported to a file and imported back, through
 * the real UI against the real backend.
 */
test('an admin exports a template to a file and imports it as a new one', async ({
  browser,
}) => {
  const admin = await activate(browser, true);
  const name = `Portable ${Date.now()}`;
  const question = 'What did you ship this sprint?';
  await createTemplate(admin, name, question);

  const downloading = admin.waitForEvent('download');
  await admin.getByRole('button', { name: 'Download file' }).click();
  const download = await downloading;
  expect(download.suggestedFilename()).toMatch(
    /^encrypted1on1-template-portable-\d+\.json$/,
  );
  const file = await download.path();
  const envelope = JSON.parse(await readFile(file, 'utf-8')) as {
    schemaVersion: number;
    template: { name: string; definition: { employee: unknown[] } };
  };
  expect(envelope.schemaVersion).toBe(1);
  expect(envelope.template.name).toBe(name);

  await admin.goto('/admin/templates');
  await admin.locator('input[type="file"]').setInputFiles(file);
  await admin.waitForURL('/admin/templates/new');

  // The same company already has this name, so the import is told apart.
  await expect(admin.getByText(/Template imported/)).toBeVisible();
  await expect(admin.getByLabel('Name', { exact: true })).toHaveValue(
    `${name} (Imported)`,
  );
  await expect(
    admin
      .locator('section.side')
      .first()
      .locator('li.block')
      .last()
      .getByRole('textbox', { name: 'Question', exact: true }),
  ).toHaveValue(question);
  await admin.getByRole('button', { name: 'Save' }).click();
  await admin.waitForURL(/\/admin\/templates\/[0-9a-f-]+$/);
  await expect(admin.getByText('Saved.')).toBeVisible();

  // A file that isn't a template is refused on the list, with nothing saved.
  await admin.goto('/admin/templates');
  await admin.locator('input[type="file"]').setInputFiles({
    name: 'nope.json',
    mimeType: 'application/json',
    buffer: Buffer.from('{"hello": "world"}'),
  });
  await expect(admin.getByRole('alert')).toHaveText(
    "This isn't an encrypted1on1 template.",
  );
  await expect(admin).toHaveURL(/\/admin\/templates$/);
});

test('a share link previews the template for anyone, and an admin installs it', async ({
  browser,
}) => {
  const author = await activate(browser, true);
  const name = `Shared ${Date.now()}`;
  const question = 'Which habit helped you most?';
  await createTemplate(author, name, question);

  await author.getByRole('button', { name: 'Copy share link' }).click();
  // Announced through the status region, which is always present.
  const shareStatus = author
    .getByRole('region', { name: 'Export and share' })
    .getByRole('status');
  await expect(shareStatus).toHaveText('Link copied.');
  const link = await author.evaluate<string>('navigator.clipboard.readText()');
  expect(link).toMatch(/\/templates\/preview#v1z:[A-Za-z0-9_-]+$/);
  const target = link.slice(link.indexOf('/templates/preview'));
  // An edit makes the copied link stale, so its confirmation goes.
  await author.getByLabel('Description (optional)').fill('Changed');
  await expect(shareStatus).toHaveText('');

  // A visitor sees the questions and is pointed at the login page.
  const visitor = await (await browser.newContext()).newPage();
  await visitor.goto(target);
  await expect(visitor.getByRole('heading', { name })).toBeVisible();
  await expect(visitor.getByRole('heading', { name: question })).toBeVisible();
  await expect(visitor.getByRole('link', { name: 'Log in' })).toBeVisible();

  // A member is asked to pass it on.
  const member = await activate(browser, false);
  await member.goto(target);
  await expect(
    member.getByText(/ask your company's administrator to install it/),
  ).toBeVisible();
  await expect(
    member.getByRole('button', { name: 'Install to company library' }),
  ).toHaveCount(0);

  // Another admin installs it: the form opens pre-filled, nothing saved yet.
  const installer = await activate(browser, true);
  await installer.goto(target);
  await installer
    .getByRole('button', { name: 'Install to company library' })
    .click();
  await installer.waitForURL('/admin/templates/new');
  await expect(installer.getByLabel('Name', { exact: true })).toHaveValue(
    `${name} (Imported)`,
  );
  await installer.getByRole('button', { name: 'Save' }).click();
  await installer.waitForURL(/\/admin\/templates\/[0-9a-f-]+$/);
  await expect(installer.getByText('Saved.')).toBeVisible();

  // Installed, it's a meeting type like any other.
  await installer.goto('/anketas/new');
  // (The radio itself is visually hidden behind its styled label.)
  await expect(
    installer.locator('label.radio', { hasText: `${name} (Imported)` }),
  ).toBeVisible();

  // A damaged link says so instead of showing anything.
  await visitor.goto(target.slice(0, -12));
  await expect(visitor.getByRole('alert')).toHaveText(
    'The share link is invalid or incomplete.',
  );
});
