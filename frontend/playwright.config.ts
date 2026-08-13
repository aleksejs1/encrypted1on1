import { defineConfig } from '@playwright/test';

// Local-only e2e suite (see CLAUDE.md) — needs the genuinely isolated e2e stack
// already running (`make e2e-up`, or just `make e2e` which does both), since it
// drives real account creation/anketa flows against real HTTP, not mocks. Its own
// backend container, own SQLite file, own Compose project (docker-compose.e2e.yml)
// — never shares state with dev or with the PHPUnit test stack. Not wired into CI:
// CI's jobs run natively with no live backend, and standing up a docker-compose
// stack inside a CI job is a separate, not-yet-built concern.
export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  retries: 0,
  workers: 1,
  use: {
    baseURL: 'http://localhost:5174',
  },
  webServer: {
    command: 'npm run dev:e2e',
    url: 'http://localhost:5174',
    reuseExistingServer: true,
  },
});
