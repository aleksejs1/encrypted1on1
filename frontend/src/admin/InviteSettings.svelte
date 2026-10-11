<script lang="ts">
  import { onMount } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiPut, ApiError } from '../api/client';
  import { ensureUnlocked, clearIdentity } from '../crypto/identity.svelte';

  /**
   * The company's invite settings: who can invite, and to which email domain.
   * On the admin panel's Invites tab, beside the invite form (GitHub issue #280).
   */

  // Deliberately just these two — not the full Company.REGISTRATION_MODES set. `domain`
  // (open self-registration) is a separate, orthogonal feature (whether people can join
  // without an invite at all, not "who can invite") and is unavailable on a multi-company
  // instance anyway (SignupController refuses it globally once CLOUD_MODE is on,
  // regardless of what any one company's registrationMode says) — offering it here would
  // let an admin pick a setting that silently does nothing. Self-hosted operators who
  // want it can still set it directly (bin/console dbal:run-sql), same as before this
  // panel existed.
  const REGISTRATION_MODES = ['admin_only', 'invite'] as const;
  // Plain string, not a literal union of REGISTRATION_MODES: a company's actual
  // registrationMode can still be 'domain' (set outside this panel, e.g. by a
  // self-hosted operator via SQL) — this only needs to round-trip that value
  // correctly if the admin doesn't touch the select, not validate it client-side.
  type RegistrationMode = string;

  let registrationMode = $state<RegistrationMode>('invite');
  let allowedEmailDomain = $state('');
  // Nothing is editable or saved before the values this session knows are in the
  // form: its defaults must not be saved over them.
  let loaded = $state(false);
  let settingsSaving = $state(false);
  let settingsSaved = $state(false);
  let settingsError = $state<string | null>(null);

  // AdminGate has already unlocked: ensureUnlocked() is memoized, so this is not
  // a second fetch. onMount, not an $effect: see AdminGate's own comment.
  onMount(() => {
    ensureUnlocked()
      .then((identity) => {
        registrationMode = identity.registrationMode;
        allowedEmailDomain = identity.allowedEmailDomain;
        loaded = true;
      })
      .catch((error: unknown) => {
        settingsError =
          error instanceof ApiError ? error.message : $_('admin.errorLoad');
      });
  });

  async function saveInviteSettings(): Promise<void> {
    settingsSaving = true;
    settingsSaved = false;
    settingsError = null;
    try {
      const result = await apiPut<{
        registrationMode: RegistrationMode;
        allowedEmailDomain: string;
      }>('/api/admin/company-settings', {
        registrationMode,
        allowedEmailDomain,
      });
      registrationMode = result.registrationMode;
      allowedEmailDomain = result.allowedEmailDomain;
      settingsSaved = true;
      // The cached identity (used elsewhere to decide whether to show the
      // general "Invite" UI, e.g. AccountSettings.svelte) is now stale —
      // clear it so the next ensureUnlocked() call re-fetches from /api/me.
      clearIdentity();
    } catch (error) {
      settingsError =
        error instanceof ApiError ? error.message : $_('admin.errorUpdate');
    } finally {
      settingsSaving = false;
    }
  }
</script>

<div class="card elev-md">
  <h2>{$_('admin.inviteSettingsTitle')}</h2>
  <p class="text-muted hint">{$_('admin.inviteSettingsHint')}</p>

  <div class="field">
    <label for="registration-mode">{$_('admin.registrationModeLabel')}</label>
    <select
      id="registration-mode"
      class="input"
      bind:value={registrationMode}
      disabled={!loaded}
    >
      {#each REGISTRATION_MODES as mode (mode)}
        <option value={mode}>{$_(`admin.registrationMode.${mode}`)}</option>
      {/each}
      {#if !REGISTRATION_MODES.includes(registrationMode as 'admin_only' | 'invite')}
        <!-- Not offered as a normal choice (see the REGISTRATION_MODES comment
             above), but a company already in this state — set outside this panel,
             e.g. a self-hosted operator's own SQL — needs a matching <option> so
             selecting anything else here is a deliberate choice, not a silent
             downgrade from an unmatched <select> defaulting to the first option. -->
        <option value={registrationMode}
          >{$_('admin.registrationModeOther', {
            values: { mode: registrationMode },
          })}</option
        >
      {/if}
    </select>
  </div>

  <div class="field">
    <label for="allowed-email-domain"
      >{$_('admin.allowedEmailDomainLabel')}</label
    >
    <input
      id="allowed-email-domain"
      class="input"
      type="text"
      placeholder="company.com"
      bind:value={allowedEmailDomain}
      disabled={!loaded}
    />
    <p class="text-muted hint">{$_('admin.allowedEmailDomainHint')}</p>
  </div>

  {#if settingsSaved}
    <p class="banner-success" role="status">
      {$_('admin.inviteSettingsSaved')}
    </p>
  {/if}
  {#if settingsError}
    <p class="banner-error" role="alert">{settingsError}</p>
  {/if}

  <button
    type="button"
    class="btn btn-primary"
    onclick={saveInviteSettings}
    disabled={!loaded || settingsSaving}
  >
    {settingsSaving ? $_('common.saving') : $_('common.save')}
  </button>
</div>
