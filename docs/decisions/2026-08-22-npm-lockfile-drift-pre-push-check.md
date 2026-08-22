# Catch npm lockfile drift in the pre-push hook, not just in CI

## Problem

CI failed on push (`frontend` job, `npm ci` step): `frontend/package-lock.json`
was missing ~28 entries (`@emnapi/core`, `@rollup/*` and `@napi-rs/*` optional
platform bindings, etc.) that `frontend/package.json` required. `npm ci`
refuses to run against a lockfile that doesn't match the manifest — that's
the whole point of `npm ci` over `npm install` — so CI failed outright with
`npm error code EUSAGE`.

Root cause: the lockfile was regenerated via two separate incremental
`npm install --save-dev <pkg>` calls (adding `knip`, then `playwright`) on
top of an already-installed `node_modules`, rather than one clean install.
`npm install` never refuses to run against a stale/incomplete lockfile — it
just silently rewrites whatever's missing, and in this case didn't fully
reconcile every optional-platform entry. The result passed a local
`npm install` + `npm run check`/`test`/`build` without any error, and only
`npm ci` — run for the first time on a real checkout, i.e. in CI — actually
caught it.

The existing `.githooks/pre-push` hook (added earlier the same day) already
runs `npm run check` when `frontend/` changes, but that's a typecheck, not a
lockfile-sync check — it would never have caught this class of bug.

## Decision

Extend `.githooks/pre-push` to run `npm ci` (root and `frontend/`
independently) whenever the corresponding `package.json`/`package-lock.json`
changed in the diff being pushed, and `composer validate --no-check-all`
(cheap — no install needed) whenever `backend/composer.json`/`composer.lock`
changed. Scoped to only fire when the relevant manifest actually changed,
matching the hook's existing "scoped to what changed" shape — this doesn't
run on every push, only ones that touch a manifest/lockfile.

`npm ci` was chosen deliberately over a lighter "diff the lockfile before
and after `npm install --package-lock-only`" check: it's the literal command
CI runs, so it proves the exact thing that matters (would CI's checkout
succeed) rather than a proxy for it, and it happens to also leave
`node_modules` freshly verified as a side effect.

## Alternatives considered

- **A CI-only fix (just regenerate the lockfile, don't touch the hook).**
  Fixes this one instance but leaves the same failure mode fully able to
  recur on the next incremental `npm install`. Rejected — the whole point of
  the git-hooks work earlier this session was catching exactly this class of
  "passes locally, fails in CI" gap before it reaches CI at all.
- **`npm install --package-lock-only` and diff against HEAD.** More
  "surgical" (doesn't touch `node_modules`), but more code to get right and
  doesn't prove `npm ci` itself succeeds — a lockfile could match its own
  manifest's *direct* dependency versions while still resolving optional/
  platform entries differently than a real `npm ci` would. Rejected in favor
  of just running the real command.

## Verification

Reproduced the actual CI failure locally first (`rm -rf node_modules
frontend/package-lock.json && npm ci` in `frontend/` failed as expected, same
"Missing: @emnapi/core..." error CI showed), confirmed a clean `rm -rf
node_modules package-lock.json && npm install` regenerates a lockfile that
`npm ci` then accepts cleanly (checked package count: 273 → 301 entries in
`frontend/package-lock.json`), then re-ran the full frontend suite (`npm run
check`/`test`/`knip`/`format`/`build`) against the freshly-installed tree —
all clean. Confirmed the updated `.githooks/pre-push`'s new checks fire
correctly on this exact diff (`frontend/package-lock.json` changed → `npm ci`
runs) before pushing the fix.
