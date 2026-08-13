<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import {
    generateKeyPair,
    packWrappedPrivateKey,
    wrapPrivateKey,
  } from '../crypto/keypair';
  import { toBase64 } from '../crypto/encoding';
  import { storeMasterKey } from '../crypto/session';
  import { markAuthenticated } from '../auth.svelte';
  import { navigate } from '../router.svelte';
  import {
    MIN_PASSWORD_LENGTH,
    STRENGTH_COLORS,
    STRENGTH_LABEL_KEYS,
    scoreOf,
  } from '../passwordStrength';

  const { token }: { token: string } = $props();

  let email = $state<string | null>(null);
  let lookupError = $state<string | null>(null);
  let password = $state('');
  let confirmPassword = $state('');
  let riskAcknowledged = $state(false);
  let submitting = $state(false);
  let submitError = $state<string | null>(null);

  $effect(() => {
    apiGet<{ email: string }>(`/api/password-reset-tokens/${token}`)
      .then((result) => {
        email = result.email;
      })
      .catch((error: unknown) => {
        lookupError =
          error instanceof ApiError
            ? error.message
            : $_('resetPassword.lookupError');
      });
  });

  const passwordScore = $derived(scoreOf(password));
  const passwordTooShort = $derived(
    password.length > 0 && password.length < MIN_PASSWORD_LENGTH,
  );
  const passwordsMismatch = $derived(
    confirmPassword.length > 0 && password !== confirmPassword,
  );
  const canSubmit = $derived(
    email !== null &&
      password.length >= MIN_PASSWORD_LENGTH &&
      password === confirmPassword &&
      riskAcknowledged &&
      !submitting,
  );

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit || email === null) return;

    submitting = true;
    submitError = null;
    try {
      const salt = await deriveArgon2idSalt(email);
      const { authKey, masterKey } = await deriveKeysFromPassword(
        password,
        salt,
      );
      // A fresh keypair, not a re-wrap of the old one — the old private key is
      // exactly what's inaccessible right now (it was wrapped with the master
      // key derived from the forgotten password). This is *why* every anketa
      // sealed under the old public key becomes unreadable until it's re-shared.
      const { publicKey, privateKey } = await generateKeyPair();
      const wrapped = await wrapPrivateKey(privateKey, masterKey);

      await apiPost(`/api/password-reset-tokens/${token}/complete`, {
        authKey: await toBase64(authKey),
        publicKey: await toBase64(publicKey),
        encryptedPrivateKey: await packWrappedPrivateKey(wrapped),
      });

      await storeMasterKey(masterKey);
      markAuthenticated();
      navigate('/');
    } catch (error) {
      submitError =
        error instanceof ApiError
          ? error.message
          : $_('resetPassword.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="card elev-md">
    <h1>{$_('resetPassword.title')}</h1>

    {#if lookupError}
      <p class="banner-error">{lookupError}</p>
    {:else if email === null}
      <p class="text-muted">{$_('common.loading')}</p>
    {:else}
      <p class="text-muted email-line">
        <strong>{$_('resetPassword.emailLabel')}</strong>
        {email}
      </p>

      <div class="warning-block" role="alert">
        <p class="warning-heading">{$_('resetPassword.warningHeading')}</p>
        <p class="warning-text">{$_('resetPassword.warningText')}</p>
      </div>

      <form onsubmit={handleSubmit}>
        <div class="field">
          <label for="reset-password">{$_('resetPassword.passwordLabel')}</label
          >
          <input
            id="reset-password"
            class="input"
            type="password"
            bind:value={password}
            autocomplete="new-password"
            required
          />
          <div class="strength-bars">
            {#each [0, 1, 2, 3] as i (i)}
              <div
                class="strength-bar"
                style:background={i < passwordScore
                  ? STRENGTH_COLORS[passwordScore - 1]
                  : 'var(--color-divider)'}
              ></div>
            {/each}
          </div>
          {#if password.length > 0}
            <p class="text-muted strength-label">
              {$_(
                `resetPassword.strength.${STRENGTH_LABEL_KEYS[passwordScore]}`,
              )}
            </p>
          {/if}
          {#if passwordTooShort}
            <p class="hint">
              {$_('resetPassword.passwordHint', {
                values: { min: MIN_PASSWORD_LENGTH },
              })}
            </p>
          {/if}
        </div>

        <div class="field">
          <label for="reset-confirm"
            >{$_('resetPassword.confirmPasswordLabel')}</label
          >
          <input
            id="reset-confirm"
            class="input"
            type="password"
            bind:value={confirmPassword}
            autocomplete="new-password"
            required
          />
        </div>
        {#if passwordsMismatch}
          <p class="hint">{$_('resetPassword.passwordMismatch')}</p>
        {/if}

        <label class="radio ack-checkbox">
          <input
            type="checkbox"
            class="native-checkbox"
            bind:checked={riskAcknowledged}
          />
          {$_('resetPassword.riskAcknowledgeLabel')}
        </label>

        {#if submitError}
          <div role="alert" class="banner-error">{submitError}</div>
        {/if}

        <button
          type="submit"
          class="btn btn-primary btn-block"
          disabled={!canSubmit}
        >
          {submitting
            ? $_('resetPassword.submitting')
            : $_('resetPassword.submit')}
        </button>
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
    width: min(440px, 100%);
    padding: 28px;
  }

  h1 {
    font-size: 24px;
    margin: 0 0 4px;
  }

  .email-line {
    font-size: 13px;
    margin: 0 0 16px;
  }

  .warning-block {
    background: color-mix(in srgb, var(--color-accent) 12%, transparent);
    border-radius: var(--radius-sm);
    padding: 12px 14px;
    margin-bottom: 18px;
  }

  .warning-heading {
    font-weight: 700;
    font-size: 13px;
    margin: 0 0 4px;
  }

  .warning-text {
    font-size: 12px;
    margin: 0;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .strength-bars {
    display: flex;
    gap: 4px;
    margin-top: 8px;
  }

  .strength-bar {
    height: 4px;
    flex: 1;
    border-radius: 2px;
  }

  .strength-label {
    font-size: 11px;
    margin: 6px 0 0;
  }

  .hint {
    margin: -0.5rem 0 0;
    font-size: 0.875rem;
    color: var(--color-text);
    opacity: 0.7;
  }

  .ack-checkbox {
    font-size: 13px;
  }

  .native-checkbox {
    position: static;
    opacity: 1;
    width: auto;
    height: auto;
  }
</style>
