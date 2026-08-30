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

## Addendum (2026-08-30): the same failure can recur across npm versions, not just incremental installs

CI failed again with the identical signature (`Missing: @emnapi/core@1.11.3
from lock file`) despite `.githooks/pre-push`'s `npm ci` check passing
locally first. Root cause this time: the local machine's npm (11.6.2, bundled
with Node 24) and CI's npm (10.9.8, bundled with `actions/setup-node`'s
Node 22) resolved/hoisted an optional peer dependency differently, so a
lockfile that satisfies `npm ci` under one npm version didn't satisfy it
under the other — not incremental-install drift, a genuine cross-npm-version
resolution difference. Reproduced directly: `docker run node:22 npm ci`
against the committed lockfile failed with the exact CI error; `docker run
node:22 npm install` (regenerating under the same npm version CI uses) then
made `npm ci` succeed under both npm 10 and npm 11. Fixed by committing the
lockfile as regenerated under npm 10/Node 22 specifically, matching CI's
actual environment rather than whatever happens to be installed locally.
`.githooks/pre-push`'s `npm ci` check is still correct and worth keeping —
it just can't catch a mismatch between the local npm version and CI's,
which pinning Node/npm versions identically everywhere would close but
hasn't been done (no `engines` field in `frontend/package.json` today).

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
