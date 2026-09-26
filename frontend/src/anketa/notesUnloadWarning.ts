import { hasNotesBackups } from './notesBackup';

/**
 * Closing the tab warns while the logged-in user (or, logged out, anyone) has
 * a private-notes backup left in it (notesBackup.ts). Every unsaved state keeps a backup and idle
 * clears it, so a backup no live panel is taking care of — after a failed last
 * save, in-app navigation, a logout or a refresh — is text that exists only in
 * this tab. Read from sessionStorage at the moment it matters, so it holds no
 * text and survives a refresh.
 *
 * App.svelte re-evaluates this whenever the logged-in user changes (startup,
 * unlock, logout, login), and notesSession.ts whenever a panel's backup
 * changes. The listener is attached only while such a backup exists: Firefox
 * keeps no page with a beforeunload listener in its back-forward cache.
 */
let userIdForWarning: string | null = null;
let attachedOn: Window | null = null;

function onBeforeUnload(event: BeforeUnloadEvent): void {
  if (hasNotesBackups(userIdForWarning)) {
    event.preventDefault();
    event.returnValue = '';
  }
}

/**
 * `userId` null means logged out: then any notes backup in the tab warns.
 * There's no other user's to protect, and a logout with unsaved notes (no
 * save goes out once the session has ended) leaves the text only there.
 */
export function refreshNotesUnloadWarning(userId: string | null): void {
  userIdForWarning = userId;
  const needed = hasNotesBackups(userId);
  if (needed && attachedOn !== window) {
    window.addEventListener('beforeunload', onBeforeUnload);
    attachedOn = window;
  } else if (!needed && attachedOn === window) {
    window.removeEventListener('beforeunload', onBeforeUnload);
    attachedOn = null;
  }
}
