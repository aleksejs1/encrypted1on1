import type { Locator, Page } from '@playwright/test';

/** The archive form's card, at the bottom of an open meeting's page. */
function archiveSection(page: Page): Locator {
  return page.locator('section', { has: page.locator('#archive-heading') });
}

/**
 * The archive form's button. Its first press only asks for confirmation
 * (GitHub issue #229); gone once the confirmation is open or the meeting
 * archived.
 */
export function archiveButton(page: Page): Locator {
  return archiveSection(page).locator('[data-action="close"]');
}

/** The open confirmation's button that actually closes the meeting. */
export function confirmArchiveButton(page: Page): Locator {
  return archiveSection(page).locator('[data-action="confirm-close"]');
}

/** Closes the meeting from the archive form: the button, then its confirmation. */
export async function archive(page: Page): Promise<void> {
  await archiveButton(page).click();
  await confirmArchiveButton(page).click();
}
