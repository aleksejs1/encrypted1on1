import {
  test,
  expect,
  type Browser,
  type Locator,
  type Page,
} from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

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

/** The "Manager" cell of the row with this email (the second column). */
function managerCell(admin: Page, email: string): Locator {
  return admin
    .locator('tr', {
      has: admin.locator('td:nth-child(2)', { hasText: email }),
    })
    .locator('td:nth-child(5)');
}

function changeButton(admin: Page, email: string): Locator {
  return admin.getByRole('button', {
    name: `Change the manager of ${email}`,
  });
}

function managerSelect(admin: Page, email: string): Locator {
  return admin.getByRole('combobox', { name: `Manager of ${email}` });
}

async function cancelEdit(admin: Page, email: string): Promise<void> {
  await managerCell(admin, email)
    .getByRole('button', { name: 'Cancel', exact: true })
    .click();
}

/** Opens the row's editor, picks the option naming `managerEmail` ('' for none), saves. */
async function setManager(
  admin: Page,
  email: string,
  managerEmail: string,
): Promise<void> {
  await changeButton(admin, email).click();
  const select = managerSelect(admin, email);
  await expect(select).toBeFocused();
  await select.selectOption(managerEmail === '' ? '' : { label: managerEmail });
  await managerCell(admin, email)
    .getByRole('button', { name: 'Save', exact: true })
    .click();
}

/**
 * The row is out of edit mode and shows this manager ('—' for none). The closed
 * editor comes first: an open one's options contain the same text.
 */
async function expectManager(
  admin: Page,
  email: string,
  shown: string,
): Promise<void> {
  await expect(managerSelect(admin, email)).toHaveCount(0);
  await expect(managerCell(admin, email)).toContainText(shown);
}

/**
 * GitHub issue #267 (part of #265): the admin user table's "Manager" column and its
 * "no manager" filter, through the real UI against the real backend. Every e2e
 * account shares one company, across runs too, so rows are found by their own
 * unique emails and no count of the whole table is asserted.
 */
test('an admin sets, clears and filters by manager; a cycle is refused', async ({
  browser,
}) => {
  const annaEmail = uniqueEmail('org-anna');
  const borisEmail = uniqueEmail('org-boris');
  await (await activate(browser, annaEmail)).context().close();
  await (await activate(browser, borisEmail)).context().close();
  const admin = await activate(browser, uniqueEmail('org-admin'), true);

  await admin.goto('/admin');
  await expectManager(admin, annaEmail, '—');

  // A person isn't offered as their own manager, and picking alone saves nothing.
  await changeButton(admin, annaEmail).click();
  await expect(
    managerSelect(admin, annaEmail).locator('option', { hasText: annaEmail }),
  ).toHaveCount(0);
  await managerSelect(admin, annaEmail).selectOption({ label: borisEmail });
  // While a row is edited, the other rows' actions and the filter wait.
  await expect(changeButton(admin, borisEmail)).toBeDisabled();
  await expect(
    admin.getByRole('checkbox', { name: /Only people without a manager/ }),
  ).toBeDisabled();
  await cancelEdit(admin, annaEmail);
  await expect(changeButton(admin, annaEmail)).toBeFocused();
  await expectManager(admin, annaEmail, '—');

  // Set: Anna reports to Boris. Focus lands back on the row's button.
  await setManager(admin, annaEmail, borisEmail);
  await expectManager(admin, annaEmail, borisEmail);
  await expect(changeButton(admin, annaEmail)).toBeFocused();

  // It was saved, not just shown.
  await admin.reload();
  await expectManager(admin, annaEmail, borisEmail);

  // A cycle: the server refuses, the message is in the row, nothing is saved.
  await setManager(admin, borisEmail, annaEmail);
  await expect(managerCell(admin, borisEmail).getByRole('alert')).toContainText(
    'This would make a loop',
  );
  await expect(managerSelect(admin, borisEmail)).toBeFocused();
  await cancelEdit(admin, borisEmail);
  await expectManager(admin, borisEmail, '—');
  await admin.reload();
  await expectManager(admin, borisEmail, '—');
  await expectManager(admin, annaEmail, borisEmail);

  // The filter keeps Boris (no manager) and drops Anna.
  const filter = admin.getByRole('checkbox', {
    name: /Only people without a manager/,
  });
  await filter.check();
  await expect(changeButton(admin, borisEmail)).toBeVisible();
  await expect(changeButton(admin, annaEmail)).toHaveCount(0);
  await filter.uncheck();

  // Clear: Anna has no manager again, and is back under the filter.
  await setManager(admin, annaEmail, '');
  await expectManager(admin, annaEmail, '—');
  await admin.reload();
  await expectManager(admin, annaEmail, '—');
  await filter.check();
  await expect(changeButton(admin, annaEmail)).toBeVisible();

  // Under the filter, a row that gets a manager leaves, and the filter takes focus.
  await setManager(admin, annaEmail, borisEmail);
  await expect(changeButton(admin, annaEmail)).toHaveCount(0);
  await expect(filter).toBeFocused();
});

test('a blocked manager stays the manager, marked, and is not offered to others; deleting them clears it', async ({
  browser,
}) => {
  const annaEmail = uniqueEmail('org-blk-anna');
  const borisEmail = uniqueEmail('org-blk-boris');
  const claraEmail = uniqueEmail('org-blk-clara');
  for (const email of [annaEmail, borisEmail, claraEmail]) {
    await (await activate(browser, email)).context().close();
  }
  const admin = await activate(browser, uniqueEmail('org-blk-admin'), true);

  await admin.goto('/admin');
  await setManager(admin, annaEmail, borisEmail);
  await expectManager(admin, annaEmail, borisEmail);

  const borisRow = admin.locator('tr', {
    has: admin.locator('td:nth-child(2)', { hasText: borisEmail }),
  });
  await borisRow.getByRole('button', { name: 'Block' }).click();

  await expectManager(admin, annaEmail, `${borisEmail} (blocked)`);
  await changeButton(admin, claraEmail).click();
  await expect(
    managerSelect(admin, claraEmail).locator('option', {
      hasText: borisEmail,
    }),
  ).toHaveCount(0);
  await cancelEdit(admin, claraEmail);

  // Deleting the manager's account leaves Anna with none, without a reload.
  admin.once('dialog', (dialog) => dialog.accept(borisEmail));
  await borisRow.getByRole('button', { name: 'Delete permanently' }).click();
  await expectManager(admin, annaEmail, '—');
  await expect(managerCell(admin, annaEmail)).not.toContainText('blocked');
});
