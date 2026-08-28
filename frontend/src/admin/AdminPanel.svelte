<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, apiDelete, ApiError } from '../api/client';
  import { ensureUnlocked, clearIdentity } from '../crypto/identity';
  import { formatDisplayDate } from '../datePreference.svelte';
  import InviteForm from './InviteForm.svelte';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';

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

  interface AdminUser {
    id: string;
    email: string;
    displayName: string;
    isAdmin: boolean;
    isBlocked: boolean;
    createdAt: string;
    deletedAt: string | null;
  }

  let myUserId = $state<string | null>(null);
  let users = $state<AdminUser[]>([]);
  let panelDataError = $state<string | null>(null);
  let actionError = $state<string | null>(null);
  let pending = $state<Record<string, boolean>>({});

  let registrationMode = $state<RegistrationMode>('invite');
  let allowedEmailDomain = $state('');
  let settingsSaving = $state(false);
  let settingsSaved = $state(false);
  let settingsError = $state<string | null>(null);

  /** AdminGate has already confirmed isAdmin — ensureUnlocked() is memoized (see its own docblock), so calling it again here to get the rest of the identity is cheap, not a second real fetch. */
  async function loadPanelData(): Promise<void> {
    try {
      const identity = await ensureUnlocked();
      myUserId = identity.userId;
      registrationMode = identity.registrationMode;
      allowedEmailDomain = identity.allowedEmailDomain;
      users = await apiGet<AdminUser[]>('/api/admin/users');
    } catch (error) {
      panelDataError =
        error instanceof ApiError ? error.message : $_('admin.errorLoad');
    }
  }

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

  async function toggleBlocked(user: AdminUser): Promise<void> {
    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiPut<{ isBlocked: boolean }>(
        `/api/admin/users/${user.id}/blocked`,
        {
          blocked: !user.isBlocked,
        },
      );
      users = users.map((u) =>
        u.id === user.id ? { ...u, isBlocked: result.isBlocked } : u,
      );
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('admin.errorUpdate');
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }

  async function toggleAdmin(user: AdminUser): Promise<void> {
    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiPut<{ isAdmin: boolean }>(
        `/api/admin/users/${user.id}/admin`,
        {
          isAdmin: !user.isAdmin,
        },
      );
      users = users.map((u) =>
        u.id === user.id ? { ...u, isAdmin: result.isAdmin } : u,
      );
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('admin.errorUpdate');
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }

  /**
   * A plain confirm() would be a single accidental click away from permanently
   * anonymizing another employee's account — the self-service equivalent
   * (AccountSettings.svelte) requires re-entering the current password, which isn't
   * available to an admin acting on someone else's account, so this asks the admin to
   * type the target's email instead as an equivalent-friction stand-in.
   */
  async function deletePermanently(user: AdminUser): Promise<void> {
    const typed = prompt(
      $_('admin.deleteConfirmPrompt', { values: { email: user.email } }),
    );
    if (typed !== user.email) return;

    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiDelete<{
        email: string;
        displayName: string;
        deletedAt: string;
      }>(`/api/admin/users/${user.id}`, null);
      users = users.map((u) =>
        u.id === user.id
          ? {
              ...u,
              email: result.email,
              displayName: result.displayName,
              deletedAt: result.deletedAt,
            }
          : u,
      );
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('admin.errorDelete');
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }
</script>

<main>
  <h1>{$_('admin.title')}</h1>

  <AdminGate onReady={loadPanelData}>
    <AdminTabStrip active="users" />

    {#if panelDataError}
      <p class="banner-error">{panelDataError}</p>
    {:else}
      <div class="card elev-md invite-settings">
        <h2>{$_('admin.inviteSettingsTitle')}</h2>
        <p class="text-muted hint">{$_('admin.inviteSettingsHint')}</p>

        <div class="field">
          <label for="registration-mode"
            >{$_('admin.registrationModeLabel')}</label
          >
          <select
            id="registration-mode"
            class="input"
            bind:value={registrationMode}
          >
            {#each REGISTRATION_MODES as mode (mode)}
              <option value={mode}
                >{$_(`admin.registrationMode.${mode}`)}</option
              >
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
          />
          <p class="text-muted hint">{$_('admin.allowedEmailDomainHint')}</p>
        </div>

        {#if settingsSaved}
          <p class="banner-success">{$_('admin.inviteSettingsSaved')}</p>
        {/if}
        {#if settingsError}
          <p class="banner-error">{settingsError}</p>
        {/if}

        <button
          type="button"
          class="btn btn-primary"
          onclick={saveInviteSettings}
          disabled={settingsSaving}
        >
          {settingsSaving ? $_('common.saving') : $_('common.save')}
        </button>
      </div>

      <div class="invite-wrap">
        <InviteForm />
      </div>

      {#if actionError}
        <p class="banner-error">{actionError}</p>
      {/if}

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>{$_('admin.nameHeader')}</th>
              <th>{$_('admin.emailHeader')}</th>
              <th>{$_('admin.statusHeader')}</th>
              <th>{$_('admin.roleHeader')}</th>
              <th>{$_('admin.createdHeader')}</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {#each users as user (user.id)}
              <tr>
                <td>{user.displayName || '—'}</td>
                <td>{user.email}</td>
                <td>
                  <span
                    class="tag {user.isBlocked
                      ? 'tag-neutral'
                      : 'tag-accent-2'}"
                  >
                    {user.deletedAt
                      ? $_('admin.statusDeleted')
                      : user.isBlocked
                        ? $_('admin.statusBlocked')
                        : $_('admin.statusActive')}
                  </span>
                </td>
                <td
                  >{user.isAdmin
                    ? $_('admin.roleAdmin')
                    : $_('admin.roleUser')}</td
                >
                <td>{formatDisplayDate(user.createdAt)}</td>
                <td class="actions">
                  {#if !user.deletedAt}
                    <button
                      type="button"
                      class="btn btn-secondary btn-small"
                      onclick={() => toggleBlocked(user)}
                      disabled={pending[user.id] || user.id === myUserId}
                    >
                      {user.isBlocked ? $_('admin.unblock') : $_('admin.block')}
                    </button>
                    <button
                      type="button"
                      class="btn btn-secondary btn-small"
                      onclick={() => toggleAdmin(user)}
                      disabled={pending[user.id]}
                    >
                      {user.isAdmin
                        ? $_('admin.revokeAdmin')
                        : $_('admin.makeAdmin')}
                    </button>
                    {#if user.isBlocked}
                      <button
                        type="button"
                        class="btn btn-secondary btn-small"
                        onclick={() => deletePermanently(user)}
                        disabled={pending[user.id] || user.id === myUserId}
                      >
                        {$_('admin.deletePermanently')}
                      </button>
                    {/if}
                  {/if}
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </AdminGate>
</main>

<style>
  main {
    max-width: 56rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  .invite-settings {
    margin-bottom: 24px;
    max-width: 26rem;
  }

  .invite-wrap {
    margin-bottom: 24px;
    max-width: 26rem;
  }

  .table-wrap {
    overflow-x: auto;
  }

  .actions {
    display: flex;
    gap: 8px;
    white-space: nowrap;
  }

  .btn-small {
    padding: 4px 12px;
    font-size: 12px;
  }
</style>
