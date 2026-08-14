/**
 * The one-click "try the demo" login on Login.svelte — gated by
 * VITE_DEMO_MODE (build-time, see vite-env.d.ts). Credentials here are
 * deliberately public: they match the fixed demo account seeded by
 * backend/fixtures/demo-seed.json and restored on a schedule by
 * `bin/console app:reset-demo-data`. See private/demo-mode-plan.md (not
 * tracked in git) for the full design.
 */
export const DEMO_MODE_ENABLED = import.meta.env.VITE_DEMO_MODE === 'true';

export const DEMO_EMAIL = 'demo-employee@example.com';
export const DEMO_PASSWORD = 'e1o1-demo-2026';
