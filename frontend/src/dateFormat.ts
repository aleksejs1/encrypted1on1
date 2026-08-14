/**
 * Pure date-display formatting — no `Intl`/browser-locale involvement, so
 * the result is exactly what the user picked in Account Settings, not
 * approximated by whatever format the browser's own locale happens to use
 * (the app's previous behavior, `new Date(x).toLocaleDateString()` with no
 * explicit options, showed US-style dates even under a Russian UI locale
 * whenever the browser's own locale was English).
 */

export const DATE_FORMAT_IDS = [
  'dmy_dot',
  'dmy_slash',
  'mdy_slash',
  'iso',
] as const;
export type DateFormatId = (typeof DATE_FORMAT_IDS)[number];

export const DEFAULT_DATE_FORMAT: DateFormatId = 'dmy_dot';

function pad(n: number): string {
  return String(n).padStart(2, '0');
}

const DATE_ONLY_PATTERN = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Accepts either a full ISO datetime string (meeting dates, checkpoint/entry
 * timestamps — anything stamped via `new Date().toISOString()`) or a plain
 * `YYYY-MM-DD` date-only string (a goal's `targetDate`, which has no
 * inherent time-of-day) — or a `Date` object directly.
 *
 * The date-only case is handled separately, not by routing it through
 * `new Date(value)`: JS parses a bare `YYYY-MM-DD` string as UTC midnight,
 * and reading it back with the local-time getters (`getDate()` etc.) can
 * shift it a day earlier or later depending on the viewer's own timezone
 * offset — a real bug for a pure calendar date that was never a UTC moment
 * to begin with. Parsing the components directly avoids that entirely.
 */
export function formatDate(
  value: string | Date,
  formatId: DateFormatId,
): string {
  let day: number;
  let month: number;
  let year: number;

  if (typeof value === 'string' && DATE_ONLY_PATTERN.test(value)) {
    const [y, m, d] = value.split('-').map(Number);
    year = y;
    month = m;
    day = d;
  } else {
    const date = typeof value === 'string' ? new Date(value) : value;
    year = date.getFullYear();
    month = date.getMonth() + 1;
    day = date.getDate();
  }

  const dd = pad(day);
  const mm = pad(month);
  const yyyy = String(year);

  switch (formatId) {
    case 'dmy_dot':
      return `${dd}.${mm}.${yyyy}`;
    case 'dmy_slash':
      return `${dd}/${mm}/${yyyy}`;
    case 'mdy_slash':
      return `${mm}/${dd}/${yyyy}`;
    case 'iso':
      return `${yyyy}-${mm}-${dd}`;
  }
}
