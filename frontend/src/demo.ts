import type { SupportedLocale } from './i18n';

/**
 * The one-click "try the demo" login on Login.svelte — gated by
 * VITE_DEMO_MODE (build-time, see vite-env.d.ts). Credentials here are
 * deliberately public: they match the fixed demo accounts seeded by
 * backend/fixtures/demo-seed.json and restored on a schedule by
 * `bin/console app:reset-demo-data`. See private/demo-mode-plan.md (not
 * tracked in git) for the full design.
 *
 * One employee/manager pair per supported UI locale that has one, each
 * with their own realistic (translated) anketa history — see
 * frontend/scripts/demo-fixture-content.mjs. The demo button always logs
 * in as the *employee* half of whichever pair matches the currently active
 * UI locale (Login.svelte reads it from svelte-i18n's own locale store,
 * which `?lang=` in the URL can drive — see i18n/index.ts), so a visitor
 * sees a demo genuinely written in their own language, not just a
 * translated UI shell around English content.
 *
 * Not every UI locale has a demo pair yet — German (issue #34) and French
 * (issue #46) added the interface translation but not a translated demo
 * narrative, which needs its own content pass and a regenerated fixture
 * (see generate-demo-fixture.mjs). Such locales carry an explicit `null`
 * below (rather than being left out of the map) so adding a locale to
 * SUPPORTED_LOCALES without updating this map is a compile error, not a
 * silent gap — demoEmailFor() falls back to the English demo account for
 * any `null` entry rather than pointing at an account that doesn't exist.
 */
export const DEMO_MODE_ENABLED = import.meta.env.VITE_DEMO_MODE === 'true';

export const DEMO_PASSWORD = 'e1o1-demo-2026';

const DEMO_EMPLOYEE_EMAIL_EN = 'demo-employee@example.com';

const DEMO_EMPLOYEE_EMAILS: Record<SupportedLocale, string | null> = {
  en: DEMO_EMPLOYEE_EMAIL_EN,
  ru: 'demo-employee-ru@example.com',
  lv: 'demo-employee-lv@example.com',
  es: 'demo-employee-es@example.com',
  de: null,
  fr: null,
};

export function demoEmailFor(locale: string): string {
  // hasOwnProperty guard (not a plain `in`/index check) so a locale string
  // that happens to match an inherited Object.prototype key (e.g.
  // "constructor") can't resolve to that prototype value instead of falling
  // back to English.
  if (!Object.prototype.hasOwnProperty.call(DEMO_EMPLOYEE_EMAILS, locale)) {
    return DEMO_EMPLOYEE_EMAIL_EN;
  }
  return (
    DEMO_EMPLOYEE_EMAILS[locale as SupportedLocale] ?? DEMO_EMPLOYEE_EMAIL_EN
  );
}
