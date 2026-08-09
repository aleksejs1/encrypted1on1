<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet } from '../api/client';
  import { ensureUnlocked } from '../crypto/identity';
  import InviteForm from '../admin/InviteForm.svelte';

  interface AnketaSummary {
    id: string;
    myRole: 'employee' | 'manager';
    counterpartEmail: string;
    meetingDate: string;
    myPublishedAt: string | null;
    counterpartPublishedAt: string | null;
    archivedAt: string | null;
    missed: boolean;
  }

  function isOverdue(anketa: AnketaSummary): boolean {
    return anketa.archivedAt === null && new Date(anketa.meetingDate).getTime() < Date.now();
  }

  const anketas = apiGet<AnketaSummary[]>('/api/anketas');

  let isAdmin = $state(false);
  let showInvite = $state(false);

  $effect(() => {
    ensureUnlocked().then((identity) => {
      isAdmin = identity.isAdmin;
      showInvite = identity.registrationMode === 'invite';
    });
  });
</script>

<main>
  <div class="header">
    <h1>{$_('anketaList.title')}</h1>
    <div class="header-links">
      {#if isAdmin}<a href="/admin">{$_('anketaList.admin')}</a>{/if}
      <a href="/report">{$_('anketaList.report')}</a>
      <a href="/anketas/new">{$_('anketaList.newAnketa')}</a>
    </div>
  </div>

  {#if showInvite}
    <section>
      <InviteForm />
    </section>
  {/if}

  {#await anketas}
    <p>{$_('common.loading')}</p>
  {:then list}
    {#if list.length === 0}
      <p>{$_('anketaList.empty')}</p>
    {:else}
      <ul>
        {#each list as anketa (anketa.id)}
          <li>
            <a href="/anketas/{anketa.id}">
              {anketa.counterpartEmail} ({$_(anketa.myRole === 'employee' ? 'common.roleEmployee' : 'common.roleManager')}) — {new Date(
                anketa.meetingDate,
              ).toLocaleDateString()}
              {#if anketa.archivedAt}<span class="badge">{$_('anketaList.badgeArchived')}</span>{/if}
              {#if anketa.missed}<span class="badge">{$_('anketaList.badgeMissed')}</span>{/if}
              {#if isOverdue(anketa)}<span class="badge overdue">{$_('anketaList.badgeOverdue')}</span>{/if}
              {#if anketa.myPublishedAt}<span class="badge">{$_('anketaList.badgePublishedByMe')}</span>{/if}
              {#if anketa.counterpartPublishedAt}<span class="badge"
                  >{$_('anketaList.badgePublishedByCounterpart')}</span
                >{/if}
            </a>
          </li>
        {/each}
      </ul>
    {/if}
  {:catch error}
    <p class="error">{error.message}</p>
  {/await}
</main>

<style>
  main {
    max-width: 40rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
  }

  .header-links {
    display: flex;
    gap: 1rem;
  }

  ul {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
  }

  li a {
    display: block;
    padding: 0.75rem;
    border: 1px solid #ddd;
    border-radius: 0.25rem;
    text-decoration: none;
    color: inherit;
  }

  .badge {
    margin-left: 0.5rem;
    font-size: 0.75rem;
    color: #6b6b6b;
  }

  .badge.overdue {
    color: #c0392b;
  }

  .error {
    color: #c0392b;
  }
</style>
