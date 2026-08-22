---
name: e1o1-verify-with-real-crypto
description: How to verify an encrypted1on1 change end-to-end against the real dev/e2e stack with genuine crypto (not opaque placeholder strings) — which layer to test at (PHPUnit ext-sodium vs. a throwaway Node script vs. Playwright), how to hand-roll a cookie+CSRF jar for a Node script, and the mandatory cleanup checklist. Use this before claiming a backend+frontend crypto-touching change is "verified" — this is the repo's own established verification standard, not a new one.
---

# Verifying with real crypto

Nearly every non-trivial change in this repo's history (`docs/history.md`) was
verified the same way: not just unit tests passing, but a real round trip
through genuine cryptography — real X25519 keypairs, real `crypto_box_seal`,
real AEAD-encrypted blobs — against the actual running stack, then cleaned
up. This skill exists because that pattern gets re-invented in prose on
every large change instead of being followed as a checklist. Reach for it
whenever a task touches anketa keys, sealing/unsealing, password-derived
master keys, or any flow whose entire point is that the server can't read
the content — a change like that isn't "verified" on unit tests alone.

## Step 1: pick the right layer — don't reach for a browser by default

Three real layers exist in this codebase, at increasing cost. Use the
cheapest one that actually proves the claim:

1. **PHP `ext-sodium` inside a plain PHPUnit test** — sufficient when the
   claim is purely about server-side content confidentiality: does the
   database genuinely contain ciphertext, not plaintext; does the wrong key
   fail closed. See `backend/tests/Functional/PrivacyBlackBoxTest.php` —
   `ext-sodium` is confirmed installed in the dev image and functionally
   equivalent to the frontend's model (`crypto_box_keypair`/`_seal`/
   `_seal_open`, `crypto_aead_xchacha20poly1305_ietf_encrypt`/`_decrypt`).
   No Node, no browser, runs in the normal test suite. **Not** sufficient
   for anything about the actual password→master-key derivation chain
   (argon2id/HKDF) — that's a different claim, covered by
   `frontend/src/crypto/password.test.ts`, not this layer.
2. **A throwaway Node script with `libsodium-wrappers-sumo`** (the `-sumo`
   build specifically — the plain package doesn't expose `crypto_pwhash`/
   argon2id) — the default choice for "does a real multi-step HTTP flow
   work correctly with real keys" (activation, publish/reveal, password
   reset, reshare, deletion, company creation, etc.). Drives the real
   backend over real HTTP with `fetch`, generates real keypairs/seals/AEAD
   blobs client-side exactly like the frontend does, but skips rendering
   any UI. This is what most "verified end-to-end" claims in
   `docs/history.md` are.
3. **Playwright, a real browser** — only when the claim genuinely requires
   it: the actual `libsodium-wrappers-sumo` WASM module executing inside a
   real browser tab (not Node's WASM runtime — different engine, matters
   for the "does this even load under CSP" class of question), a real UI
   interaction (typing, clicking, a form's own validation), or two
   genuinely independent browser sessions/contexts at once (dual-actor
   flows). `frontend/e2e/` is the committed, permanent version of this;
   most single-use verification scripts don't need to go this far.

Don't default to the most expensive layer "to be safe" — a Node script that
never touches a browser is faster to write, faster to run, and already
proves everything except WASM-in-a-real-browser or UI interaction.

## Step 2: writing a throwaway Node script (layer 2)

- `npm install libsodium-wrappers-sumo` in the scratchpad directory (never
  the repo's own `frontend/`), or reuse it from `frontend/node_modules` if
  already installed there.
- `await sodium.ready` before calling anything.
- Hand-roll a cookie + CSRF jar — Node's `fetch` does **not** manage cookies
  across requests the way a browser does, so a naive script that reuses one
  `fetch` call's `Set-Cookie` without threading it into the next request's
  `Cookie` header will silently talk to no session at all:

  ```js
  let cookie = '';
  let csrfToken = null;

  async function api(path, opts = {}) {
    if (!csrfToken && opts.method && opts.method !== 'GET') {
      csrfToken = await api('/api/csrf-token').then((r) => r.token);
    }
    const res = await fetch(`http://localhost:8000${path}`, {
      ...opts,
      headers: {
        'Content-Type': 'application/json',
        ...(cookie ? { Cookie: cookie } : {}),
        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
        ...opts.headers,
      },
    });
    const setCookie = res.headers.get('set-cookie');
    if (setCookie) cookie = setCookie.split(';')[0];
    return res.status === 204 ? null : res.json();
  }

  function resetAfterLogout() {
    csrfToken = null; // logout invalidates the session, wiping the CSRF secret with it
  }
  ```

  **The specific, twice-already-hit bug**: any request that invalidates the
  session server-side (`AuthSession::logOut()` — fired by `POST /api/login`
  after a prior session, `DELETE /api/me`, an explicit logout call) rotates
  the session and wipes the CSRF secret. If the script's cached `csrfToken`
  isn't cleared too, the *next* state-changing request gets a real,
  confusing `403 Invalid CSRF token` that has nothing to do with the change
  under test. Call `resetAfterLogout()` (or equivalent) right after any such
  request, mirroring `frontend/src/api/client.ts`'s own `resetCsrfToken()` —
  this exact bug reaching real frontend users is what motivated adding that
  export in the first place (see `docs/history.md`, "stale CSRF token after
  logout").
- Talk to the real dev stack (`http://localhost:8000`, `make up` first) or
  the isolated e2e stack (`http://localhost:8001`, `make e2e-up` first) —
  never invent a mock.
- Read real emails from Mailpit's REST API (`http://localhost:8025/api/v1/...`)
  when a flow sends one, rather than assuming the send succeeded.

## Step 3: mandatory cleanup — every time, not just when convenient

A verification script writes real rows into a real database (the dev
stack's, almost always — `var/data.db`, shared with actual manual testing
history). Leaving throwaway accounts/companies/anketas behind pollutes that
history and has caused real bugs before (e.g. a counterpart-typeahead
pagination bug only surfaced once the dev DB had accumulated 30+ accounts).
Before finishing:

- Delete every throwaway account/company/anketa the script created, via the
  real API or a direct DB query if the API can't (e.g. `bin/console
  dbal:run-sql`).
- If `backend/.env` was temporarily edited for the verification (most
  commonly `CLOUD_MODE=1` or `REGISTRATION_MODE=domain`/`ALLOWED_EMAIL_DOMAIN`
  — several past changes needed a real container rebuild under an alternate
  mode, since PHPUnit can't cheaply do that itself), revert it and
  `docker compose -f docker-compose.dev.yml up -d --force-recreate backend`,
  then re-confirm the reverted state for real (e.g. `GET
  /api/registration-info` reports the original mode) — don't just trust the
  `git checkout`. **Check `git status` on `backend/.env` before starting any
  new verification work, too** — a prior session's forgotten revert here has
  already caused real, confusing backend test failures unrelated to
  whatever was actually being worked on.
- Confirm the dev stack's real user/account counts are unchanged from before
  the script ran (a plain count query, not just "the delete calls didn't
  error") — this is the actual proof cleanup worked, and is exactly the
  check `docs/history.md` describes doing after nearly every such script.
- The script itself: keep it in the scratchpad directory and don't commit
  it, unless the verification is worth keeping permanently — in which case
  promote it properly instead (a real Vitest/PHPUnit test, or a permanent
  `frontend/e2e/*.spec.ts`, or — for a genuinely reusable generator like
  the demo-mode fixture — a committed `frontend/scripts/*.mjs`, added to
  `frontend/knip.json`'s `entry` list so it doesn't get flagged as dead code).
