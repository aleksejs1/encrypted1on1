/**
 * Turns a user's optional plaintext displayName + email into what the UI should
 * actually show — every call site used to show raw email/uuid directly, which read
 * oddly as an identifier. displayName is optional (empty string means "not set"),
 * so every function here falls back to email in that case.
 */

/** The first token of a name, e.g. for the anketa page's tight two-column layout. */
export function firstWord(name: string): string {
  return name.trim().split(/\s+/)[0] ?? '';
}

/** First name only if set, else the full email — for space-constrained spots inside an anketa. */
export function shortDisplayName(name: string, email: string): string {
  const trimmed = name.trim();
  return trimmed === '' ? email : firstWord(trimmed);
}

/** Full name if set, else the full email — for the header, anketa list, and admin panels. */
export function fullDisplayName(name: string, email: string): string {
  const trimmed = name.trim();
  return trimmed === '' ? email : trimmed;
}

/** "Full Name (email)" if a name is set, else just the email — for picking a person out of a list. */
export function nameWithEmail(name: string, email: string): string {
  const trimmed = name.trim();
  return trimmed === '' ? email : `${trimmed} (${email})`;
}
