<script lang="ts">
  import { _ } from 'svelte-i18n';

  const privacyPolicyUrl = import.meta.env.VITE_PRIVACY_POLICY_URL;
</script>

{#if privacyPolicyUrl}
  <footer class="app-footer">
    <a href={privacyPolicyUrl} target="_blank" rel="noopener noreferrer"
      >{$_('common.privacyPolicy')}</a
    >
  </footer>
{/if}

<style>
  .app-footer {
    padding: 16px 20px;
    text-align: center;
  }

  .app-footer a {
    color: inherit;
    text-decoration: none;
    font-size: 12px;
    opacity: 0.7;
  }

  .app-footer a:hover {
    opacity: 1;
    color: var(--color-accent-ink);
  }
</style>
