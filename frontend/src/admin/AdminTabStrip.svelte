<script lang="ts">
  import { _ } from 'svelte-i18n';

  const TABS = [
    { key: 'users', href: '/admin', labelKey: 'admin.usersTab' },
    { key: 'reports', href: '/admin/reports', labelKey: 'admin.reportsTab' },
    { key: 'invites', href: '/admin/invites', labelKey: 'admin.invitesTab' },
  ] as const;

  const { active }: { active: (typeof TABS)[number]['key'] } = $props();
</script>

<nav class="tab-strip">
  {#each TABS as tab (tab.key)}
    {#if tab.key === active}
      <span class="tab tab-active">{$_(tab.labelKey)}</span>
    {:else}
      <a class="tab" href={tab.href}>{$_(tab.labelKey)}</a>
    {/if}
  {/each}
</nav>

<style>
  .tab-strip {
    display: flex;
    gap: 4px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--color-divider);
  }

  .tab {
    padding: 8px 4px;
    font-size: 14px;
    color: var(--color-text-muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-right: 16px;
  }

  a.tab:hover {
    color: var(--color-accent-ink);
  }

  .tab-active {
    color: var(--color-text);
    border-bottom-color: var(--color-accent);
    font-weight: var(--font-heading-weight);
  }
</style>
