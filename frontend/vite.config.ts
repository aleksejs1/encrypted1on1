import { defineConfig } from 'vite'
import { svelte } from '@sveltejs/vite-plugin-svelte'

// https://vite.dev/config/
export default defineConfig({
  plugins: [svelte()],
  server: {
    proxy: {
      // Proxied to the backend container instead of configuring CORS —
      // simplest option for a dev-only concern (see plan Phase 1).
      '/health': 'http://localhost:8000',
      '/api': 'http://localhost:8000',
    },
  },
})
