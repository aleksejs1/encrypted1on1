# Design the state machine up front for concurrent/async features

## Problem

A user reported that opening a second browser tab left the app broken with
no explanation: the header lost the username, and creating an anketa showed
a misleading "Could not load users" error. Root cause: the E2EE master key
lives in tab-scoped `sessionStorage` (deliberate — see `docs/user-flow.md`),
while the session cookie is shared across tabs, so a second tab is
authenticated but not "unlocked." Nothing reconciled the two states, and the
two places that assumed unlock had already succeeded (`AppHeader.svelte`,
`CreateAnketa.svelte`) failed silently or with a mislabeled error instead of
surfacing anything actionable.

The fix (`App.svelte` routes to a new `UnlockTab.svelte` password-re-entry
screen whenever authenticated-but-locked) took 11 rounds of the
`code-review` skill loop before landing clean. Most rounds found real, if
narrow, bugs — but the pattern in *how* they arrived is the actual lesson,
not the bug count itself:

- The first implementation modeled "is this tab unlocked" as two
  independent booleans (`unlockChecked`, `unlocked`), mutated at several
  different call sites (`checkUnlocked()`'s several branches,
  `UnlockTab.svelte`'s own fetch, `logOut()`) that each had to remember to
  reset both together. Early rounds found a real race here (a same-tab
  relogin after a session-death 401 got stuck showing the unlock screen
  forever, since the login only flipped one of the two flags) — and the fix
  for it landed round after round in a *different* call site, because each
  patch fixed the specific spot review had just shown broken rather than
  the general shape of the problem. The identical symptom recurred three
  separate times before the two booleans were finally collapsed into one
  enum.
- Separately, a round-5 fix (an async staleness guard so a slow in-flight
  request can't resurrect a stale decrypted identity after logout) was
  applied by making a shared cache-clearing function always bump the guard
  — without checking who else already called that function. Two rounds
  later, review found this had broken an unrelated, pre-existing
  "cache-bust after saving settings" call in the admin panel, which was
  never a logout and shouldn't have been treated like one.

## Decision

Two concrete practices for any future concurrent/async or multi-step-state
feature (not just multi-tab auth):

1. **Model the state as a single enum/union over the actual reachable
   states, not independent booleans.** Two booleans standing in for a
   3-state value ("haven't checked yet" / "checked, no" / "checked, yes")
   let the type represent combinations nothing ever produces — and it was
   exactly that unstated invariant that broke, repeatedly, in the early
   rounds here. `authState.unlockStatus: 'unknown' | 'locked' | 'unlocked'`
   (`frontend/src/auth.svelte.ts`) replaced `unlockChecked`/`unlocked` and
   closed a whole class of "forgot to reset the other flag" bugs in one
   change, instead of one more per review round.
2. **Before broadening what a shared function does — especially an
   invalidation/cleanup function — grep every existing call site and
   re-derive whether the broadened behavior is still correct for each one,
   not just the call site that prompted the change.** The admin-panel
   regression above happened because a routine cache-bust and a genuine
   "this session is dead" both went through one `clearIdentity()`, until the
   fix split it into a plain `clearIdentity()` (cache only) and
   `invalidateIdentity()` (also bumps the staleness-guard generation
   counter) — the second reserved for actual session death
   (`frontend/src/crypto/identity.svelte.ts`).

In short: for this class of change, think through the full state space and
every existing caller *before* writing the fix, rather than reacting one
review round at a time to whatever the next pass happens to surface. The
round-10 redesign (collapsing the two booleans, consolidating every
invalidation path through one `markSessionExpired()`) fixed several
previously-separate findings at once, which is what actually cut the loop
short — patch-per-finding is what stretched it to 11 rounds in the first
place.

## Verification

Covered by `frontend/e2e/multi-tab-unlock.spec.ts` (real crypto, real
backend, two genuinely independent tabs in the same browser context —
opening a second tab, wrong-password, and successful unlock), plus the
final `code-review` pass returning no further confirmed correctness bugs on
`frontend/src/auth.svelte.ts`, `frontend/src/crypto/identity.svelte.ts`,
`frontend/src/App.svelte`, `frontend/src/design/AppHeader.svelte`, and
`frontend/src/pages/UnlockTab.svelte`.
