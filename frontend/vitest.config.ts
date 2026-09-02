import { svelte } from '@sveltejs/vite-plugin-svelte';
import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig({
  // Needed so importing a *.svelte.ts rune module (auth.svelte.ts,
  // identity.svelte.ts, displayName.svelte.ts) doesn't blow up on a bare
  // `$state(...)` call — Svelte 5 runes are a compile-time transform, not a
  // runtime global. Svelte's compiled reactivity has no DOM dependency, so
  // this works fine under the 'node' environment below; no component
  // rendering is involved.
  plugins: [svelte()],
  test: {
    environment: 'node',
    // The Playwright e2e suite (frontend/e2e/) lives outside Vitest's world
    // entirely — its own *.spec.ts files, run via `npm run test:e2e`, not
    // this default include pattern.
    exclude: [...configDefaults.exclude, 'e2e/**'],
    coverage: {
      provider: 'v8',
      // Untested files count in the denominator whenever `include` is set —
      // Vitest 4 made that the unconditional default (the old `all: true`
      // toggle was removed, not just renamed; TypeScript now rejects it as
      // an unknown option). Scoped to .ts only — Vitest's v8 remapper cannot
      // parse .svelte files' compiled output for files with no test
      // touching them (RolldownError: "Unexpected JSX expression" on every
      // .svelte file), so they're structurally impossible to include here
      // today. That's consistent with this project's own stance (see
      // docs/architecture.md): no component-rendering tests, .svelte
      // correctness verified by code review + manual passes, not automated
      // coverage.
      include: ['src/**/*.ts'],
      exclude: ['src/**/*.test.ts'],
      // A small buffer below the real measured baseline (51.94/46.05/54.23/52.35)
      // — catches a real regression, not day-to-day noise.
      thresholds: {
        statements: 48,
        branches: 42,
        functions: 50,
        lines: 48,
      },
    },
  },
});
