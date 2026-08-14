import { SUPPORTED_LOCALES, type SupportedLocale } from './i18n';

/**
 * The one-click "try the demo" login on Login.svelte — gated by
 * VITE_DEMO_MODE (build-time, see vite-env.d.ts). Credentials here are
 * deliberately public: they match the fixed demo accounts seeded by
 * backend/fixtures/demo-seed.json and restored on a schedule by
 * `bin/console app:reset-demo-data`. See private/demo-mode-plan.md (not
 * tracked in git) for the full design.
 *
 * One employee/manager pair per supported UI locale, each with their own
 * realistic (translated) anketa history — see
 * frontend/scripts/demo-fixture-content.mjs. The demo button always logs
 * in as the *employee* half of whichever pair matches the currently active
 * UI locale (Login.svelte reads it from svelte-i18n's own locale store,
 * which `?lang=` in the URL can drive — see i18n/index.ts), so a visitor
 * sees a demo genuinely written in their own language, not just a
 * translated UI shell around English content.
 */
export const DEMO_MODE_ENABLED = import.meta.env.VITE_DEMO_MODE === 'true';

export const DEMO_PASSWORD = 'e1o1-demo-2026';

const DEMO_EMPLOYEE_EMAILS: Record<SupportedLocale, string> = {
  en: 'demo-employee@example.com',
  ru: 'demo-employee-ru@example.com',
  lv: 'demo-employee-lv@example.com',
  es: 'demo-employee-es@example.com',
};

export function demoEmailFor(locale: string): string {
  return (SUPPORTED_LOCALES as readonly string[]).includes(locale)
    ? DEMO_EMPLOYEE_EMAILS[locale as SupportedLocale]
    : DEMO_EMPLOYEE_EMAILS.en;
}
