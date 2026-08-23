import { readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

// Single source of truth for the version shown in the footer (see AppFooter.svelte) —
// read from package.json rather than duplicated into an env var, so a version bump
// there can't drift out of sync with what's displayed.
const { version } = JSON.parse(
  readFileSync(new URL('./package.json', import.meta.url), 'utf-8'),
);

// https://vite.dev/config/
export default defineConfig({
  plugins: [svelte()],
  define: {
    __APP_VERSION__: JSON.stringify(version),
  },
  server: {
    proxy: {
      // Proxied to the backend container instead of configuring CORS —
      // simplest option for a dev-only concern (see plan Phase 1).
      '/health': 'http://localhost:8000',
      '/api': 'http://localhost:8000',
    },
  },
});
