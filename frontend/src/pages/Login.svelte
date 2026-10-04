<script lang="ts">
  import { _, locale } from 'svelte-i18n';
  import { get } from 'svelte/store';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy } from '../api/abortOnDestroy';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import { unpackWrappedPrivateKey, unwrapPrivateKey } from '../crypto/keypair';
  import { toBase64 } from '../crypto/encoding';
  import { storeLoginMasterKey } from '../crypto/session';
  import { markAuthenticated } from '../auth.svelte';
  import { DEMO_MODE_ENABLED, DEMO_PASSWORD, demoEmailFor } from '../demo';

  let signupOpen = $state(false);
  let cloudMode = $state(false);

  // Cancels the mount-time fetch below on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();

  $effect(() => {
    apiGet<{ registrationMode: string; cloudMode: boolean }>(
      '/api/registration-info',
      { signal: readAbort },
    )
      .then((info) => {
        signupOpen = info.registrationMode === 'domain';
        cloudMode = info.cloudMode;
      })
      .catch(() => {});
  });

  let email = $state('');
  let password = $state('');
  let rememberMe = $state(false);
  let submitting = $state(false);
  let error = $state<string | null>(null);

  const canSubmit = $derived(
    email.length > 0 && password.length > 0 && !submitting,
  );

  async function performLogin(
    loginEmail: string,
    loginPassword: string,
    remember: boolean,
  ): Promise<void> {
    submitting = true;
    error = null;
    try {
      const salt = await deriveArgon2idSalt(loginEmail);
      const { authKey, masterKey } = await deriveKeysFromPassword(
        loginPassword,
        salt,
      );

      const response = await apiPost<{
        publicKey: string;
        encryptedPrivateKey: string;
        rememberedSecondsLeft: number | null;
      }>('/api/login', {
        email: loginEmail,
        authKey: await toBase64(authKey),
        rememberMe: remember,
      });

      // Unwrapping is also a correctness check: a wrong master-key throws (see keypair.ts).
      const wrapped = await unpackWrappedPrivateKey(
        response.encryptedPrivateKey,
      );
      await unwrapPrivateKey(wrapped, masterKey);

      // The server's answer decides, not the checkbox: it never remembers the
      // demo account.
      await storeLoginMasterKey(
        masterKey,
        typeof response.rememberedSecondsLeft === 'number'
          ? {
              secondsLeft: response.rememberedSecondsLeft,
              owner: response.publicKey,
            }
          : null,
      );
      markAuthenticated();
    } catch (err) {
      error = err instanceof ApiError ? err.message : $_('login.genericError');
    } finally {
      submitting = false;
    }
  }

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit) return;
    await performLogin(email, password, rememberMe);
  }

  async function handleDemoLogin(): Promise<void> {
    if (submitting) return;
    // Follows whatever locale is currently displayed (?lang= in the URL,
    // the language switcher, or the usual browser-detected default) — see
    // demo.ts's own docblock for which locales have their own demo pair
    // and what happens for the ones that don't yet.
    await performLogin(demoEmailFor(get(locale) ?? 'en'), DEMO_PASSWORD, false);
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

      <label class="radio remember">
        <input
          type="checkbox"
          class="native-checkbox"
          bind:checked={rememberMe}
          aria-describedby={rememberMe ? 'login-remember-note' : undefined}
        />
        {$_('login.rememberMe')}
      </label>
      {#if rememberMe}
        <p id="login-remember-note" class="text-muted remember-note">
          {$_('login.rememberNote')}
        </p>
      {/if}

      {#if error}
        <div role="alert" class="banner-error">{error}</div>
      {/if}

      <button
        type="submit"
        class="btn btn-primary btn-block"
        disabled={!canSubmit}
      >
        {submitting ? $_('login.submitting') : $_('login.submit')}
      </button>

      {#if submitting}
        <p class="text-muted crypto-note">
          <span aria-hidden="true">⏳</span>
          {$_('login.cryptoNote')}
        </p>
      {/if}

      <a href="/forgot-password" class="forgot-link"
        >{$_('login.forgotPassword')}</a
      >
      {#if signupOpen}
        <a href="/signup" class="signup-link">{$_('login.signUpLink')}</a>
      {/if}
      {#if cloudMode}
        <a href="/create-company" class="signup-link"
          >{$_('login.createCompanyLink')}</a
        >
      {/if}
    </form>

    {#if DEMO_MODE_ENABLED}
      <div class="hr"></div>
      <button
        type="button"
        class="btn btn-secondary btn-block"
        onclick={handleDemoLogin}
        disabled={submitting}
      >
        {$_('login.tryDemo')}
      </button>
      <p class="text-muted demo-note">{$_('login.demoNote')}</p>
    {/if}

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

  .crypto-note {
    font-size: 12px;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 6px;
  }

  .remember {
    font-size: 13px;
  }

  .native-checkbox {
    position: static;
    opacity: 1;
    width: auto;
    height: auto;
    pointer-events: auto;
  }

  .remember-note {
    font-size: 12px;
    margin: -6px 0 0;
  }

  .session-note {
    font-size: 12px;
    margin: 0;
  }

  .demo-note {
    font-size: 12px;
    margin: 8px 0 0;
    text-align: center;
  }

  .forgot-link,
  .signup-link {
    font-size: 13px;
  }
</style>
