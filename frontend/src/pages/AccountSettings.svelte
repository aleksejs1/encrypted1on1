<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, ApiError } from '../api/client';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import { packWrappedPrivateKey, wrapPrivateKey } from '../crypto/keypair';
  import { toBase64 } from '../crypto/encoding';
  import { storeMasterKey } from '../crypto/session';
  import { ensureUnlocked } from '../crypto/identity';
  import { MIN_PASSWORD_LENGTH, STRENGTH_COLORS, STRENGTH_LABEL_KEYS, scoreOf } from '../passwordStrength';

  let meetingRemindersEnabled = $state<boolean | null>(null);

  $effect(() => {
    apiGet<{ meetingRemindersEnabled: boolean }>('/api/me').then((me) => {
      meetingRemindersEnabled = me.meetingRemindersEnabled;
    });
  });

  function handleNotificationToggle(enabled: boolean): void {
    meetingRemindersEnabled = enabled;
    // Best-effort background sync, same pattern as LanguageSwitcher.svelte's
    // /api/me/locale — a single low-stakes preference toggle doesn't need a
    // blocking spinner or its own error banner.
    apiPut('/api/me/notification-preferences', { meetingRemindersEnabled: enabled }).catch(() => {});
  }

  let currentPassword = $state('');
  let newPassword = $state('');
  let confirmPassword = $state('');
  let submitting = $state(false);
  let submitError = $state<string | null>(null);
  let success = $state(false);

  const passwordScore = $derived(scoreOf(newPassword));
  const passwordTooShort = $derived(newPassword.length > 0 && newPassword.length < MIN_PASSWORD_LENGTH);
  const passwordsMismatch = $derived(confirmPassword.length > 0 && newPassword !== confirmPassword);
  const canSubmit = $derived(
    currentPassword.length > 0 &&
      newPassword.length >= MIN_PASSWORD_LENGTH &&
      newPassword === confirmPassword &&
      !submitting,
  );

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit) return;

    submitting = true;
    submitError = null;
    success = false;
    try {
      const identity = await ensureUnlocked();
      const salt = await deriveArgon2idSalt(identity.email);
      const { authKey: currentAuthKey } = await deriveKeysFromPassword(currentPassword, salt);
      const { authKey: newAuthKey, masterKey: newMasterKey } = await deriveKeysFromPassword(newPassword, salt);
      // Re-wraps the *same* private key — unlike a forgotten-password reset, the
      // keypair itself never changes here, so no anketa is ever affected.
      const wrapped = await wrapPrivateKey(identity.privateKey, newMasterKey);

      await apiPut('/api/me/password', {
        currentAuthKey: await toBase64(currentAuthKey),
        newAuthKey: await toBase64(newAuthKey),
        newEncryptedPrivateKey: await packWrappedPrivateKey(wrapped),
      });

      await storeMasterKey(newMasterKey);
      currentPassword = '';
      newPassword = '';
      confirmPassword = '';
      success = true;
    } catch (error) {
      submitError = error instanceof ApiError ? error.message : $_('accountSettings.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="settings-stack">
    <div class="card elev-md">
      <h1>{$_('accountSettings.title')}</h1>
      <p class="text-muted subtitle">{$_('accountSettings.subtitle')}</p>

      <form onsubmit={handleSubmit}>
        <div class="field">
          <label for="current-password">{$_('accountSettings.currentPasswordLabel')}</label>
          <input
            id="current-password"
            class="input"
            type="password"
            bind:value={currentPassword}
            autocomplete="current-password"
            required
          />
        </div>

        <div class="field">
          <label for="new-password">{$_('accountSettings.newPasswordLabel')}</label>
          <input
            id="new-password"
            class="input"
            type="password"
            bind:value={newPassword}
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
          {#if newPassword.length > 0}
            <p class="text-muted strength-label">
              {$_(`accountSettings.strength.${STRENGTH_LABEL_KEYS[passwordScore]}`)}
            </p>
          {/if}
          {#if passwordTooShort}
            <p class="hint">{$_('accountSettings.passwordHint', { values: { min: MIN_PASSWORD_LENGTH } })}</p>
          {/if}
        </div>

        <div class="field">
          <label for="confirm-password">{$_('accountSettings.confirmPasswordLabel')}</label>
          <input
            id="confirm-password"
            class="input"
            type="password"
            bind:value={confirmPassword}
            autocomplete="new-password"
            required
          />
        </div>
        {#if passwordsMismatch}
          <p class="hint">{$_('accountSettings.passwordMismatch')}</p>
        {/if}

        {#if success}
          <p class="banner-success">{$_('accountSettings.successMessage')}</p>
        {/if}
        {#if submitError}
          <div role="alert" class="banner-error">{submitError}</div>
        {/if}

        <button type="submit" class="btn btn-primary btn-block" disabled={!canSubmit}>
          {submitting ? $_('accountSettings.submitting') : $_('accountSettings.submit')}
        </button>
      </form>
    </div>

    <div class="card elev-md">
      <h2>{$_('accountSettings.notificationsTitle')}</h2>
      <label class="radio notif-checkbox">
        <input
          type="checkbox"
          class="native-checkbox"
          checked={meetingRemindersEnabled ?? true}
          disabled={meetingRemindersEnabled === null}
          onchange={(e) => handleNotificationToggle(e.currentTarget.checked)}
        />
        {$_('accountSettings.meetingRemindersLabel')}
      </label>
      <p class="text-muted hint">{$_('accountSettings.meetingRemindersHint')}</p>
    </div>
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

  .settings-stack {
    width: min(440px, 100%);
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  .card {
    padding: 28px;
  }

  h1 {
    font-size: 24px;
    margin: 0 0 4px;
  }

  h2 {
    font-size: 18px;
    margin: 0 0 14px;
  }

  .subtitle {
    font-size: 13px;
    margin: 0 0 20px;
  }

  .notif-checkbox {
    font-size: 14px;
  }

  .native-checkbox {
    position: static;
    opacity: 1;
    width: auto;
    height: auto;
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
</style>
