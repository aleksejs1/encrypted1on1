import { test, expect, type Browser, type Page } from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

// Every e2e account lands in the one e2e company, and templates can't be
// deleted, so each run of this file adds templates there for good. `make
// e2e-up` (and CI) start from an empty database; a stack reused for about 25
// runs of this file reaches the 50-template cap.

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

async function activate(browser: Browser, admin: boolean): Promise<Page> {
  const token = createActivationLink(
    uniqueEmail(admin ? 'admin' : 'member'),
    admin,
  );
  const page = await (await browser.newContext()).newPage();
  await page.goto(`/activate/${token}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/** Creates a template through the editor and returns its editor URL. */
async function createTemplate(admin: Page, name: string): Promise<string> {
  await admin.goto('/admin/templates/new');
  await admin.getByLabel('Name', { exact: true }).fill(name);
  await admin.getByRole('button', { name: 'Save' }).click();
  await admin.waitForURL(/\/admin\/templates\/[0-9a-f-]+$/);
  return admin.url();
}

/**
 * GitHub issue #143 (#133 §6, e2e for C3): the admin template editor, driven
 * through the real UI against the real backend.
 */
test('an admin builds, previews, saves, edits, archives and restores a template', async ({
  browser,
}) => {
  const admin = await activate(browser, true);
  // Unique: every e2e account shares one company, across runs too.
  const name = `Weekly sync ${Date.now()}`;

  await admin.goto('/admin');
  await admin.getByRole('link', { name: 'Templates' }).click();
  await admin.waitForURL('/admin/templates');
  // Every e2e account shares one company, so earlier runs' templates may be here.
  await expect(
    admin.getByRole('heading', { name: 'Meeting templates' }),
  ).toBeVisible();
  await admin.getByRole('link', { name: 'New template' }).click();
  await admin.waitForURL('/admin/templates/new');

  // Pre-filled with the Regular check-in, and not savable without a name.
  const employee = admin.locator('section.side').first();
  await expect(employee.locator('li.block')).toHaveCount(7);
  await expect(employee.getByText('Mood')).toBeVisible();
  await expect(admin.getByText(/stored unencrypted/)).toBeVisible();
  await expect(admin.getByRole('button', { name: 'Save' })).toBeDisabled();
  await admin.getByLabel('Name', { exact: true }).fill(name);

  // Drop Feelings, then add a single-choice question of our own.
  await employee.getByRole('button', { name: 'Remove: Feelings' }).click();
  // Focus lands on the side's heading, not on <body>.
  await expect(
    admin.getByRole('heading', { name: 'Employee answers' }),
  ).toBeFocused();
  // Moving a block keeps focus on the same button, so it can be pressed again.
  await employee.getByRole('button', { name: 'Move down: Mood' }).click();
  await expect(
    employee.getByRole('button', { name: 'Move down: Mood' }),
  ).toBeFocused();
  await employee.getByRole('button', { name: 'Move up: Mood' }).click();

  // A standard question comes back through its own Add button.
  await employee
    .getByRole('combobox', { name: 'Add a standard question…' })
    .selectOption({ label: 'Feelings' });
  await employee.getByRole('button', { name: 'Add', exact: true }).click();
  await expect(employee.locator('li.block')).toHaveCount(7);
  await employee.getByRole('button', { name: 'Remove: Feelings' }).click();
  await employee.getByRole('button', { name: 'Add a custom question' }).click();
  const custom = employee.locator('li.block').last();
  await custom
    .getByRole('textbox', { name: 'Question', exact: true })
    .fill('How focused were you?');
  await custom
    .getByLabel('Answer type')
    .selectOption({ label: 'Single choice' });
  // An empty choice is an error until it's filled in.
  await expect(admin.getByRole('button', { name: 'Save' })).toBeDisabled();
  await custom
    .getByRole('textbox', { name: 'Choice 1', exact: true })
    .fill('Very');
  await custom
    .getByRole('textbox', { name: 'Choice 2', exact: true })
    .fill('Not much');

  // The preview renders the real answer fields.
  await admin.getByRole('button', { name: 'Preview' }).click();
  const preview = admin.locator('section.preview');
  await expect(
    preview.getByRole('heading', { name: 'How focused were you?' }),
  ).toBeVisible();
  await expect(preview.getByText('Not much')).toBeVisible();
  await expect(
    preview.getByRole('heading', { name: 'Feelings', exact: true }),
  ).toHaveCount(0);

  // An answer picked in the preview doesn't survive a change of type. Single
  // → multiple → single choice keeps the choices' values, so a leftover
  // answer would show up as chosen again.
  await preview.getByText('Very').click();
  await expect(preview.getByRole('radio', { name: 'Very' })).toBeChecked();
  await custom
    .getByLabel('Answer type')
    .selectOption({ label: 'Multiple choice' });
  await expect(preview.getByRole('button', { name: 'Very' })).toHaveAttribute(
    'aria-pressed',
    'false',
  );
  await custom
    .getByLabel('Answer type')
    .selectOption({ label: 'Single choice' });
  await expect(preview.getByRole('radio', { name: 'Very' })).not.toBeChecked();

  await admin.getByRole('button', { name: 'Save' }).click();
  await admin.waitForURL(/\/admin\/templates\/[0-9a-f-]+$/);
  const editorUrl = admin.url();
  // The new template's own page confirms the save.
  await expect(admin.getByText('Saved.')).toBeVisible();

  // Reloaded from the server: the question is there; edit and save again.
  const reloaded = admin
    .locator('section.side')
    .first()
    .locator('li.block')
    .last();
  await expect(
    reloaded.getByRole('textbox', { name: 'Question', exact: true }),
  ).toHaveValue('How focused were you?');
  await reloaded
    .getByRole('textbox', { name: 'Question', exact: true })
    .fill('How focused were you this week?');
  await admin.getByRole('button', { name: 'Save' }).click();
  await expect(admin.getByText('Saved.')).toBeVisible();

  // Archive from the list; the editor is then read-only until restored.
  await admin.goto('/admin/templates');
  const row = admin.locator('li.template-row', { hasText: name });
  await row.getByRole('button', { name: `Archive: ${name}` }).click();
  await expect(row.getByText('archived')).toBeVisible();
  // Focus follows to the row's new button rather than dropping to <body>.
  await expect(
    row.getByRole('button', { name: `Restore: ${name}` }),
  ).toBeFocused();
  await admin.goto(editorUrl);
  await expect(admin.getByText(/This template is archived/)).toBeVisible();
  await expect(admin.getByLabel('Name', { exact: true })).toBeDisabled();
  await expect(admin.getByRole('button', { name: 'Save' })).toHaveCount(0);
  await admin.getByRole('button', { name: 'Restore' }).click();
  await expect(admin.getByLabel('Name', { exact: true })).toBeEnabled();
  await expect(admin.getByRole('button', { name: 'Save' })).toBeVisible();
});

test('a non-admin cannot reach the template editor', async ({ browser }) => {
  const member = await activate(browser, false);

  for (const path of ['/admin/templates', '/admin/templates/new']) {
    await member.goto(path);
    await expect(member.getByText('Not authorized.')).toBeVisible();
    await expect(member.getByRole('link', { name: 'Templates' })).toHaveCount(
      0,
    );
  }
});

test('a save that loses to another admin keeps the edit and offers a reload', async ({
  browser,
}) => {
  const first = await activate(browser, true);
  const second = await activate(browser, true);
  const editorUrl = await createTemplate(first, 'Shared template');

  await first.goto(editorUrl);
  await second.goto(editorUrl);
  await second
    .getByLabel('Description (optional)')
    .fill('Second admin was here');
  await second.getByRole('button', { name: 'Save' }).click();
  await expect(second.getByText('Saved.')).toBeVisible();

  await first.getByLabel('Description (optional)').fill('First admin was here');
  await first.getByRole('button', { name: 'Save' }).click();
  await expect(first.getByRole('alert')).toContainText(
    'changed by someone else',
  );
  // Save stays disabled until a reload, so focus goes to Reload.
  await expect(first.getByRole('button', { name: 'Reload' })).toBeFocused();
  // The edit is kept until the admin reloads.
  await expect(first.getByLabel('Description (optional)')).toHaveValue(
    'First admin was here',
  );
  await first.getByRole('button', { name: 'Reload' }).click();
  await expect(first.getByLabel('Description (optional)')).toHaveValue(
    'Second admin was here',
  );
});

test('leaving the editor with unsaved changes asks first', async ({
  browser,
}) => {
  const admin = await activate(browser, true);
  await admin.goto('/admin/templates/new');
  await admin.getByLabel('Name', { exact: true }).fill('Unsaved');

  const dialog = admin.waitForEvent('dialog');
  // The back link is a full page load, so beforeunload guards it.
  void admin.getByRole('link', { name: '← All templates' }).click();
  const prompt = await dialog;
  expect(prompt.type()).toBe('beforeunload');
  await prompt.dismiss();
  await expect(admin).toHaveURL('/admin/templates/new');
  await expect(admin.getByLabel('Name', { exact: true })).toHaveValue(
    'Unsaved',
  );
});
