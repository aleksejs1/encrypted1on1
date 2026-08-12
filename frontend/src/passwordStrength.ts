/**
 * A visual read on password strength — not new validation, just a UX signal
 * layered on top of the MIN_PASSWORD_LENGTH/mismatch checks each caller
 * already gates submission on. Mirrors the design mockup's own scoreOf().
 * Extracted once a third page (AccountSettings.svelte) needed the exact
 * same logic already duplicated in Activate.svelte/ResetPassword.svelte.
 */
export const MIN_PASSWORD_LENGTH = 12;
export const STRENGTH_COLORS = ['#c0574a', '#d68a3f', '#c9a23f', '#8fa073'];
export const STRENGTH_LABEL_KEYS = ['tooWeak', 'weak', 'okay', 'good', 'strong'] as const;

export function scoreOf(pw: string): number {
  if (!pw) return 0;
  let score = 0;
  if (pw.length >= 8) score++;
  if (pw.length >= 12) score++;
  if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
  if (/[0-9]/.test(pw) || /[^A-Za-z0-9]/.test(pw)) score++;
  return Math.min(score, 4);
}
