<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, apiDelete, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { fullDisplayName, nameWithEmail } from '../userDisplay';
  import {
    beginAction,
    fallbackFocusOptions,
    findRow,
    ignoreHeldEnter,
    refocus,
    type RefocusOptions,
  } from '../anketa/keepFocus';
  import {
    accountState,
    hasNoManager,
    listedUsers,
    managerChoices,
    type OrgUser,
  } from './managerColumn';
  import { PATHS } from '../routes';
  import OrgImportCard from './OrgImportCard.svelte';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';

  interface AdminUser extends OrgUser {
    isAdmin: boolean;
    createdAt: string;
  }

  let myUserId = $state<string | null>(null);
  let users = $state<AdminUser[]>([]);
  let panelDataError = $state<string | null>(null);
  // Only for reloadUsers(), and cleared only by the next one: until then the table
  // may be stale. A row action's error is shown in its own panel.
  let reloadError = $state<string | null>(null);

  let onlyNoManager = $state(false);
  // Both filters, found by `data-filter` when one has to take focus.
  let filters = $state<HTMLElement>();
  // Deleted accounts are out of the table until asked for (GitHub issue #280):
  // nothing can be done to one.
  let showDeleted = $state(false);
  const usersById = $derived(new Map(users.map((u) => [u.id, u])));
  const usersWithoutManager = $derived(
    users.filter((u) => hasNoManager(u, usersById)),
  );
  const deletedCount = $derived(users.filter((u) => u.deletedAt).length);
  const shownUsers = $derived(
    onlyNoManager ? usersWithoutManager : listedUsers(users, showDeleted),
  );
  let table = $state<HTMLElement>();

  // At most one row is open, in one mode (GitHub issue #280): its manager editor,
  // or its actions panel (block, admin, delete, under the row).
  //
  // The manager is edited with an explicit Save: a select that saved on change
  // would write on every arrow-key press where a closed select changes value per
  // keystroke.
  //
  // One thing at a time, so nothing has to be reconciled: while an editor is open
  // or a panel's request is in flight, every other row's "Change" and "Actions" and
  // both filters are disabled (`busy` below). The picked manager can't be blocked
  // or deleted under the editor, the edited row can't be deleted under its own
  // save, and a filter can't hide a row that is being edited. A panel that is
  // only open blocks nothing in the table, and closes when anything else opens.
  type OpenRow =
    | {
        kind: 'manager';
        userId: string;
        managerId: string;
        saving: boolean;
        error: string | null;
      }
    | {
        kind: 'actions';
        userId: string;
        pending: boolean;
        error: string | null;
      }
    | null;
  let openRow = $state<OpenRow>(null);
  // An open editor, or a panel's request in flight.
  const rowBusy = $derived(
    openRow !== null && (openRow.kind === 'manager' || openRow.pending),
  );
  // The import card's request, which may rewrite any row's manager.
  let importInFlight = $state(false);
  // What every "Change", every "Actions", every panel button and the filters are
  // disabled by. The import card in turn is disabled by `rowBusy`.
  const busy = $derived(rowBusy || importInFlight);

  // Cancels loadPanelData()'s user-list fetch below on unmount — see GitHub
  // issue #95. Not extended to toggleBlocked/toggleAdmin/deletePermanently
  // below: those are writes a user explicitly started via
  // a button click, which should finish regardless of navigation, same as
  // AnketaList.svelte's reshareAll (see abortOnDestroy's own docblock).
  const readAbort = abortOnDestroy();

  /** AdminGate has already confirmed isAdmin — ensureUnlocked() is memoized (see its own docblock), so calling it again here to get the rest of the identity is cheap, not a second real fetch. */
  async function loadPanelData(): Promise<void> {
    try {
      const identity = await ensureUnlocked();
      myUserId = identity.userId;
      users = await apiGet<AdminUser[]>('/api/admin/users', {
        signal: readAbort,
      });
    } catch (error) {
      if (isAbortError(error)) return;
      panelDataError =
        error instanceof ApiError ? error.message : $_('admin.errorLoad');
    }
  }

  /**
   * After an import, which answers with counts, not with the rows it changed. No
   * abort signal: the import was a write the admin started.
   */
  async function reloadUsers(): Promise<void> {
    reloadError = null;
    try {
      users = await apiGet<AdminUser[]>('/api/admin/users');
    } catch (error) {
      reloadError =
        error instanceof ApiError ? error.message : $_('admin.errorLoad');
    }
  }

  /**
   * One request of the open actions panel; `request` puts the answer into `users`.
   * An error stays in the panel, next to the button that caused it. Focus returns
   * to that button (its label may have changed) only if the admin hasn't gone
   * elsewhere meanwhile (keepFocus.ts).
   */
  async function runRowAction(
    user: AdminUser,
    action: 'blocked' | 'admin' | 'delete',
    request: () => Promise<void>,
    focusOptions?: FocusOptions,
  ): Promise<void> {
    if (busy || openRow?.kind !== 'actions' || openRow.userId !== user.id) {
      return;
    }
    const row = userRow(user.id);
    const startedOn = beginAction();
    openRow = { kind: 'actions', userId: user.id, pending: true, error: null };
    let error: string | null = null;
    try {
      await request();
    } catch (caught) {
      error =
        caught instanceof ApiError
          ? caught.message
          : $_(action === 'delete' ? 'admin.errorDelete' : 'admin.errorUpdate');
    }
    if (action === 'delete' && error === null) {
      // A deleted account has no panel and no buttons, whether its row is still
      // shown or not, so focus goes to the filter that decides that.
      openRow = null;
      focusFilter(onlyNoManager ? 'noManager' : 'deleted', {
        startedOn,
        focusOptions,
      });
      return;
    }
    openRow = { kind: 'actions', userId: user.id, pending: false, error };
    void refocus(row, `[data-row-action="${action}"]`, { startedOn });
  }

  function toggleBlocked(user: AdminUser): Promise<void> {
    return runRowAction(user, 'blocked', async () => {
      const result = await apiPut<{ isBlocked: boolean }>(
        `/api/admin/users/${user.id}/blocked`,
        { blocked: !user.isBlocked },
      );
      users = users.map((u) =>
        u.id === user.id ? { ...u, isBlocked: result.isBlocked } : u,
      );
    });
  }

  function toggleAdmin(user: AdminUser): Promise<void> {
    return runRowAction(user, 'admin', async () => {
      const result = await apiPut<{ isAdmin: boolean }>(
        `/api/admin/users/${user.id}/admin`,
        { isAdmin: !user.isAdmin },
      );
      users = users.map((u) =>
        u.id === user.id ? { ...u, isAdmin: result.isAdmin } : u,
      );
    });
  }

  /** One line, for the select's options; the cells use the `person` snippet. */
  function managerLabel(manager: OrgUser): string {
    const state = accountState(manager);
    // Its name and address are placeholders.
    if (state === 'deleted') return $_('admin.deletedAccount');
    const name = nameWithEmail(manager.displayName, manager.email);
    return state === 'blocked'
      ? $_('admin.managerBlocked', { values: { name } })
      : name;
  }

  /** Focus for a row that has left the table, or has nothing left to focus. */
  function focusFilter(
    name: 'noManager' | 'deleted',
    options?: RefocusOptions,
  ): void {
    void refocus(filters, `[data-filter="${name}"]`, options);
  }

  /** A person's <tbody>: their row and, when open, their actions panel under it. */
  const userRow = (userId: string) => findRow(table, 'data-user-id', userId);

  /** Opens the row's actions panel, or closes it if it's the open one. */
  function toggleActions(user: AdminUser): void {
    if (busy) return;
    if (openRow?.userId === user.id) {
      openRow = null;
      void refocus(userRow(user.id), '[data-actions-toggle]');
      return;
    }
    // Focus stays on the toggle: the panel's first button acts at once, with no
    // confirmation, so a second Enter must not reach it.
    openRow = { kind: 'actions', userId: user.id, pending: false, error: null };
  }

  /**
   * Closes an actions panel that is only open. Also when the import card sends a
   * request: the admin has moved on to the card, and the panel's buttons are
   * disabled for as long as the request runs.
   */
  function closeActions(): void {
    if (openRow?.kind === 'actions' && !openRow.pending) openRow = null;
  }

  function startManagerEdit(user: AdminUser): void {
    if (busy) return;
    openRow = {
      kind: 'manager',
      userId: user.id,
      managerId: user.managerId ?? '',
      saving: false,
      error: null,
    };
    void refocus(userRow(user.id), '[data-manager-select]');
  }

  function cancelManagerEdit(): void {
    if (openRow?.kind !== 'manager' || openRow.saving) return;
    const { userId } = openRow;
    openRow = null;
    void refocus(userRow(userId), '[data-manager-change]');
  }

  /**
   * The server decides (GitHub issue #267): a refused assignment, a cycle for one,
   * leaves the row in edit mode with the server's message under the select.
   * Focus follows only if the admin hasn't gone elsewhere meanwhile (keepFocus.ts).
   */
  async function saveManagerEdit(): Promise<void> {
    if (openRow?.kind !== 'manager' || openRow.saving) return;
    const edit = openRow;
    const row = userRow(edit.userId);
    const managerId = edit.managerId === '' ? null : edit.managerId;
    const startedOn = beginAction();
    openRow = { ...edit, saving: true, error: null };
    try {
      const result = await apiPut<{ managerId: string | null }>(
        `/api/admin/users/${edit.userId}/manager`,
        { managerId },
      );
      users = users.map((u) =>
        u.id === edit.userId ? { ...u, managerId: result.managerId } : u,
      );
      openRow = null;
      // With the filter on, a row that got a manager is gone: the filter takes focus.
      void refocus(row, '[data-manager-change]', {
        startedOn,
        onRootGone: () => focusFilter('noManager'),
      });
    } catch (error) {
      openRow = {
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
  async function deletePermanently(
    user: AdminUser,
    click: MouseEvent,
  ): Promise<void> {
    const typed = prompt(
      $_('admin.deleteConfirmPrompt', { values: { email: user.email } }),
    );
    if (typed !== user.email) return;

    await runRowAction(
      user,
      'delete',
      async () => {
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
      },
      // The filter that takes focus may be far above a row deleted with the mouse.
      fallbackFocusOptions(click),
    );
  }
</script>

<!-- A name with the email under it; the email alone when no name is set. A
     deleted account's name and address are placeholders, and aren't shown. -->
{#snippet person(account: OrgUser, markBlocked = false)}
  {@const state = accountState(account)}
  {#if state === 'deleted'}
    <span class="text-muted">{$_('admin.deletedAccount')}</span>
  {:else}
    {@const name = fullDisplayName(account.displayName, account.email)}
    <div class="person">
      <span
        >{markBlocked && state === 'blocked'
          ? $_('admin.managerBlocked', { values: { name } })
          : name}</span
      >
      {#if name !== account.email}
        <span class="text-muted person-email">{account.email}</span>
      {/if}
    </div>
  {/if}
{/snippet}

<main>
  <h1>{$_('admin.title')}</h1>

  <AdminGate onReady={loadPanelData}>
    <AdminTabStrip active="users" />

    {#if panelDataError}
      <p class="banner-error">{panelDataError}</p>
    {:else}
      <OrgImportCard
        {users}
        disabled={rowBusy || users.length === 0}
        bind:inFlight={importInFlight}
        onStart={closeActions}
        onApplied={reloadUsers}
      />

      {#if reloadError}
        <p class="banner-error" role="alert">
          {reloadError}
        </p>
      {/if}

      <div class="filters" bind:this={filters}>
        <label class="filter">
          <input
            type="checkbox"
            bind:checked={onlyNoManager}
            data-filter="noManager"
            onchange={() => {
              if (!onlyNoManager) return;
              // No panel stays open on a row the filter has just taken out.
              if (!usersWithoutManager.some((u) => u.id === openRow?.userId)) {
                closeActions();
              }
              // It never lists a deleted account: the other filter is off under it.
              showDeleted = false;
            }}
            disabled={busy}
          />
          {$_('admin.noManagerFilter', {
            values: { count: usersWithoutManager.length },
          })}
        </label>
        {#if deletedCount > 0}
          <label class="filter">
            <input
              type="checkbox"
              bind:checked={showDeleted}
              data-filter="deleted"
              disabled={busy || onlyNoManager}
            />
            {$_('admin.showDeleted', { values: { count: deletedCount } })}
          </label>
        {/if}
        <!-- The invite form and its settings are on the Invites tab (GitHub issue #280). -->
        <a class="invite-link" href={PATHS.adminInvites}
          >{$_('admin.inviteLink')}</a
        >
      </div>

      <div class="table-wrap">
        <table
          class="table users"
          bind:this={table}
          onkeydowncapture={ignoreHeldEnter}
        >
          <thead>
            <tr>
              <th>{$_('admin.personHeader')}</th>
              <th>{$_('admin.statusHeader')}</th>
              <th>{$_('admin.managerHeader')}</th>
              <th><span class="sr-only">{$_('admin.actions')}</span></th>
            </tr>
          </thead>
          {#each shownUsers as user (user.id)}
            {@const label = nameWithEmail(user.displayName, user.email)}
            {@const state = accountState(user)}
            {@const actions =
              openRow?.kind === 'actions' && openRow.userId === user.id
                ? openRow
                : null}
            <!-- One <tbody> per person, so the row and its panel are one root for
                 refocus() (keepFocus.ts). -->
            <tbody data-user-id={user.id} class:open={actions !== null}>
              <tr>
                <td>{@render person(user)}</td>
                <td>
                  <div class="tags">
                    <span
                      class="tag {state === 'active'
                        ? 'tag-accent-2'
                        : 'tag-neutral'}"
                    >
                      {state === 'deleted'
                        ? $_('admin.statusDeleted')
                        : state === 'blocked'
                          ? $_('admin.statusBlocked')
                          : $_('admin.statusActive')}
                    </span>
                    {#if user.isAdmin && state !== 'deleted'}
                      <span class="tag tag-outline"
                        >{$_('admin.roleAdmin')}</span
                      >
                    {/if}
                  </div>
                </td>
                <td>
                  {#if openRow?.kind === 'manager' && openRow.userId === user.id}
                    {@const edit = openRow}
                    <div class="manager-edit">
                      <!-- Not narrowed to "who wouldn't make a cycle": the server
                           answers for that (see saveManagerEdit()). -->
                      <select
                        class="input manager-select"
                        data-manager-select
                        aria-label={$_('admin.managerSelectLabel', {
                          values: { name: label },
                        })}
                        bind:value={openRow.managerId}
                        onchange={() => {
                          // The refusal was about the option picked before.
                          if (openRow?.kind === 'manager') openRow.error = null;
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
                      <p class="banner-error row-error" role="alert">
                        {edit.error}
                      </p>
                    {/if}
                  {:else}
                    {@const manager = user.managerId
                      ? usersById.get(user.managerId)
                      : undefined}
                    <div class="manager-shown">
                      {#if manager}
                        {@render person(manager, true)}
                      {:else}
                        <span>—</span>
                      {/if}
                      {#if !user.deletedAt}
                        <button
                          type="button"
                          class="btn btn-secondary btn-small"
                          data-manager-change
                          aria-label={$_('admin.managerChangeLabel', {
                            values: { name: label },
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
                <td class="actions-cell">
                  {#if !user.deletedAt}
                    <button
                      type="button"
                      class="btn btn-secondary btn-small"
                      data-actions-toggle
                      aria-expanded={actions !== null}
                      aria-controls={actions ? `actions-${user.id}` : undefined}
                      aria-label={$_('admin.actionsLabel', {
                        values: { name: label },
                      })}
                      onclick={() => toggleActions(user)}
                      disabled={busy}
                    >
                      {$_('admin.actions')}
                      <span aria-hidden="true">{actions ? '▴' : '▾'}</span>
                    </button>
                  {/if}
                </td>
              </tr>
              {#if actions}
                <tr>
                  <td colspan="4">
                    <div
                      class="actions-panel"
                      id="actions-{user.id}"
                      role="group"
                      aria-label={$_('admin.actionsLabel', {
                        values: { name: label },
                      })}
                    >
                      <span class="text-muted">
                        {$_('admin.createdOn', {
                          values: { date: formatDisplayDate(user.createdAt) },
                        })}
                      </span>
                      <button
                        type="button"
                        class="btn btn-secondary btn-small"
                        data-row-action="blocked"
                        onclick={() => toggleBlocked(user)}
                        disabled={busy || user.id === myUserId}
                      >
                        {user.isBlocked
                          ? $_('admin.unblock')
                          : $_('admin.block')}
                      </button>
                      <button
                        type="button"
                        class="btn btn-secondary btn-small"
                        data-row-action="admin"
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
                          data-row-action="delete"
                          onclick={(click) => deletePermanently(user, click)}
                          disabled={busy || user.id === myUserId}
                        >
                          {$_('admin.deletePermanently')}
                        </button>
                      {/if}
                    </div>
                    {#if actions.error}
                      <p class="banner-error row-error" role="alert">
                        {actions.error}
                      </p>
                    {/if}
                  </td>
                </tr>
              {/if}
            </tbody>
          {/each}
          {#if onlyNoManager && shownUsers.length === 0}
            <tbody>
              <tr>
                <td colspan="4" class="text-muted">
                  {$_('admin.noManagerEmpty')}
                </td>
              </tr>
            </tbody>
          {/if}
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

  .table-wrap {
    overflow-x: auto;
  }

  .filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 24px;
    margin-bottom: 12px;
  }

  .invite-link {
    margin-left: auto;
  }

  .filter {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  /* A person's row and their actions panel are one <tbody>, highlighted together. */
  .users tbody tr:hover {
    background: none;
  }

  .users tbody[data-user-id]:hover {
    background: color-mix(in srgb, var(--color-text) 4%, transparent);
  }

  .users tbody.open tr:first-child td {
    border-bottom: none;
  }

  .users td {
    vertical-align: middle;
  }

  .person {
    display: flex;
    flex-direction: column;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .person-email {
    font-size: 13px;
  }

  .tags {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
  }

  .manager-shown,
  .manager-edit {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .manager-shown {
    justify-content: space-between;
  }

  /* The select on one line, Save and Cancel under it: the editor stays inside its column. */
  .manager-edit {
    flex-wrap: wrap;
  }

  .manager-select {
    flex: 1 1 100%;
    min-width: 10rem;
    max-width: 16rem;
    padding: 4px 8px;
    font-size: 13px;
  }

  .row-error {
    margin: 8px 0 0;
  }

  .actions-cell {
    text-align: right;
  }

  .actions-panel {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding-bottom: 4px;
  }

  .btn-small {
    padding: 4px 12px;
    font-size: 12px;
    white-space: nowrap;
  }
</style>
