import { configDefaults, defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    environment: 'node',
    // The Playwright e2e suite (frontend/e2e/) lives outside Vitest's world
    // entirely — its own *.spec.ts files, run via `npm run test:e2e`, not
    // this default include pattern.
    exclude: [...configDefaults.exclude, 'e2e/**'],
    coverage: {
      provider: 'v8',
      // `all: true` so untested files count in the denominator, not just
      // whatever a test happens to import. Scoped to .ts only — Vitest's v8
      // remapper cannot parse .svelte files' compiled output for files with
      // no test touching them (RolldownError: "Unexpected JSX expression"
      // on every .svelte file), so they're structurally impossible to
      // include here today. That's consistent with this project's own
      // stance (see docs/architecture.md): no component-rendering tests,
      // .svelte correctness verified by code review + manual passes, not
      // automated coverage.
      all: true,
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
