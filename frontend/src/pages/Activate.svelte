<script lang="ts">
  import { tick } from 'svelte';
  import { _, locale } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import {
    generateKeyPair,
    packWrappedPrivateKey,
    wrapPrivateKey,
  } from '../crypto/keypair';
  import { toBase64 } from '../crypto/encoding';
  import { storeLoginMasterKey } from '../crypto/session';
  import { markAuthenticated } from '../auth.svelte';
  import { navigate } from '../router.svelte';
  import { linkStateFromError, type LinkState } from '../activationLink';
  import {
    MIN_PASSWORD_LENGTH,
    STRENGTH_COLORS,
    STRENGTH_LABEL_KEYS,
    scoreOf,
  } from '../passwordStrength';

  const { token }: { token: string } = $props();

  let link = $state<LinkState>({ kind: 'loading' });
  const email = $derived('ready' === link.kind ? link.email : null);
  let name = $state('');
  let password = $state('');
  let confirmPassword = $state('');
  let submitting = $state(false);
  let submitError = $state<string | null>(null);
  let done = $state(false);
  let renewalSending = $state(false);
  let renewalError = $state<string | null>(null);
  let renewalSentMessage = $state<HTMLElement | null>(null);
  let heading = $state<HTMLElement | null>(null);

  // After the button or form that had focus is replaced by another link state,
  // focus lands on the outcome instead of <body>: the "Request sent"
  // confirmation if that's the new state, the card's heading otherwise.
  async function focusOutcome(): Promise<void> {
    await tick();
    (renewalSentMessage ?? heading)?.focus();
  }

  // Cancels the mount-time lookup fetch below on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();

  $effect(() => {
    apiGet<{ email: string }>(`/api/activation-tokens/${token}`, {
      signal: readAbort,
    })
      .then((result) => {
        link = { kind: 'ready', email: result.email };
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        link = linkStateFromError(error) ?? {
          kind: 'error',
          message: error instanceof ApiError ? error.message : null,
        };
      });
  });

  // GitHub issue #169: asks whoever invited this person for a new invite. The
  // response is a link state too (already requested, activated meanwhile, …),
  // so any of those replaces the page's state; only the per-IP rate limit, a
  // failed send or a network error stays an error under the button.
  async function requestRenewal(): Promise<void> {
    if (renewalSending) return;
    renewalSending = true;
    renewalError = null;
    try {
      await apiPost(`/api/activation-tokens/${token}/request-renewal`, {});
      link = { kind: 'expired', renewal: 'requested' };
    } catch (error) {
      const state = linkStateFromError(error);
      if (null === state) {
        renewalError =
          error instanceof ApiError
            ? error.message
            : $_('activate.genericError');
        return;
      }
      link = state;
    } finally {
      renewalSending = false;
    }
    await focusOutcome();
  }

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
      const { publicKey, privateKey } = await generateKeyPair();
      const wrapped = await wrapPrivateKey(privateKey, masterKey);

      await apiPost(`/api/activation-tokens/${token}/complete`, {
        authKey: await toBase64(authKey),
        publicKey: await toBase64(publicKey),
        encryptedPrivateKey: await packWrappedPrivateKey(wrapped),
        // The UI language active right now (Phase 6h) — so this account starts with a
        // sensible email language (Phase 6i) instead of always English.
        locale: $locale,
        displayName: name.trim(),
      });

      // This login isn't a remembered one; a key an earlier account left in
      // this browser goes.
      await storeLoginMasterKey(masterKey, null);
      done = true;
      markAuthenticated();
      navigate('/');
    } catch (error) {
      // The link expired (or was used elsewhere) while the form was open: show
      // that state, with its renewal option, instead of an error under the form.
      const state = linkStateFromError(error);
      if (null !== state) {
        link = state;
        void focusOutcome();
        return;
      }
      submitError =
        error instanceof ApiError ? error.message : $_('activate.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="card elev-md">
    <h1 tabindex="-1" bind:this={heading}>{$_('activate.title')}</h1>

    {#if done}
      <p>{$_('activate.done')}</p>
    {:else if 'loading' === link.kind}
      <p>{$_('common.loading')}</p>
    {:else if 'error' === link.kind}
      <p class="banner-error">{link.message ?? $_('activate.lookupError')}</p>
    {:else if 'invalid' === link.kind}
      <p class="banner-error">{$_('activate.invalidLink')}</p>
    {:else if 'alreadyActive' === link.kind}
      <p class="status-line">{$_('activate.alreadyActive')}</p>
      <a href="/" class="btn btn-primary btn-block">{$_('activate.logIn')}</a>
    {:else if 'expired' === link.kind}
      <p class="banner-error">{$_('activate.expired')}</p>
      {#if 'available' === link.renewal}
        <p class="text-muted status-line">{$_('activate.renewalAvailable')}</p>
        <button
          type="button"
          class="btn btn-primary btn-block"
          disabled={renewalSending}
          onclick={requestRenewal}
        >
          {renewalSending
            ? $_('activate.renewalSending')
            : $_('activate.renewalRequest')}
        </button>
        {#if renewalError}
          <div role="alert" class="banner-error renewal-error">
            {renewalError}
          </div>
        {/if}
      {:else if 'requested' === link.renewal}
        <p
          class="banner-success"
          role="status"
          tabindex="-1"
          bind:this={renewalSentMessage}
        >
          {$_('activate.renewalRequested')}
        </p>
      {:else if 'reissued' === link.renewal}
        <p class="text-muted status-line">{$_('activate.renewalReissued')}</p>
      {:else if 'create_company' === link.renewal}
        <p class="text-muted status-line">
          {$_('activate.renewalCreateCompany')}
        </p>
        <a href="/create-company" class="btn btn-primary btn-block"
          >{$_('activate.createCompanyAgain')}</a
        >
      {:else if 'signup' === link.renewal}
        <p class="text-muted status-line">{$_('activate.renewalSignup')}</p>
        <a href="/signup" class="btn btn-primary btn-block"
          >{$_('activate.signUpAgain')}</a
        >
      {:else}
        <p class="text-muted status-line">{$_('activate.renewalNone')}</p>
      {/if}
    {:else}
      <p class="text-muted email-line">
        <strong>{$_('activate.emailLabel')}</strong>
        {email}
      </p>
      <p class="text-muted key-explainer">{$_('activate.keyExplainer')}</p>

      <form onsubmit={handleSubmit}>
        <div class="field">
          <label for="act-name">{$_('activate.nameLabel')}</label>
          <input
            id="act-name"
            class="input"
            type="text"
            bind:value={name}
            autocomplete="name"
            placeholder={$_('activate.namePlaceholder')}
          />
          <p class="hint">{$_('activate.nameHint')}</p>
        </div>

        <div class="field">
          <label for="act-password">{$_('activate.passwordLabel')}</label>
          <input
            id="act-password"
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
              {$_(`activate.strength.${STRENGTH_LABEL_KEYS[passwordScore]}`)}
            </p>
          {/if}
          {#if passwordTooShort}
            <p class="hint">
              {$_('activate.passwordHint', {
                values: { min: MIN_PASSWORD_LENGTH },
              })}
            </p>
          {/if}
        </div>

        <div class="field">
          <label for="act-confirm">{$_('activate.confirmPasswordLabel')}</label>
          <input
            id="act-confirm"
            class="input"
            type="password"
            bind:value={confirmPassword}
            autocomplete="new-password"
            required
          />
        </div>
        {#if passwordsMismatch}
          <p class="hint">{$_('activate.passwordMismatch')}</p>
        {/if}

        {#if submitError}
          <div role="alert" class="banner-error">{submitError}</div>
        {/if}

        <button
          type="submit"
          class="btn btn-primary btn-block"
          disabled={!canSubmit}
        >
          {submitting ? $_('activate.submitting') : $_('activate.submit')}
        </button>
      </form>

      <div class="hr"></div>
      <p class="text-muted one-time-note">{$_('activate.oneTimeNote')}</p>
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
    width: min(420px, 100%);
    padding: 28px;
  }

  h1 {
    font-size: 24px;
    margin: 0 0 4px;
  }

  .email-line {
    font-size: 13px;
    margin: 0 0 4px;
  }

  .key-explainer {
    font-size: 13px;
    margin: 0 0 20px;
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

  /* When the password-too-short hint follows the strength label (rather than
     sitting directly under the input, which .hint's negative margin assumes),
     the negative margin pulls it up into the label's own text instead. */
  .strength-label + .hint {
    margin-top: 6px;
  }

  .status-line {
    margin: 12px 0 16px;
  }

  .renewal-error {
    margin-top: 12px;
  }

  .banner-success {
    margin-top: 12px;
  }

  .one-time-note {
    font-size: 12px;
    margin: 0;
  }
</style>
