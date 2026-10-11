import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';
import { actionsButton, managerCell } from './helpers/adminTable.js';

const PASSWORD = 'correct horse battery staple 123';

test.afterEach(async ({ browser }) => {
  await Promise.all(browser.contexts().map((context) => context.close()));
});

/** Activates an account and returns its page, still logged in. */
async function activate(
  browser: Browser,
  email: string,
  admin = false,
): Promise<Page> {
  const page = await (await browser.newContext()).newPage();
  await page.goto(`/activate/${createActivationLink(email, admin)}`);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');
  return page;
}

/** Three accounts and an admin on the admin panel. */
async function company(
  browser: Browser,
  label: string,
): Promise<{ admin: Page; anna: string; boris: string; clara: string }> {
  const [anna, boris, clara] = ['anna', 'boris', 'clara'].map((name) =>
    uniqueEmail(`${label}-${name}`),
  );
  for (const email of [anna, boris, clara]) {
    await (await activate(browser, email)).context().close();
  }
  const admin = await activate(browser, uniqueEmail(`${label}-admin`), true);
  await admin.goto('/admin');
  return { admin, anna, boris, clara };
}

const card = (admin: Page): Locator => admin.locator('.org-import');

/** Picks a CSV file with this content and asks for the preview. */
async function check(admin: Page, csv: string): Promise<void> {
  // The card is collapsed until opened, after every page load.
  const toggle = card(admin).getByRole('button', {
    name: 'Import reporting lines',
  });
  if ((await toggle.getAttribute('aria-expanded')) === 'false') {
    await toggle.click();
  }
  await card(admin)
    .locator('input[type="file"]')
    .setInputFiles({
      name: 'org.csv',
      mimeType: 'text/csv',
      buffer: Buffer.from(csv, 'utf-8'),
    });
  await card(admin).getByRole('button', { name: 'Check the file' }).click();
}

const applyButton = (admin: Page): Locator =>
  card(admin).getByRole('button', { name: 'Apply changes' });

/**
 * GitHub issue #272 (part of #265): the admin panel's import card, through the real
 * UI against the real backend. Every e2e account shares one company, across runs
 * too, so each test names only its own accounts and asserts only on their rows.
 */
test('a file is previewed, then applied; a row about an unknown email is left out', async ({
  browser,
}) => {
  const { admin, anna, boris, clara } = await company(browser, 'imp');

  // Collapsed until opened.
  await expect(
    card(admin).getByRole('button', { name: 'Import reporting lines' }),
  ).toHaveAttribute('aria-expanded', 'false');
  await expect(admin.locator('#org-import-body')).toBeHidden();

  // An import that starts closes a row's actions panel that was left open.
  await actionsButton(admin, anna).click();
  await expect(actionsButton(admin, anna)).toHaveAttribute(
    'aria-expanded',
    'true',
  );
  await check(
    admin,
    `employee_email,manager_email\n${anna},${boris}\nghost-${anna},${boris}\n\n${clara},\n`,
  );
  await expect(card(admin)).toContainText('nothing has been changed yet');
  await expect(actionsButton(admin, anna)).toHaveAttribute(
    'aria-expanded',
    'false',
  );
  await expect(card(admin)).toContainText('manager will change: 1');
  await expect(card(admin)).toContainText('Already as in the file: 1');
  await expect(card(admin)).toContainText('Rows left out: 1');
  const problem = card(admin).locator('tbody tr');
  await expect(problem).toHaveCount(1);
  await expect(problem.locator('td').first()).toHaveText('3');
  await expect(problem).toContainText(`ghost-${anna} → ${boris}`);
  await expect(problem).toContainText('Nobody in the company has this email');
  // A preview only.
  await expect(managerCell(admin, anna)).toContainText('—');

  await applyButton(admin).click();
  await expect(card(admin)).toContainText('Imported');
  await expect(card(admin)).toContainText('manager changed: 1');
  // The table reloaded by itself.
  await expect(managerCell(admin, anna)).toContainText(boris);
  await expect(applyButton(admin)).toHaveCount(0);
  await admin.reload();
  await expect(managerCell(admin, anna)).toContainText(boris);

  // A blank manager cell clears, and the preview says whose.
  await check(admin, `employee_email;manager_email\n${anna};\n${clara};\n`);
  await expect(card(admin)).toContainText('will have no manager');
  await expect(card(admin).locator('.cleared li')).toHaveText([anna]);
  await applyButton(admin).click();
  await expect(card(admin)).toContainText('Imported');
  await expect(managerCell(admin, anna)).toContainText('—');
});

test('a file that would make a loop cannot be applied', async ({ browser }) => {
  const { admin, anna, boris, clara } = await company(browser, 'imp-loop');

  await check(admin, `employee_email,manager_email\n${anna},${boris}\n`);
  await applyButton(admin).click();
  await expect(managerCell(admin, anna)).toContainText(boris);

  await check(
    admin,
    `employee_email,manager_email\n${clara},${anna}\n${boris},${anna}\n`,
  );
  await expect(card(admin)).toContainText('would make a loop');
  await expect(card(admin)).toContainText('manager will change: 0');
  await expect(card(admin).locator('tbody tr')).toContainText(
    'This row would make a loop',
  );
  await expect(applyButton(admin)).toBeDisabled();
  await admin.reload();
  await expect(managerCell(admin, clara)).toContainText('—');
  await expect(managerCell(admin, boris)).toContainText('—');
});

test('a manager named by name is found in the same file', async ({
  browser,
}) => {
  const { admin, anna, boris, clara } = await company(browser, 'imp-name');

  await check(
    admin,
    'Name;Email;Direct manager\n' +
      `Jordan Lee;${boris};\n` +
      `Anna Berg;${anna};jordan  LEE\n` +
      `Clara Diaz;${clara};Someone Else\n`,
  );
  // The columns were guessed, the name column included.
  await expect(
    card(admin).getByLabel("Column with the person's name"),
  ).toHaveValue('0');
  await expect(card(admin)).toContainText('manager will change: 1');
  const problem = card(admin).locator('tbody tr');
  await expect(problem).toHaveCount(1);
  await expect(problem.locator('td').first()).toHaveText('4');
  await expect(problem).toContainText('Someone Else');
  // Who each name was taken for is shown before anything is applied.
  await expect(card(admin).locator('.by-name li')).toHaveText([
    `jordan LEE → ${boris}`,
  ]);
  await expect(problem).toContainText(
    "Nobody in the file has this manager's name",
  );

  await applyButton(admin).click();
  await expect(managerCell(admin, anna)).toContainText(boris);
  await expect(managerCell(admin, clara)).toContainText('—');
});
