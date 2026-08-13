<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, apiDelete, ApiError } from '../api/client';
  import { deriveArgon2idSalt } from '../crypto/salt';
  import { deriveKeysFromPassword } from '../crypto/password';
  import { packWrappedPrivateKey, wrapPrivateKey } from '../crypto/keypair';
  import { decryptBlob, unsealAnketaKey } from '../crypto/anketaKey';
  import { toBase64 } from '../crypto/encoding';
  import { storeMasterKey, loadMasterKey } from '../crypto/session';
  import { ensureUnlocked } from '../crypto/identity';
  import {
    MIN_PASSWORD_LENGTH,
    STRENGTH_COLORS,
    STRENGTH_LABEL_KEYS,
    scoreOf,
  } from '../passwordStrength';
  import { logOut } from '../auth.svelte';
  import { navigate } from '../router.svelte';
  import type { Answers } from '../anketa/questions';
  import type { Comment } from '../anketa/comments';
  import type { OutcomeItem } from '../anketa/outcomes';
  import type { Goal, GoalCheckpoint } from '../anketa/goals';

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
    apiPut('/api/me/notification-preferences', {
      meetingRemindersEnabled: enabled,
    }).catch(() => {});
  }

  let currentPassword = $state('');
  let newPassword = $state('');
  let confirmPassword = $state('');
  let submitting = $state(false);
  let submitError = $state<string | null>(null);
  let success = $state(false);

  const passwordScore = $derived(scoreOf(newPassword));
  const passwordTooShort = $derived(
    newPassword.length > 0 && newPassword.length < MIN_PASSWORD_LENGTH,
  );
  const passwordsMismatch = $derived(
    confirmPassword.length > 0 && newPassword !== confirmPassword,
  );
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
      const { authKey: currentAuthKey } = await deriveKeysFromPassword(
        currentPassword,
        salt,
      );
      const { authKey: newAuthKey, masterKey: newMasterKey } =
        await deriveKeysFromPassword(newPassword, salt);
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
      submitError =
        error instanceof ApiError
          ? error.message
          : $_('accountSettings.genericError');
    } finally {
      submitting = false;
    }
  }

  interface AnketaBulkRowForExport {
    id: string;
    myRole: 'employee' | 'manager';
    counterpartEmail: string;
    meetingDate: string;
    archivedAt: string | null;
    mySealedKey: string;
    employeeBlob: string | null;
    employeePublishedAt: string | null;
    managerBlob: string | null;
    managerPublishedAt: string | null;
    commentsBlob: string | null;
    outcomesBlob: string | null;
    goals: Goal[];
    goalCheckpointsBlob: string | null;
  }

  let exporting = $state(false);
  let exportError = $state<string | null>(null);

  function downloadJson(filename: string, data: unknown): void {
    const blob = new Blob([JSON.stringify(data, null, 2)], {
      type: 'application/json',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    URL.revokeObjectURL(url);
  }

  async function handleExport(): Promise<void> {
    exporting = true;
    exportError = null;
    try {
      const identity = await ensureUnlocked();
      const masterKey = await loadMasterKey();
      const list = await apiGet<AnketaBulkRowForExport[]>('/api/anketas/bulk');

      const exportedAnketas = [];
      for (const detail of list) {
        // A wrong-key AEAD failure here means this anketa was sealed under a keypair
        // that no longer matches identity.privateKey (most likely a password reset
        // since this anketa was created) — skip just this one, same discipline
        // Report.svelte/CreateAnketa.svelte already established.
        let anketaKey: Uint8Array;
        try {
          anketaKey = await unsealAnketaKey(
            detail.mySealedKey,
            identity.publicKey,
            identity.privateKey,
          );
        } catch {
          continue;
        }

        const myPublishedAt =
          detail.myRole === 'employee'
            ? detail.employeePublishedAt
            : detail.managerPublishedAt;
        const myBlob =
          detail.myRole === 'employee'
            ? detail.employeeBlob
            : detail.managerBlob;
        const counterpartBlob =
          detail.myRole === 'employee'
            ? detail.managerBlob
            : detail.employeeBlob;
        const counterpartPublishedAt =
          detail.myRole === 'employee'
            ? detail.managerPublishedAt
            : detail.employeePublishedAt;

        // My own side: a draft (never published) is encrypted with my *master* key, not
        // the anketa key — mirrors Anketa.svelte's own load() exactly.
        let myAnswers: Answers | null = null;
        if (myBlob && (myPublishedAt ? true : masterKey !== null)) {
          myAnswers = (
            await decryptBlob<Answers>(
              myBlob,
              myPublishedAt ? anketaKey : masterKey!,
            )
          ).data;
        }

        // The counterpart's side is only ever readable once published — an unpublished
        // draft of theirs is encrypted with a master key I never have, by design.
        let counterpartAnswers: Answers | null = null;
        if (counterpartBlob && counterpartPublishedAt) {
          counterpartAnswers = (
            await decryptBlob<Answers>(counterpartBlob, anketaKey)
          ).data;
        }

        const comments = detail.commentsBlob
          ? (await decryptBlob<Comment[]>(detail.commentsBlob, anketaKey)).data
          : [];
        const outcomes = detail.outcomesBlob
          ? (await decryptBlob<OutcomeItem[]>(detail.outcomesBlob, anketaKey))
              .data
          : [];
        const goalCheckpoints = detail.goalCheckpointsBlob
          ? (
              await decryptBlob<GoalCheckpoint[]>(
                detail.goalCheckpointsBlob,
                anketaKey,
              )
            ).data
          : [];

        exportedAnketas.push({
          id: detail.id,
          counterpartEmail: detail.counterpartEmail,
          myRole: detail.myRole,
          meetingDate: detail.meetingDate,
          archivedAt: detail.archivedAt,
          myAnswers,
          counterpartAnswers,
          comments,
          outcomes,
          goals: detail.goals,
          goalCheckpoints,
        });
      }

      downloadJson(
        `encrypted1on1-export-${new Date().toISOString().slice(0, 10)}.json`,
        {
          exportedAt: new Date().toISOString(),
          email: identity.email,
          anketas: exportedAnketas,
        },
      );
    } catch (error) {
      exportError =
        error instanceof ApiError
          ? error.message
          : $_('accountSettings.exportGenericError');
    } finally {
      exporting = false;
    }
  }

  let deleteCurrentPassword = $state('');
  let deleteRiskAcknowledged = $state(false);
  let deleting = $state(false);
  let deleteError = $state<string | null>(null);

  const canDelete = $derived(
    deleteCurrentPassword.length > 0 && deleteRiskAcknowledged && !deleting,
  );

  async function handleDelete(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!canDelete) return;

    deleting = true;
    deleteError = null;
    try {
      const identity = await ensureUnlocked();
      const salt = await deriveArgon2idSalt(identity.email);
      const { authKey: currentAuthKey } = await deriveKeysFromPassword(
        deleteCurrentPassword,
        salt,
      );

      await apiDelete('/api/me', {
        currentAuthKey: await toBase64(currentAuthKey),
      });

      // The server already invalidated the session — logOut() is still safe to call
      // (best-effort, wrapped in its own try/catch) and is what clears the client-side
      // master key + cached identity, same as a normal logout.
      await logOut();
      navigate('/');
    } catch (error) {
      deleteError =
        error instanceof ApiError
          ? error.message
          : $_('accountSettings.deleteGenericError');
      deleting = false;
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
          <label for="current-password"
            >{$_('accountSettings.currentPasswordLabel')}</label
          >
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
          <label for="new-password"
            >{$_('accountSettings.newPasswordLabel')}</label
          >
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
                style:background={i < passwordScore
                  ? STRENGTH_COLORS[passwordScore - 1]
                  : 'var(--color-divider)'}
              ></div>
            {/each}
          </div>
          {#if newPassword.length > 0}
            <p class="text-muted strength-label">
              {$_(
                `accountSettings.strength.${STRENGTH_LABEL_KEYS[passwordScore]}`,
              )}
            </p>
          {/if}
          {#if passwordTooShort}
            <p class="hint">
              {$_('accountSettings.passwordHint', {
                values: { min: MIN_PASSWORD_LENGTH },
              })}
            </p>
          {/if}
        </div>

        <div class="field">
          <label for="confirm-password"
            >{$_('accountSettings.confirmPasswordLabel')}</label
          >
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

        <button
          type="submit"
          class="btn btn-primary btn-block"
          disabled={!canSubmit}
        >
          {submitting
            ? $_('accountSettings.submitting')
            : $_('accountSettings.submit')}
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
      <p class="text-muted hint">
        {$_('accountSettings.meetingRemindersHint')}
      </p>
    </div>

    <div class="card elev-md">
      <h2>{$_('accountSettings.exportTitle')}</h2>
      <p class="text-muted subtitle">{$_('accountSettings.exportHint')}</p>
      {#if exportError}
        <div role="alert" class="banner-error">{exportError}</div>
      {/if}
      <button
        type="button"
        class="btn btn-secondary btn-block"
        onclick={handleExport}
        disabled={exporting}
      >
        {exporting
          ? $_('accountSettings.exporting')
          : $_('accountSettings.exportButton')}
      </button>
    </div>

    <div class="card elev-md">
      <h2>{$_('accountSettings.deleteTitle')}</h2>

      <div class="warning-block" role="alert">
        <p class="warning-heading">
          {$_('accountSettings.deleteWarningHeading')}
        </p>
        <p class="warning-text">{$_('accountSettings.deleteWarningText')}</p>
      </div>

      <form onsubmit={handleDelete}>
        <div class="field">
          <label for="delete-current-password"
            >{$_('accountSettings.currentPasswordLabel')}</label
          >
          <input
            id="delete-current-password"
            class="input"
            type="password"
            bind:value={deleteCurrentPassword}
            autocomplete="current-password"
            required
          />
        </div>

        <label class="radio ack-checkbox">
          <input
            type="checkbox"
            class="native-checkbox"
            bind:checked={deleteRiskAcknowledged}
          />
          {$_('accountSettings.deleteRiskAcknowledgeLabel')}
        </label>

        {#if deleteError}
          <div role="alert" class="banner-error">{deleteError}</div>
        {/if}

        <button
          type="submit"
          class="btn btn-secondary btn-block"
          disabled={!canDelete}
        >
          {deleting
            ? $_('accountSettings.deleting')
            : $_('accountSettings.deleteButton')}
        </button>
      </form>
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

  .ack-checkbox {
    font-size: 13px;
  }
</style>
