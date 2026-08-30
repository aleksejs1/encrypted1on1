// Scoped on purpose: this isn't a general "lint everything" adoption — it's specifically
// aimed at the one failure class types alone can't catch: an unhandled/floating promise,
// which can leave stale async work in flight after a state change (e.g. logout) — see
// docs/decisions/2026-08-28-multi-tab-unlock-state-machine.md for the shape of bug that
// class covers (not this exact rule set — that fix was a stale-resolution race on an
// already-awaited call, not a missing await/catch, but the same "an in-flight async
// operation outlives the state it assumed was still current" risk).
//
// Type-aware promise rules run on both plain *.ts files (src/**, e2e/**, root config
// files, and *.svelte.ts rune files — all ordinary TypeScript syntactically) and on
// *.svelte files themselves, via svelte-eslint-parser's TypeScript integration
// (parserOptions.projectService + extraFileExtensions). Verified directly: an earlier,
// more conservative draft of this config scoped type-aware rules to *.ts files only,
// on the assumption that type-aware parsing of Svelte 5 rune syntax was unreliable —
// checked against the actually-installed tooling versions here (eslint-plugin-svelte
// 3.x, svelte-eslint-parser 1.x) instead of taking that assumption on faith, and it
// works cleanly, catching real unhandled promises inside $effect blocks that the
// *.ts-only scope would have missed entirely.
//
// Known, accepted blind spot: scripts/**/*.mjs (the hand-run maintenance scripts —
// generate-demo-fixture.mjs and friends, some of which do real async I/O) match none
// of the globs below and aren't linted at all. Tried extending coverage to them
// (adding checkJs/allowJs to tsconfig.node.json): it surfaced ~150 pre-existing
// "implicit any" errors across these never-type-checked plain-JS files, since
// no-floating-promises fundamentally requires real type information and can't run
// without it. Fixing that is a real, separate type-annotation project, not a
// lint-config change — out of scope here.
import svelte from 'eslint-plugin-svelte';
import tseslint from 'typescript-eslint';

const promiseSafetyRules = {
  '@typescript-eslint/no-floating-promises': 'error',
  '@typescript-eslint/no-misused-promises': 'error',
  '@typescript-eslint/await-thenable': 'error',
};

// Shared verbatim between the *.ts and *.svelte blocks below (beyond `parser`, which
// differs) — typescript-eslint's `projectService` is one unconditional, unkeyed
// module-level singleton (createProjectService() called once, cached forever), not
// scoped per file-type or per options object at all. Whichever config block ESLint
// parses first "wins": its options build the singleton, and every later block's
// projectService options are silently ignored, not merged or rebuilt. Keeping both
// blocks' options identical isn't just a performance optimization, then — it's the
// only way to avoid the actual options used depending on file-processing order.
const projectServiceOptions = {
  projectService: true,
  extraFileExtensions: ['.svelte'],
  tsconfigRootDir: import.meta.dirname,
};

export default tseslint.config(
  {
    // Mirrors frontend/.gitignore's build-artifact entries.
    ignores: [
      'dist/**',
      'dist-ssr/**',
      'coverage/**',
      'playwright-report/**',
      'test-results/**',
      'blob-report/**',
    ],
  },
  ...svelte.configs['flat/recommended'],
  ...svelte.configs['flat/prettier'],
  {
    files: ['src/**/*.ts', 'e2e/**/*.ts', '*.ts'],
    plugins: {
      '@typescript-eslint': tseslint.plugin,
    },
    languageOptions: {
      parser: tseslint.parser,
      parserOptions: projectServiceOptions,
    },
    rules: promiseSafetyRules,
  },
  {
    files: ['**/*.svelte'],
    plugins: {
      '@typescript-eslint': tseslint.plugin,
    },
    languageOptions: {
      parser: svelte.parser,
      parserOptions: {
        parser: tseslint.parser,
        ...projectServiceOptions,
      },
    },
    rules: promiseSafetyRules,
  },
);
