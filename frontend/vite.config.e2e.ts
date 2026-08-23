import { readFileSync } from 'node:fs';
import { defineConfig } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

// Used only by the isolated e2e stack (docker-compose.e2e.yml, playwright.config.ts) —
// same as vite.config.ts but proxies to the e2e backend's own port (8001, not dev's
// 8000) and serves on its own port (5174, not dev's 5173), so both stacks can run side
// by side without colliding.

// Same __APP_VERSION__ define as vite.config.ts — AppFooter.svelte references it
// unconditionally, so it must be defined here too or the e2e dev server throws a
// ReferenceError at runtime.
const { version } = JSON.parse(
  readFileSync(new URL('./package.json', import.meta.url), 'utf-8'),
);

export default defineConfig({
  plugins: [svelte()],
  define: {
    __APP_VERSION__: JSON.stringify(version),
  },
  server: {
    port: 5174,
    strictPort: true,
    proxy: {
      '/health': 'http://localhost:8001',
      '/api': 'http://localhost:8001',
    },
  },
});
