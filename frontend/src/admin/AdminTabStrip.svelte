<script lang="ts">
  import { _ } from 'svelte-i18n';

  const { active }: { active: 'users' | 'reports' } = $props();
</script>

<nav class="tab-strip">
  {#if active === 'users'}
    <span class="tab tab-active">{$_('admin.usersTab')}</span>
    <a class="tab" href="/admin/reports">{$_('admin.reportsTab')}</a>
  {:else}
    <a class="tab" href="/admin">{$_('admin.usersTab')}</a>
    <span class="tab tab-active">{$_('admin.reportsTab')}</span>
  {/if}
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
