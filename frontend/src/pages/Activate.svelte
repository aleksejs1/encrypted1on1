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
  const STRENGTH_COLORS = ['#c0574a', '#d68a3f', '#c9a23f', '#8fa073'];
  const STRENGTH_LABEL_KEYS = ['tooWeak', 'weak', 'okay', 'good', 'strong'] as const;

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

  // Mirrors the mockup's scoreOf() — not new validation, just a visual read on the
  // MIN_PASSWORD_LENGTH/mismatch checks that already gate submission below.
  function scoreOf(pw: string): number {
    if (!pw) return 0;
    let score = 0;
    if (pw.length >= 8) score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
    if (/[0-9]/.test(pw) || /[^A-Za-z0-9]/.test(pw)) score++;
    return Math.min(score, 4);
  }

  const passwordScore = $derived(scoreOf(password));
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
  <div class="card elev-md">
    <h1>{$_('activate.title')}</h1>

    {#if done}
      <p>{$_('activate.done')}</p>
    {:else if lookupError}
      <p class="banner-error">{lookupError}</p>
    {:else if email === null}
      <p>{$_('common.loading')}</p>
    {:else}
      <p class="text-muted email-line"><strong>{$_('activate.emailLabel')}</strong> {email}</p>
      <p class="text-muted key-explainer">{$_('activate.keyExplainer')}</p>

      <form onsubmit={handleSubmit}>
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
                style:background={i < passwordScore ? STRENGTH_COLORS[passwordScore - 1] : 'var(--color-divider)'}
              ></div>
            {/each}
          </div>
          {#if password.length > 0}
            <p class="text-muted strength-label">
              {$_(`activate.strength.${STRENGTH_LABEL_KEYS[passwordScore]}`)}
            </p>
          {/if}
          {#if passwordTooShort}
            <p class="hint">{$_('activate.passwordHint', { values: { min: MIN_PASSWORD_LENGTH } })}</p>
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

        <button type="submit" class="btn btn-primary btn-block" disabled={!canSubmit}>
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

  .one-time-note {
    font-size: 12px;
    margin: 0;
  }
</style>
