<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, ApiError } from '../api/client';
  import { ensureUnlocked } from '../crypto/identity';
  import { formatDisplayDate } from '../datePreference.svelte';
  import InviteForm from './InviteForm.svelte';

  interface AdminUser {
    id: string;
    email: string;
    isAdmin: boolean;
    isBlocked: boolean;
    createdAt: string;
  }

  let myUserId = $state<string | null>(null);
  let isAdmin = $state<boolean | null>(null);
  let users = $state<AdminUser[]>([]);
  let loadError = $state<string | null>(null);
  let actionError = $state<string | null>(null);
  let pending = $state<Record<string, boolean>>({});

  $effect(() => {
    ensureUnlocked()
      .then((identity) => {
        myUserId = identity.userId;
        isAdmin = identity.isAdmin;
        if (!identity.isAdmin) return;
        return apiGet<AdminUser[]>('/api/admin/users').then((list) => {
          users = list;
        });
      })
      .catch((error: unknown) => {
        loadError =
          error instanceof ApiError ? error.message : $_('admin.errorLoad');
      });
  });

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
</script>

<main>
  <h1>{$_('admin.title')}</h1>

  {#if loadError}
    <p class="banner-error">{loadError}</p>
  {:else if isAdmin === null}
    <p class="text-muted">{$_('common.loading')}</p>
  {:else if !isAdmin}
    <p class="text-muted">{$_('admin.notAuthorized')}</p>
  {:else}
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
              <td>{user.email}</td>
              <td>
                <span
                  class="tag {user.isBlocked ? 'tag-neutral' : 'tag-accent-2'}"
                >
                  {user.isBlocked
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
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
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
