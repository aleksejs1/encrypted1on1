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
  <h1>{$_('login.title')}</h1>

  <form onsubmit={handleSubmit}>
      <label>
        {$_('login.emailLabel')}
        <input type="email" bind:value={email} autocomplete="username" />
      </label>

      <label>
        {$_('login.passwordLabel')}
        <input type="password" bind:value={password} autocomplete="current-password" />
      </label>

      {#if error}
        <p class="error">{error}</p>
      {/if}

      <button type="submit" disabled={!canSubmit}>
        {submitting ? $_('login.submitting') : $_('login.submit')}
      </button>
  </form>
</main>

<style>
  main {
    max-width: 24rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  .error {
    color: #c0392b;
  }
</style>
