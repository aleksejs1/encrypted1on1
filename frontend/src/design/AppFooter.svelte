<script lang="ts">
  import { _ } from 'svelte-i18n';

  const privacyPolicyUrl = import.meta.env.VITE_PRIVACY_POLICY_URL;
  const showVersion = import.meta.env.VITE_SHOW_VERSION === 'true';
  const gitSha = import.meta.env.VITE_GIT_SHA;
  const versionLabel = gitSha
    ? `v${__APP_VERSION__} (${gitSha})`
    : `v${__APP_VERSION__}`;
</script>

{#if privacyPolicyUrl || showVersion}
  <footer class="app-footer">
    {#if showVersion}
      <span class="app-footer-version">{versionLabel}</span>
    {/if}
    {#if privacyPolicyUrl}
      <a href={privacyPolicyUrl} target="_blank" rel="noopener noreferrer"
        >{$_('common.privacyPolicy')}</a
      >
    {/if}
  </footer>
{/if}

<style>
  .app-footer {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 6px;
    padding: 16px 20px;
    text-align: center;
    font-size: 12px;
  }

  .app-footer-version {
    opacity: 0.7;
  }

  /* Separator between version and privacy-policy link, shown only when both
     are present — a plain CSS sibling rule instead of a third conditional
     branch for the "both" case. */
  .app-footer > * + *::before {
    content: '\00b7';
    margin-right: 6px;
    opacity: 0.7;
  }

  .app-footer a {
    color: inherit;
    text-decoration: none;
    opacity: 0.7;
  }

  .app-footer a:hover {
    opacity: 1;
    color: var(--color-accent-ink);
  }
</style>
