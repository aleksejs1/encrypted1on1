import type { Locator, Page } from '@playwright/test';

/**
 * The admin user table's <tbody> for the person with this email: their row and,
 * when open, their actions panel. Found by the row's own "Actions" button, since
 * the email also appears in the rows of this person's reports.
 * A deleted account has neither the button nor its address, so it isn't found.
 */
export function userRow(admin: Page, email: string): Locator {
  return admin.locator('tbody', { has: actionsButton(admin, email) });
}

/** The row's "Actions" button, named by the email alone or by "Name (email)". */
export function actionsButton(admin: Page, email: string): Locator {
  const escaped = email.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return admin.getByRole('button', {
    name: new RegExp(`^Actions for (.+ \\()?${escaped}\\)?$`),
  });
}

/** The "Manager" cell of the row with this email, in or out of edit mode. */
export function managerCell(admin: Page, email: string): Locator {
  return userRow(admin, email).locator('td', {
    has: admin.locator('[data-manager-change], [data-manager-select]'),
  });
}
