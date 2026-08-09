<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet } from '../api/client';
  import { ensureUnlocked } from '../crypto/identity';
  import InviteForm from '../admin/InviteForm.svelte';
  import { groupByCounterpart } from '../anketa/groupByCounterpart';

  interface AnketaSummary {
    id: string;
    myRole: 'employee' | 'manager';
    counterpartId: string;
    counterpartEmail: string;
    meetingDate: string;
    myPublishedAt: string | null;
    counterpartPublishedAt: string | null;
    archivedAt: string | null;
    missed: boolean;
  }

  interface BadgeMeta {
    cls: string;
    label: string;
  }

  function isOverdue(anketa: AnketaSummary): boolean {
    return anketa.archivedAt === null && new Date(anketa.meetingDate).getTime() < Date.now();
  }

  function initials(email: string): string {
    return email.slice(0, 2).toUpperCase();
  }

  function badgesFor(anketa: AnketaSummary): BadgeMeta[] {
    const list: BadgeMeta[] = [];
    if (isOverdue(anketa)) list.push({ cls: 'tag-outline', label: $_('anketaList.badgeOverdue') });
    if (anketa.archivedAt) list.push({ cls: 'tag-neutral', label: $_('anketaList.badgeArchived') });
    if (anketa.missed) list.push({ cls: 'tag-neutral', label: $_('anketaList.badgeMissed') });
    if (anketa.myPublishedAt) list.push({ cls: 'tag-accent', label: $_('anketaList.badgePublishedByMe') });
    if (anketa.counterpartPublishedAt) {
      list.push({ cls: 'tag-accent-2', label: $_('anketaList.badgePublishedByCounterpart') });
    }
    return list;
  }

  const anketas = apiGet<AnketaSummary[]>('/api/anketas');

  let showInvite = $state(false);
  let groupBy = $state<'date' | 'counterpart'>('date');

  $effect(() => {
    ensureUnlocked().then((identity) => {
      showInvite = identity.registrationMode === 'invite';
    });
  });
</script>

<main>
  <h1>{$_('anketaList.title')}</h1>

  {#if showInvite}
    <div class="invite-wrap">
      <InviteForm />
    </div>
  {/if}

  {#snippet anketaRow(anketa: AnketaSummary)}
    <a href="/anketas/{anketa.id}" class="card elev-sm anketa-row">
      <div class="avatar">{initials(anketa.counterpartEmail)}</div>
      <div class="anketa-info">
        <div class="anketa-email">{anketa.counterpartEmail}</div>
        <div class="text-muted anketa-meta">
          {$_(anketa.myRole === 'employee' ? 'common.roleEmployee' : 'common.roleManager')} —
          {new Date(anketa.meetingDate).toLocaleDateString()}
        </div>
      </div>
      <div class="badges">
        {#each badgesFor(anketa) as badge (badge.cls + badge.label)}
          <span class="tag {badge.cls}">{badge.label}</span>
        {/each}
      </div>
    </a>
  {/snippet}

  {#await anketas}
    <p class="text-muted">{$_('common.loading')}</p>
  {:then list}
    {#if list.length === 0}
      <div class="card elev-sm empty-state">
        <p class="text-muted">{$_('anketaList.empty')}</p>
        <a href="/anketas/new" class="btn btn-primary">{$_('anketaList.newAnketa')}</a>
      </div>
    {:else}
      <div class="seg view-toggle">
        <label class="seg-opt">
          <input type="radio" name="view" bind:group={groupBy} value="date" />
          {$_('anketaList.viewToggleDate')}
        </label>
        <label class="seg-opt">
          <input type="radio" name="view" bind:group={groupBy} value="counterpart" />
          {$_('anketaList.viewToggleCounterpart')}
        </label>
      </div>

      {#if groupBy === 'counterpart'}
        <div class="groups">
          {#each groupByCounterpart(list) as group (group.counterpartId)}
            <div class="card group-card">
              <div class="group-header">
                <div class="avatar avatar-accent-2">{initials(group.counterpartEmail)}</div>
                <div class="anketa-info">
                  <div class="anketa-email">{group.counterpartEmail}</div>
                  <div class="text-muted anketa-meta">
                    {$_('anketaList.nextMeetingLabel', {
                      values: { date: new Date(group.anketas[0].meetingDate).toLocaleDateString() },
                    })}
                  </div>
                </div>
              </div>
              <div class="group-anketas">
                {#each group.anketas as anketa (anketa.id)}
                  <a href="/anketas/{anketa.id}" class="group-anketa-row">
                    <span class="group-anketa-date">{new Date(anketa.meetingDate).toLocaleDateString()}</span>
                    <div class="badges">
                      {#each badgesFor(anketa) as badge (badge.cls + badge.label)}
                        <span class="tag {badge.cls}">{badge.label}</span>
                      {/each}
                    </div>
                  </a>
                {/each}
              </div>
            </div>
          {/each}
        </div>
      {:else}
        <div class="flat-list">
          {#each list as anketa (anketa.id)}
            {@render anketaRow(anketa)}
          {/each}
        </div>
      {/if}
    {/if}
  {:catch error}
    <p class="banner-error">{error.message}</p>
  {/await}
</main>

<style>
  main {
    max-width: 52rem;
    margin: 0 auto;
    padding: 28px 24px 60px;
  }

  h1 {
    font-size: 30px;
    margin-bottom: 20px;
  }

  .invite-wrap {
    margin-bottom: 24px;
    max-width: 26rem;
  }

  .view-toggle {
    margin-bottom: 20px;
  }

  .flat-list,
  .groups {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .anketa-row {
    flex-direction: row;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
    text-decoration: none;
    color: inherit;
  }

  .avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: var(--color-accent-100);
    color: var(--color-accent-800);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-heading);
    font-size: 14px;
    flex: none;
  }

  .avatar-accent-2 {
    background: var(--color-accent-2-100);
    color: var(--color-accent-2-800);
  }

  .anketa-info {
    flex: 1;
    min-width: 160px;
  }

  .anketa-email {
    font-size: 14px;
  }

  .anketa-meta {
    font-size: 12px;
  }

  .badges {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .group-card {
    padding: 0;
    overflow: hidden;
  }

  .group-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 16px;
    flex-wrap: wrap;
  }

  .group-anketas {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 0 16px 14px;
  }

  .group-anketa-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    background: var(--color-bg);
    text-decoration: none;
    color: inherit;
    flex-wrap: wrap;
  }

  .group-anketa-date {
    font-size: 13px;
    flex: 1;
    min-width: 100px;
  }

  .empty-state {
    align-items: center;
    text-align: center;
    padding: 48px 24px;
  }
</style>
