<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { ApiError } from '../api/client';
  import { ensureUnlocked, type Identity } from '../crypto/identity.svelte';
  import TabStrip from '../design/TabStrip.svelte';
  import { adminTabs, type AdminTab } from './adminTabs';
  import { onMount, type Snippet } from 'svelte';

  /**
   * The isAdmin/loadError gate shared by every admin-only page
   * (AdminPanel.svelte, AdminReports.svelte) — was copy-pasted state +
   * effect + three-way template branch in both until this extraction.
   * It also shows the admin tab strip above the page: the identity it has
   * just resolved says which tabs this deployment has.
   * `onReady` fires once, the moment identity.isAdmin resolves true, so the
   * host page can kick off whatever it needs next (AdminPanel's user list
   * fetch, AdminReports' report load) without duplicating the unlock call
   * itself.
   */
  const {
    tab,
    onIdentity,
    onReady,
    errorLoadKey = 'admin.errorLoad',
    children,
  }: {
    /** The page's own tab in the strip this gate shows above the page. */
    tab: AdminTab['key'];
    /** Fires once, before `onReady` and for someone who isn't an admin too: for a page that depends on the deployment (AdminPlan; AdminInvites' seat-limit next step). */
    onIdentity?: (identity: Identity) => void;
    onReady?: (identity: Identity) => void;
    /** i18n key for a non-ApiError failure (e.g. "not logged in") — each host page has its own ("Could not load the admin panel." vs. "Could not load the report."). */
    errorLoadKey?: string;
    children: Snippet;
  } = $props();

  let isAdmin = $state<boolean | null>(null);
  // The Cloud deployment has one tab more. Known together with isAdmin, so the
  // strip is complete in its first render.
  let cloudMode = $state(false);
  let loadError = $state<string | null>(null);

  // onMount (unlike an $effect) doesn't track reactive reads inside it, so
  // ensureUnlocked()'s synchronous read of getGeneration() can't re-trigger
  // this when an unrelated 401 elsewhere bumps the identity generation —
  // see App.svelte's own checkAuth() mount check and GitHub issue #62/#94.
  onMount(() => {
    ensureUnlocked()
      .then((identity) => {
        onIdentity?.(identity);
        cloudMode = identity.cloudMode;
        isAdmin = identity.isAdmin;
        if (identity.isAdmin) onReady?.(identity);
      })
      .catch((error: unknown) => {
        loadError =
          error instanceof ApiError ? error.message : $_(errorLoadKey);
      });
  });
</script>

{#if loadError}
  <p class="banner-error" role="alert">{loadError}</p>
{:else if isAdmin === null}
  <p class="text-muted">{$_('common.loading')}</p>
{:else if !isAdmin}
  <p class="text-muted">{$_('admin.notAuthorized')}</p>
{:else}
  <TabStrip
    tabs={adminTabs(cloudMode).map((item) => ({
      ...item,
      label: $_(item.labelKey),
    }))}
    active={tab}
  />
  {@render children()}
{/if}
