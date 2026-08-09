<script lang="ts">
  import { _, locale } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import { generateKeyPair, packWrappedPrivateKey, wrapPrivateKey } from '../crypto/keypair';
  import { toBase64 } from '../crypto/encoding';
  import { storeMasterKey } from '../crypto/session';
  import { markAuthenticated } from '../auth.svelte';
  import { navigate } from '../router.svelte';

  const { token }: { token: string } = $props();

  const MIN_PASSWORD_LENGTH = 12;

  let email = $state<string | null>(null);
  let lookupError = $state<string | null>(null);
  let password = $state('');
  let confirmPassword = $state('');
  let submitting = $state(false);
  let submitError = $state<string | null>(null);
  let done = $state(false);

  $effect(() => {
    apiGet<{ email: string }>(`/api/activation-tokens/${token}`)
      .then((result) => {
        email = result.email;
      })
      .catch((error: unknown) => {
        lookupError = error instanceof ApiError ? error.message : $_('activate.lookupError');
      });
  });

  const passwordTooShort = $derived(password.length > 0 && password.length < MIN_PASSWORD_LENGTH);
  const passwordsMismatch = $derived(confirmPassword.length > 0 && password !== confirmPassword);
  const canSubmit = $derived(
    email !== null &&
      password.length >= MIN_PASSWORD_LENGTH &&
      password === confirmPassword &&
      !submitting,
  );

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit || email === null) return;

    submitting = true;
    submitError = null;
    try {
      const salt = await deriveArgon2idSalt(email);
      const { authKey, masterKey } = await deriveKeysFromPassword(password, salt);
      const { publicKey, privateKey } = await generateKeyPair();
      const wrapped = await wrapPrivateKey(privateKey, masterKey);

      await apiPost(`/api/activation-tokens/${token}/complete`, {
        authKey: await toBase64(authKey),
        publicKey: await toBase64(publicKey),
        encryptedPrivateKey: await packWrappedPrivateKey(wrapped),
        // The UI language active right now (Phase 6h) — so this account starts with a
        // sensible email language (Phase 6i) instead of always English.
        locale: $locale,
      });

      await storeMasterKey(masterKey);
      done = true;
      markAuthenticated();
      navigate('/');
    } catch (error) {
      submitError = error instanceof ApiError ? error.message : $_('activate.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <h1>{$_('activate.title')}</h1>

  {#if done}
    <p>{$_('activate.done')}</p>
  {:else if lookupError}
    <p class="error">{lookupError}</p>
  {:else if email === null}
    <p>{$_('common.loading')}</p>
  {:else}
    <form onsubmit={handleSubmit}>
      <p>{$_('activate.emailLabel')} <strong>{email}</strong></p>

      <label>
        {$_('activate.passwordLabel')}
        <input type="password" bind:value={password} autocomplete="new-password" />
      </label>
      {#if passwordTooShort}
        <p class="hint">{$_('activate.passwordHint', { values: { min: MIN_PASSWORD_LENGTH } })}</p>
      {/if}

      <label>
        {$_('activate.confirmPasswordLabel')}
        <input type="password" bind:value={confirmPassword} autocomplete="new-password" />
      </label>
      {#if passwordsMismatch}
        <p class="hint">{$_('activate.passwordMismatch')}</p>
      {/if}

      {#if submitError}
        <p class="error">{submitError}</p>
      {/if}

      <button type="submit" disabled={!canSubmit}>
        {submitting ? $_('activate.submitting') : $_('activate.submit')}
      </button>
    </form>
  {/if}
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

  .hint {
    margin: -0.5rem 0 0;
    font-size: 0.875rem;
    color: #6b6b6b;
  }

  .error {
    color: #c0392b;
  }
</style>
