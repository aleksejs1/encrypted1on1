<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { ApiError } from '../api/client';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import type { Snippet } from 'svelte';

  /**
   * The isAdmin/loadError gate shared by every admin-only page
   * (AdminPanel.svelte, AdminReports.svelte) — was copy-pasted state +
   * effect + three-way template branch in both until this extraction.
   * `onReady` fires once, the moment identity.isAdmin resolves true, so the
   * host page can kick off whatever it needs next (AdminPanel's user list
   * fetch, AdminReports' report load) without duplicating the unlock call
   * itself.
   */
  const {
    onReady,
    errorLoadKey = 'admin.errorLoad',
    children,
  }: {
    onReady?: () => void;
    /** i18n key for a non-ApiError failure (e.g. "not logged in") — each host page has its own ("Could not load the admin panel." vs. "Could not load the report."). */
    errorLoadKey?: string;
    children: Snippet;
  } = $props();

  let isAdmin = $state<boolean | null>(null);
  let loadError = $state<string | null>(null);

  $effect(() => {
    ensureUnlocked()
      .then((identity) => {
        isAdmin = identity.isAdmin;
        if (identity.isAdmin) onReady?.();
      })
      .catch((error: unknown) => {
        loadError =
          error instanceof ApiError ? error.message : $_(errorLoadKey);
      });
  });
</script>

{#if loadError}
  <p class="banner-error">{loadError}</p>
{:else if isAdmin === null}
  <p class="text-muted">{$_('common.loading')}</p>
{:else if !isAdmin}
  <p class="text-muted">{$_('admin.notAuthorized')}</p>
{:else}
  {@render children()}
{/if}
