import { defineConfig } from '@playwright/test';

// Needs the genuinely isolated e2e stack already running (`make e2e-up`, or just
// `make e2e` which does both, locally; the `e2e` CI job in .github/workflows/ci.yml
// does the CI equivalent), since it drives real account creation/anketa flows
// against real HTTP, not mocks. Its own backend container, own SQLite file, own
// Compose project (docker-compose.e2e.yml) — never shares state with dev or with the
// PHPUnit test stack.
export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  retries: 0,
  workers: 1,
  // The default 'list' reporter is fine for a human watching a local run, but leaves
  // nothing to inspect after the fact — CI gets an HTML report (uploaded as an
  // artifact by the workflow on failure) plus the 'github' reporter's inline PR
  // annotations; kept off locally so a local run doesn't leave report files behind
  // if nobody's going to click into them (the trailing terminal output is enough).
  reporter: process.env.CI ? [['html', { open: 'never' }], ['github']] : 'list',
  use: {
    baseURL: 'http://localhost:5174',
    // Only written for a test that actually failed, locally and in CI alike — a red
    // CI run has nothing else to inspect beyond the terminal output (the only
    // diagnostic a CI-only failure that doesn't reproduce locally leaves behind), and
    // it's just as useful for a local failure a developer wants to step through.
    trace: 'retain-on-failure',
  },
  webServer: {
    command: 'npm run dev:e2e',
    url: 'http://localhost:5174',
    // Not unconditionally true: reusing a server already listening on :5174 is a
    // convenience for a developer who left `npm run dev:e2e` running locally, but in
    // CI a leftover process from a cancelled prior run (or a retried job that didn't
    // fully reap it) would otherwise be silently reused instead of failing loudly —
    // Playwright's own documented recommendation for this exact setting.
    reuseExistingServer: !process.env.CI,
  },
});
