import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'

// Used only by the isolated e2e stack (docker-compose.e2e.yml, playwright.config.ts) —
// same as vite.config.ts but proxies to the e2e backend's own port (8001, not dev's
// 8000) and serves on its own port (5174, not dev's 5173), so both stacks can run side
// by side without colliding.
export default defineConfig({
  plugins: [svelte()],
  server: {
    port: 5174,
    strictPort: true,
    proxy: {
      '/health': 'http://localhost:8001',
      '/api': 'http://localhost:8001',
    },
  },
})
