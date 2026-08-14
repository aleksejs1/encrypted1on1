/**
 * The user's preferred date-display format — same pattern as theme.svelte.ts
 * (localStorage override, else a fixed default, applied as a module-level
 * side effect) and i18n/index.ts's locale detection. A pure client-side
 * display preference, not synced to the server — same reasoning as theme
 * and unlike User.locale (which only ever drives email language), there's
 * no server-rendered content that needs to know this.
 */
import {
  DATE_FORMAT_IDS,
  DEFAULT_DATE_FORMAT,
  formatDate,
  type DateFormatId,
} from './dateFormat';

const STORAGE_KEY = 'e1o1:dateFormat';

function isSupported(code: string | null | undefined): code is DateFormatId {
  return null != code && (DATE_FORMAT_IDS as readonly string[]).includes(code);
}

function detectInitialDateFormat(): DateFormatId {
  const stored = localStorage.getItem(STORAGE_KEY);
  return isSupported(stored) ? stored : DEFAULT_DATE_FORMAT;
}

export const dateFormatState = $state<{ format: DateFormatId }>({
  format: detectInitialDateFormat(),
});

export function setDateFormat(format: DateFormatId): void {
  dateFormatState.format = format;
  localStorage.setItem(STORAGE_KEY, format);
}

/** What every page should call to display a date — follows whatever format is currently selected. */
export function formatDisplayDate(value: string | Date): string {
  return formatDate(value, dateFormatState.format);
}
