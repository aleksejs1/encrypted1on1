<script lang="ts">
  import { apiGet, apiPut, ApiError } from '../api/client';
  import { ensureUnlocked } from '../crypto/identity';
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
        loadError = error instanceof ApiError ? error.message : 'Could not load the admin panel.';
      });
  });

  async function toggleBlocked(user: AdminUser): Promise<void> {
    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiPut<{ isBlocked: boolean }>(`/api/admin/users/${user.id}/blocked`, {
        blocked: !user.isBlocked,
      });
      users = users.map((u) => (u.id === user.id ? { ...u, isBlocked: result.isBlocked } : u));
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not update the user.';
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }

  async function toggleAdmin(user: AdminUser): Promise<void> {
    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiPut<{ isAdmin: boolean }>(`/api/admin/users/${user.id}/admin`, {
        isAdmin: !user.isAdmin,
      });
      users = users.map((u) => (u.id === user.id ? { ...u, isAdmin: result.isAdmin } : u));
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not update the user.';
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }
</script>

<main>
  <h1>Admin panel</h1>

  {#if loadError}
    <p class="error">{loadError}</p>
  {:else if isAdmin === null}
    <p>Loading…</p>
  {:else if !isAdmin}
    <p>Not authorized.</p>
  {:else}
    <section>
      <InviteForm />
    </section>

    {#if actionError}
      <p class="error">{actionError}</p>
    {/if}

    <table>
      <thead>
        <tr>
          <th>Email</th>
          <th>Status</th>
          <th>Role</th>
          <th>Created</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each users as user (user.id)}
          <tr>
            <td>{user.email}</td>
            <td>{user.isBlocked ? 'Blocked' : 'Active'}</td>
            <td>{user.isAdmin ? 'Admin' : 'User'}</td>
            <td>{new Date(user.createdAt).toLocaleDateString()}</td>
            <td class="actions">
              <button
                type="button"
                onclick={() => toggleBlocked(user)}
                disabled={pending[user.id] || user.id === myUserId}
              >
                {user.isBlocked ? 'Unblock' : 'Block'}
              </button>
              <button type="button" onclick={() => toggleAdmin(user)} disabled={pending[user.id]}>
                {user.isAdmin ? 'Revoke admin' : 'Make admin'}
              </button>
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
  {/if}
</main>

<style>
  main {
    max-width: 48rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 1rem;
  }

  th,
  td {
    text-align: left;
    padding: 0.5rem;
    border-bottom: 1px solid #ddd;
  }

  .actions {
    display: flex;
    gap: 0.5rem;
  }

  .error {
    color: #c0392b;
  }
</style>
