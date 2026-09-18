<script lang="ts">
  import { _ } from 'svelte-i18n';

  /**
   * Shared by every section heading that needs to disclose whether its
   * content is end-to-end encrypted (closed) or not (open, currently only
   * goals — see CLAUDE.md's plaintext-goal exception). Previously three
   * separate copies of this SVG + title (Anketa.svelte, AnketaOutcomes.svelte,
   * AnketaGoals.svelte) that a visual tweak would have needed updating in
   * lockstep across.
   */
  let { encrypted }: { encrypted: boolean } = $props();
</script>

<span
  class="lock-hint"
  title={encrypted ? $_('anketa.encryptedHint') : $_('anketa.notEncryptedHint')}
  aria-hidden="true"
>
  <svg
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2.5"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <rect x="3" y="11" width="18" height="10" rx="2"></rect>
    {#if encrypted}
      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
    {:else}
      <path d="M7 11V7a5 5 0 0 1 9.5-1.5"></path>
    {/if}
  </svg>
</span>

<style>
  .lock-hint {
    display: inline-flex;
    width: 14px;
    height: 14px;
    flex: none;
    color: color-mix(in srgb, var(--color-text) 45%, transparent);
  }

  .lock-hint svg {
    width: 100%;
    height: 100%;
  }
</style>
