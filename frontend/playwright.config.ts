import { defineConfig } from '@playwright/test';

// Local-only e2e suite (see CLAUDE.md) — needs the real dev stack (backend +
// Mailpit) already running (`make up`), since it drives real account
// creation/anketa flows against real HTTP, not mocks. Not wired into CI:
// CI's jobs run natively with no live backend, and standing up the full
// docker-compose stack inside a CI job is a separate, not-yet-built concern
// (private/todo.md P4 item 22).
export default defineConfig({
  testDir: './e2e',
  timeout: 60_000,
  retries: 0,
  workers: 1,
  use: {
    baseURL: 'http://localhost:5173',
  },
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: true,
  },
});
