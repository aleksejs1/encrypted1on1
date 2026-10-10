/**
 * The archive form's heading (AnketaArchiveSection), which the header's "not
 * closed" card scrolls to and focuses (GitHub issue #201).
 */
export const ARCHIVE_HEADING_ID = 'archive-heading';

/**
 * Scrolls to the archive form and focuses its heading: from the "not closed"
 * card, and when the page was opened from the follow-up email's "close" link
 * (GitHub issue #202). Does nothing on an archived anketa, which has no form.
 */
export function goToArchiveSection(): void {
  const heading = document.getElementById(ARCHIVE_HEADING_ID);
  heading?.closest('section')?.scrollIntoView({ block: 'start' });
  heading?.focus({ preventScroll: true });
}
