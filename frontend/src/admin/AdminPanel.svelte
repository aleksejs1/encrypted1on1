<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, apiDelete, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { ensureUnlocked, clearIdentity } from '../crypto/identity.svelte';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { nameWithEmail } from '../userDisplay';
  import {
    beginAction,
    findRow,
    ignoreHeldEnter,
    refocus,
  } from '../anketa/keepFocus';
  import { hasNoManager, managerChoices, type OrgUser } from './managerColumn';
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

  interface AdminUser extends OrgUser {
    isAdmin: boolean;
    createdAt: string;
  }

  let myUserId = $state<string | null>(null);
  let users = $state<AdminUser[]>([]);
  let panelDataError = $state<string | null>(null);
  let actionError = $state<string | null>(null);
  // Block, admin and delete: one at a time, see `busy` below.
  let rowActionInFlight = $state(false);

  let onlyNoManager = $state(false);
  let noManagerFilter = $state<HTMLInputElement | null>(null);
  const usersById = $derived(new Map(users.map((u) => [u.id, u])));
  const usersWithoutManager = $derived(
    users.filter((u) => hasNoManager(u, usersById)),
  );
  const shownUsers = $derived(onlyNoManager ? usersWithoutManager : users);
  let tableBody = $state<HTMLElement>();

  // One row's manager is edited at a time, with an explicit Save: a select that
  // saved on change would write on every arrow-key press where a closed select
  // changes value per keystroke.
  //
  // One thing at a time, so nothing has to be reconciled: while an editor is open
  // or a row action is in flight, every other row action, every "Change" and the filter
  // are disabled (`busy` below). The picked manager can't be
  // blocked or deleted under the editor, the edited row can't be deleted under its
  // own save, and the filter can't hide a row that is being edited.
  let managerEdit = $state<{
    userId: string;
    managerId: string;
    saving: boolean;
    error: string | null;
  } | null>(null);
  const editorOpen = $derived(managerEdit !== null);
  // What every row action, every "Change" and the filter are disabled by.
  const busy = $derived(editorOpen || rowActionInFlight);

  let registrationMode = $state<RegistrationMode>('invite');
  let allowedEmailDomain = $state('');
  let settingsSaving = $state(false);
  let settingsSaved = $state(false);
  let settingsError = $state<string | null>(null);

  // Cancels loadPanelData()'s user-list fetch below on unmount — see GitHub
  // issue #95. Not extended to toggleBlocked/toggleAdmin/deletePermanently/
  // saveInviteSettings below: those are writes a user explicitly started via
  // a button click, which should finish regardless of navigation, same as
  // AnketaList.svelte's reshareAll (see abortOnDestroy's own docblock).
  const readAbort = abortOnDestroy();

  /** AdminGate has already confirmed isAdmin — ensureUnlocked() is memoized (see its own docblock), so calling it again here to get the rest of the identity is cheap, not a second real fetch. */
  async function loadPanelData(): Promise<void> {
    try {
      const identity = await ensureUnlocked();
      myUserId = identity.userId;
      registrationMode = identity.registrationMode;
      allowedEmailDomain = identity.allowedEmailDomain;
      users = await apiGet<AdminUser[]>('/api/admin/users', {
        signal: readAbort,
      });
    } catch (error) {
      if (isAbortError(error)) return;
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
    rowActionInFlight = true;
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
      rowActionInFlight = false;
    }
  }

  async function toggleAdmin(user: AdminUser): Promise<void> {
    rowActionInFlight = true;
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
      rowActionInFlight = false;
    }
  }

  function managerLabel(manager: OrgUser): string {
    const name = nameWithEmail(manager.displayName, manager.email);
    // Deleted first: a deleted account is blocked too.
    if (manager.deletedAt)
      return $_('admin.managerDeleted', { values: { name } });
    return manager.isBlocked
      ? $_('admin.managerBlocked', { values: { name } })
      : name;
  }

  const userRow = (userId: string) =>
    findRow(tableBody, 'data-user-id', userId);

  function startManagerEdit(user: AdminUser): void {
    managerEdit = {
      userId: user.id,
      managerId: user.managerId ?? '',
      saving: false,
      error: null,
    };
    void refocus(userRow(user.id), '[data-manager-select]');
  }

  function cancelManagerEdit(): void {
    if (!managerEdit) return;
    const { userId } = managerEdit;
    managerEdit = null;
    void refocus(userRow(userId), '[data-manager-change]');
  }

  /**
   * The server decides (GitHub issue #267): a refused assignment, a cycle for one,
   * leaves the row in edit mode with the server's message under the select.
   * Focus follows only if the admin hasn't gone elsewhere meanwhile (keepFocus.ts).
   */
  async function saveManagerEdit(): Promise<void> {
    if (!managerEdit || managerEdit.saving) return;
    const edit = managerEdit;
    const row = userRow(edit.userId);
    const managerId = edit.managerId === '' ? null : edit.managerId;
    const startedOn = beginAction();
    managerEdit = { ...edit, saving: true, error: null };
    actionError = null;
    try {
      const result = await apiPut<{ managerId: string | null }>(
        `/api/admin/users/${edit.userId}/manager`,
        { managerId },
      );
      users = users.map((u) =>
        u.id === edit.userId ? { ...u, managerId: result.managerId } : u,
      );
      managerEdit = null;
      // With the filter on, a row that got a manager is gone: the filter takes focus.
      void refocus(row, '[data-manager-change]', {
        startedOn,
        onRootGone: () => noManagerFilter?.focus(),
      });
    } catch (error) {
      managerEdit = {
        ...edit,
        saving: false,
        error:
          error instanceof ApiError ? error.message : $_('admin.errorUpdate'),
      };
      void refocus(row, '[data-manager-select]', { startedOn });
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

    const row = userRow(user.id);
    const startedOn = beginAction();
    rowActionInFlight = true;
    actionError = null;
    try {
      const result = await apiDelete<{
        email: string;
        displayName: string;
        deletedAt: string;
      }>(`/api/admin/users/${user.id}`, null);
      // The server cleared the deleted account's reporting lines both ways
      // (AccountDeleter); the same here, so the column and the filter are right
      // without a reload.
      users = users.map((u) =>
        u.id === user.id
          ? {
              ...u,
              email: result.email,
              displayName: result.displayName,
              deletedAt: result.deletedAt,
              managerId: null,
            }
          : u.managerId === user.id
            ? { ...u, managerId: null }
            : u,
      );
      // A deleted row has nothing left to focus; one the filter has just dropped
      // (a deleted account is never "without a manager") hands focus to the filter.
      void refocus(row, '[data-manager-change]', {
        startedOn,
        onRootGone: () => noManagerFilter?.focus(),
      });
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('admin.errorDelete');
    } finally {
      rowActionInFlight = false;
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
        <p class="banner-error" role="alert">
          {actionError}
        </p>
      {/if}

      <label class="no-manager-filter">
        <input
          type="checkbox"
          bind:checked={onlyNoManager}
          bind:this={noManagerFilter}
          disabled={busy}
        />
        {$_('admin.noManagerFilter', {
          values: { count: usersWithoutManager.length },
        })}
      </label>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>{$_('admin.nameHeader')}</th>
              <th>{$_('admin.emailHeader')}</th>
              <th>{$_('admin.statusHeader')}</th>
              <th>{$_('admin.roleHeader')}</th>
              <th>{$_('admin.managerHeader')}</th>
              <th>{$_('admin.createdHeader')}</th>
              <th></th>
            </tr>
          </thead>
          <tbody bind:this={tableBody} onkeydowncapture={ignoreHeldEnter}>
            {#each shownUsers as user (user.id)}
              <tr data-user-id={user.id}>
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
                <td>
                  {#if managerEdit?.userId === user.id}
                    {@const edit = managerEdit}
                    <div class="manager-edit">
                      <!-- Not narrowed to "who wouldn't make a cycle": the server
                           answers for that (see saveManagerEdit()). -->
                      <select
                        class="input manager-select"
                        data-manager-select
                        aria-label={$_('admin.managerSelectLabel', {
                          values: {
                            name: nameWithEmail(user.displayName, user.email),
                          },
                        })}
                        bind:value={managerEdit.managerId}
                        onchange={() => {
                          // The refusal was about the option picked before.
                          if (managerEdit) managerEdit.error = null;
                        }}
                        disabled={edit.saving}
                      >
                        <option value="">{$_('admin.managerNone')}</option>
                        {#each managerChoices(users, user) as choice (choice.id)}
                          <option value={choice.id}
                            >{managerLabel(choice)}</option
                          >
                        {/each}
                      </select>
                      <button
                        type="button"
                        class="btn btn-primary btn-small"
                        onclick={saveManagerEdit}
                        disabled={edit.saving}
                      >
                        {edit.saving ? $_('common.saving') : $_('common.save')}
                      </button>
                      <button
                        type="button"
                        class="btn btn-secondary btn-small"
                        onclick={cancelManagerEdit}
                        disabled={edit.saving}
                      >
                        {$_('common.cancel')}
                      </button>
                    </div>
                    {#if edit.error}
                      <p class="banner-error manager-error" role="alert">
                        {edit.error}
                      </p>
                    {/if}
                  {:else}
                    {@const manager = user.managerId
                      ? usersById.get(user.managerId)
                      : undefined}
                    <div class="manager-shown">
                      <span>{manager ? managerLabel(manager) : '—'}</span>
                      {#if !user.deletedAt}
                        <button
                          type="button"
                          class="btn btn-secondary btn-small"
                          data-manager-change
                          aria-label={$_('admin.managerChangeLabel', {
                            values: {
                              name: nameWithEmail(user.displayName, user.email),
                            },
                          })}
                          onclick={() => startManagerEdit(user)}
                          disabled={busy}
                        >
                          {$_('admin.managerChange')}
                        </button>
                      {/if}
                    </div>
                  {/if}
                </td>
                <td>{formatDisplayDate(user.createdAt)}</td>
                <td class="actions">
                  {#if !user.deletedAt}
                    <button
                      type="button"
                      class="btn btn-secondary btn-small"
                      onclick={() => toggleBlocked(user)}
                      disabled={busy || user.id === myUserId}
                    >
                      {user.isBlocked ? $_('admin.unblock') : $_('admin.block')}
                    </button>
                    <button
                      type="button"
                      class="btn btn-secondary btn-small"
                      onclick={() => toggleAdmin(user)}
                      disabled={busy}
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
                        disabled={busy || user.id === myUserId}
                      >
                        {$_('admin.deletePermanently')}
                      </button>
                    {/if}
                  {/if}
                </td>
              </tr>
            {/each}
            {#if onlyNoManager && shownUsers.length === 0}
              <tr>
                <td colspan="7" class="text-muted">
                  {$_('admin.noManagerEmpty')}
                </td>
              </tr>
            {/if}
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

  .no-manager-filter {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
  }

  .manager-shown,
  .manager-edit {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .manager-select {
    min-width: 10rem;
    max-width: 16rem;
    padding: 4px 8px;
    font-size: 13px;
  }

  .manager-error {
    margin: 8px 0 0;
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
