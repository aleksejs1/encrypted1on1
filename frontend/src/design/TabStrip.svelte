<script lang="ts">
  /** A row of page tabs: links, and the current page's as plain text. */
  const {
    tabs,
    active,
  }: {
    tabs: readonly { key: string; href: string; label: string }[];
    active: string;
  } = $props();
</script>

<nav class="tab-strip">
  {#each tabs as tab (tab.key)}
    {#if tab.key === active}
      <span class="tab tab-active" aria-current="page">{tab.label}</span>
    {:else}
      <a class="tab" href={tab.href}>{tab.label}</a>
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
