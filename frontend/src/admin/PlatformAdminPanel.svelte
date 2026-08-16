<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPut, ApiError } from '../api/client';
  import { ensureUnlocked } from '../crypto/identity';
  import { formatDisplayDate } from '../datePreference.svelte';

  interface PlatformCompany {
    id: string;
    name: string;
    registrationMode: string;
    allowedEmailDomain: string;
    createdAt: string;
    userCount: number;
  }

  interface PlatformUser {
    id: string;
    email: string;
    companyId: string;
    companyName: string;
    isAdmin: boolean;
    isPlatformAdmin: boolean;
    isBlocked: boolean;
    createdAt: string;
  }

  let myUserId = $state<string | null>(null);
  let isPlatformAdmin = $state<boolean | null>(null);
  let companies = $state<PlatformCompany[]>([]);
  let users = $state<PlatformUser[]>([]);
  let loadError = $state<string | null>(null);
  let actionError = $state<string | null>(null);
  let pending = $state<Record<string, boolean>>({});

  $effect(() => {
    ensureUnlocked()
      .then((identity) => {
        myUserId = identity.userId;
        isPlatformAdmin = identity.isPlatformAdmin;
        if (!identity.isPlatformAdmin) return;
        return Promise.all([
          apiGet<PlatformCompany[]>('/api/platform-admin/companies').then(
            (list) => {
              companies = list;
            },
          ),
          apiGet<PlatformUser[]>('/api/platform-admin/users').then((list) => {
            users = list;
          }),
        ]);
      })
      .catch((error: unknown) => {
        loadError =
          error instanceof ApiError
            ? error.message
            : $_('platformAdmin.errorLoad');
      });
  });

  async function toggleBlocked(user: PlatformUser): Promise<void> {
    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiPut<{ isBlocked: boolean }>(
        `/api/platform-admin/users/${user.id}/blocked`,
        { blocked: !user.isBlocked },
      );
      users = users.map((u) =>
        u.id === user.id ? { ...u, isBlocked: result.isBlocked } : u,
      );
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('platformAdmin.errorUpdate');
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }

  async function togglePlatformAdmin(user: PlatformUser): Promise<void> {
    pending = { ...pending, [user.id]: true };
    actionError = null;
    try {
      const result = await apiPut<{ isPlatformAdmin: boolean }>(
        `/api/platform-admin/users/${user.id}/platform-admin`,
        { isPlatformAdmin: !user.isPlatformAdmin },
      );
      users = users.map((u) =>
        u.id === user.id
          ? { ...u, isPlatformAdmin: result.isPlatformAdmin }
          : u,
      );
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('platformAdmin.errorUpdate');
    } finally {
      pending = { ...pending, [user.id]: false };
    }
  }
</script>

<main>
  <h1>{$_('platformAdmin.title')}</h1>

  {#if loadError}
    <p class="banner-error">{loadError}</p>
  {:else if isPlatformAdmin === null}
    <p class="text-muted">{$_('common.loading')}</p>
  {:else if !isPlatformAdmin}
    <p class="text-muted">{$_('platformAdmin.notAuthorized')}</p>
  {:else}
    {#if actionError}
      <p class="banner-error">{actionError}</p>
    {/if}

    <h2>{$_('platformAdmin.companiesHeading')}</h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>{$_('platformAdmin.companyNameHeader')}</th>
            <th>{$_('platformAdmin.companyModeHeader')}</th>
            <th>{$_('platformAdmin.companyUsersHeader')}</th>
            <th>{$_('platformAdmin.createdHeader')}</th>
          </tr>
        </thead>
        <tbody>
          {#each companies as company (company.id)}
            <tr>
              <td>{company.name}</td>
              <td>{company.registrationMode}</td>
              <td>{company.userCount}</td>
              <td>{formatDisplayDate(company.createdAt)}</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>

    <h2>{$_('platformAdmin.usersHeading')}</h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>{$_('platformAdmin.emailHeader')}</th>
            <th>{$_('platformAdmin.companyHeader')}</th>
            <th>{$_('platformAdmin.statusHeader')}</th>
            <th>{$_('platformAdmin.roleHeader')}</th>
            <th>{$_('platformAdmin.createdHeader')}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {#each users as user (user.id)}
            <tr>
              <td>{user.email}</td>
              <td>{user.companyName}</td>
              <td>
                <span
                  class="tag {user.isBlocked ? 'tag-neutral' : 'tag-accent-2'}"
                >
                  {user.isBlocked
                    ? $_('platformAdmin.statusBlocked')
                    : $_('platformAdmin.statusActive')}
                </span>
              </td>
              <td>
                {#if user.isPlatformAdmin}
                  {$_('platformAdmin.rolePlatformAdmin')}
                {:else if user.isAdmin}
                  {$_('platformAdmin.roleCompanyAdmin')}
                {:else}
                  {$_('platformAdmin.roleUser')}
                {/if}
              </td>
              <td>{formatDisplayDate(user.createdAt)}</td>
              <td class="actions">
                <button
                  type="button"
                  class="btn btn-secondary btn-small"
                  onclick={() => toggleBlocked(user)}
                  disabled={pending[user.id] || user.id === myUserId}
                >
                  {user.isBlocked
                    ? $_('platformAdmin.unblock')
                    : $_('platformAdmin.block')}
                </button>
                <button
                  type="button"
                  class="btn btn-secondary btn-small"
                  onclick={() => togglePlatformAdmin(user)}
                  disabled={pending[user.id]}
                >
                  {user.isPlatformAdmin
                    ? $_('platformAdmin.revokePlatformAdmin')
                    : $_('platformAdmin.makePlatformAdmin')}
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
    max-width: 64rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  h2 {
    font-size: 18px;
    margin: 28px 0 12px;
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
