/**
 * Shared by generate-demo-fixture.mjs and generate-doc-screenshots.mjs —
 * both drive DateInput (design/DateInput.svelte) fields through Playwright.
 *
 * DateInput is a locale-formatted text field, not a native
 * `<input type=date>` — it only commits its typed text to the bound value
 * on blur, parsed against the *browser's* current date-format preference
 * (a `localStorage` value, see datePreference.svelte.ts — not anything
 * server-side or account-specific). Hardcoded here to `DEFAULT_DATE_FORMAT`
 * ('dmy_dot', i.e. `DD.MM.YYYY`, from frontend/src/dateFormat.ts, not
 * imported directly since these are plain Node scripts, not a Vite/TS
 * build) rather than reading the real constant, so it only stays correct as
 * long as (a) that default doesn't change and (b) every Playwright context
 * these scripts use starts with empty `localStorage`, which both scripts'
 * own fresh `browser.newContext()` per account already guarantees.
 *
 * The explicit `blur()` is required, not redundant with whatever the caller
 * does next: the button that follows (e.g. "Create anketa"/"Add goal") is
 * disabled until this field's value commits, so Playwright's own pre-click
 * actionability check never lets that click happen at all without it —
 * confirmed by hitting exactly this hang (a 30s timeout waiting for a
 * permanently-disabled button) before this call existed.
 *
 * `locator` must resolve to the component's own visible text input (e.g.
 * `page.locator('#meeting-date')` when DateInput has that `id`, or
 * `page.locator('.some-wrapper .date-input input[type=text]')` when it
 * doesn't).
 */
export async function fillDateInput(locator, date) {
  const dd = String(date.getDate()).padStart(2, '0');
  const mm = String(date.getMonth() + 1).padStart(2, '0');
  await locator.fill(`${dd}.${mm}.${date.getFullYear()}`);
  await locator.blur();
}
