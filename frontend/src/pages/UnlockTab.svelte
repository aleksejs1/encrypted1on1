<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet } from '../api/client';
  import type { MeResponse } from '../api/types';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import { storeMasterKey } from '../crypto/session';
  import {
    checkUnlocked,
    isSessionExpiredError,
    markSessionExpired,
  } from '../auth.svelte';

  // Auth (the cookie) is already confirmed by the time App.svelte renders
  // this page — /api/me only needs the email it already returns (to derive
  // the salt), not a fresh login round-trip.
  let me = $state<MeResponse | null>(null);
  let meLoadFailed = $state(false);
  let password = $state('');
  let submitting = $state(false);
  let error = $state<string | null>(null);

  async function loadMe(): Promise<void> {
    meLoadFailed = false;
    try {
      me = await apiGet<MeResponse>('/api/me');
    } catch (err) {
      if (isSessionExpiredError(err)) {
        // The server session died (expired/revoked) before this tab even
        // got to ask for a password — same as checkUnlocked()'s own 401
        // handling (shares markSessionExpired() with it), route back to
        // Login instead of showing a "could not load" banner whose only
        // retry is this same fetch, which would just 401 again forever.
        markSessionExpired();
        return;
      }
      meLoadFailed = true;
    }
  }

  $effect(() => {
    // loadMe() catches every error itself today (see above) — .catch() here isn't
    // for that, it's so a future change that makes loadMe() reject doesn't become a
    // silent unhandled rejection just because this call site used to be provably safe.
    loadMe().catch((error: unknown) => {
      console.error(error);
    });
  });

  const canSubmit = $derived(password.length > 0 && me !== null && !submitting);

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit || me === null) return;

    submitting = true;
    error = null;
    try {
      const salt = await deriveArgon2idSalt(me.email);
      const { masterKey } = await deriveKeysFromPassword(password, salt);
      await storeMasterKey(masterKey);

      // The real verification: checkUnlocked() (auth.svelte.ts) calls
      // ensureUnlocked(), which re-fetches /api/me and unwraps the private
      // key with the master key just stored — an AEAD decrypt that throws
      // on a wrong password (see crypto/keypair.ts) rather than returning
      // garbage. Deliberately does NOT reuse the `me` fetched at mount
      // (unlike checkAuth()'s own call to checkUnlocked()): the user may
      // have taken any amount of time typing a password, and this fresh
      // fetch is what catches the session having died, or the password
      // having changed elsewhere, in that gap — passing a stale `me` would
      // silently skip exactly that check. Reusing checkUnlocked() here
      // (instead of duplicating this check) also means its returned outcome
      // tells a genuinely wrong password apart from a network/server
      // failure, and a session that died in the meantime is handled the
      // same way it is everywhere else (App.svelte routes to Login rather
      // than this page claiming a wrong password). checkUnlocked() itself
      // clears the master key it just proved wrong — nothing left to do
      // here beyond showing the message.
      const outcome = await checkUnlocked();
      if (outcome === 'wrong-password' || outcome === 'error') {
        error = $_(
          outcome === 'wrong-password'
            ? 'unlockTab.wrongPassword'
            : 'unlockTab.genericError',
        );
      }
    } catch {
      error = $_('unlockTab.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="card elev-md">
    <h1>{$_('unlockTab.title')}</h1>
    <p class="text-muted subtitle">{$_('unlockTab.subtitle')}</p>

    {#if meLoadFailed}
      <div role="alert" class="banner-error">{$_('unlockTab.loadError')}</div>
      <button
        type="button"
        class="btn btn-secondary btn-block"
        onclick={loadMe}
      >
        {$_('unlockTab.retry')}
      </button>
    {:else}
      <form onsubmit={handleSubmit}>
        <div class="field">
          <label for="unlock-password">{$_('login.passwordLabel')}</label>
          <input
            id="unlock-password"
            class="input"
            type="password"
            bind:value={password}
            autocomplete="current-password"
            required
          />
        </div>

        {#if error}
          <div role="alert" class="banner-error">{error}</div>
        {/if}

        <button
          type="submit"
          class="btn btn-primary btn-block"
          disabled={!canSubmit}
        >
          {submitting ? $_('login.submitting') : $_('unlockTab.submit')}
        </button>

        {#if submitting}
          <p class="text-muted crypto-note">
            <span aria-hidden="true">⏳</span>
            {$_('login.cryptoNote')}
          </p>
        {/if}
      </form>
    {/if}
  </div>
</main>

<style>
  main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }

  .card {
    width: min(400px, 100%);
    padding: 28px;
  }

  h1 {
    font-size: 26px;
    margin: 0 0 4px;
  }

  .subtitle {
    font-size: 13px;
    margin: 0 0 20px;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .crypto-note {
    font-size: 12px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
  }
</style>
