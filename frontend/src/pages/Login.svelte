<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiPost, ApiError } from '../api/client';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import { unpackWrappedPrivateKey, unwrapPrivateKey } from '../crypto/keypair';
  import { toBase64 } from '../crypto/encoding';
  import { storeMasterKey } from '../crypto/session';
  import { markAuthenticated } from '../auth.svelte';

  let email = $state('');
  let password = $state('');
  let submitting = $state(false);
  let error = $state<string | null>(null);

  const canSubmit = $derived(email.length > 0 && password.length > 0 && !submitting);

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit) return;

    submitting = true;
    error = null;
    try {
      const salt = await deriveArgon2idSalt(email);
      const { authKey, masterKey } = await deriveKeysFromPassword(password, salt);

      const response = await apiPost<{ publicKey: string; encryptedPrivateKey: string }>(
        '/api/login',
        { email, authKey: await toBase64(authKey) },
      );

      // Unwrapping is also a correctness check: a wrong master-key throws (see keypair.ts).
      const wrapped = await unpackWrappedPrivateKey(response.encryptedPrivateKey);
      await unwrapPrivateKey(wrapped, masterKey);

      await storeMasterKey(masterKey);
      markAuthenticated();
    } catch (err) {
      error = err instanceof ApiError ? err.message : $_('login.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="card elev-md">
    <h1>{$_('login.title')}</h1>
    <p class="text-muted subtitle">{$_('login.subtitle')}</p>

    <form onsubmit={handleSubmit}>
      <div class="field">
        <label for="login-email">{$_('login.emailLabel')}</label>
        <input
          id="login-email"
          class="input"
          type="email"
          bind:value={email}
          autocomplete="username"
          required
        />
      </div>

      <div class="field">
        <label for="login-password">{$_('login.passwordLabel')}</label>
        <input
          id="login-password"
          class="input"
          type="password"
          bind:value={password}
          autocomplete="current-password"
          required
        />
      </div>

      {#if error}
        <div role="alert" class="error-banner">{error}</div>
      {/if}

      <button type="submit" class="btn btn-primary btn-block" disabled={!canSubmit}>
        {submitting ? $_('login.submitting') : $_('login.submit')}
      </button>

      {#if submitting}
        <p class="text-muted crypto-note">
          <span aria-hidden="true">⏳</span> {$_('login.cryptoNote')}
        </p>
      {/if}
    </form>

    <div class="hr"></div>
    <p class="text-muted session-note">{$_('login.sessionNote')}</p>
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

  .error-banner {
    font-size: 13px;
    color: var(--color-accent-ink);
    background: color-mix(in srgb, var(--color-accent) 14%, transparent);
    border-radius: var(--radius-sm);
    padding: 8px 12px;
  }

  .crypto-note {
    font-size: 12px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .session-note {
    font-size: 12px;
    margin: 0;
  }
</style>
