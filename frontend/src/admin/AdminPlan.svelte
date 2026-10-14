<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import type { BillingOverview } from '../api/types';
  import type { Identity } from '../crypto/identity.svelte';
  import NotFound from '../pages/NotFound.svelte';
  import AdminGate from './AdminGate.svelte';

  /**
   * The company's plan, in the Cloud deployment only (GitHub issue #286).
   * So far the frame: the heading, the tab, and the load of what the page
   * will show.
   */

  // A self-hosted instance has no plans: to it, and to anyone on it, this page
  // doesn't exist. Known only from the identity, so until AdminGate has it
  // nothing here says "Plan".
  let deployment = $state<'unknown' | 'self-hosted' | 'cloud'>('unknown');
  let overview = $state<BillingOverview | null>(null);
  let loadError = $state<string | null>(null);

  const readAbort = abortOnDestroy();

  function identified(identity: Identity): void {
    deployment = identity.cloudMode ? 'cloud' : 'self-hosted';
  }

  async function load(identity: Identity): Promise<void> {
    if (!identity.cloudMode) return;
    try {
      overview = await apiGet<BillingOverview>('/api/admin/billing', {
        signal: readAbort,
      });
    } catch (error) {
      if (isAbortError(error)) return;
      loadError =
        error instanceof ApiError ? error.message : $_('adminPlan.errorLoad');
    }
  }
</script>

{#if deployment === 'self-hosted'}
  <NotFound />
{:else}
  <main>
    {#if deployment === 'cloud'}
      <h1>{$_('adminPlan.title')}</h1>
    {/if}

    <!-- The gate's own failure keeps its general message: it may be shown before
         the deployment is known, when nothing here may say "plan". -->
    <AdminGate tab="plan" onIdentity={identified} onReady={load}>
      {#if loadError}
        <p class="banner-error" role="alert">{loadError}</p>
      {:else if !overview}
        <p class="text-muted">{$_('common.loading')}</p>
      {/if}
    </AdminGate>
  </main>
{/if}

<style>
  main {
    max-width: 56rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }
</style>
