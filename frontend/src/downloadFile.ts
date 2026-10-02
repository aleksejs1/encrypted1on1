/**
 * Saves `content` as a JSON file, through a link to it the browser
 * downloads: the account data export and a template's export (GitHub issue
 * #163).
 */
export function downloadJsonFile(fileName: string, content: string): void {
  const url = URL.createObjectURL(
    new Blob([content], { type: 'application/json' }),
  );
  const link = document.createElement('a');
  link.href = url;
  link.download = fileName;
  link.click();
  // Revoked once the click has started the download.
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
