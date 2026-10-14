<script lang="ts">
  import { _, locale } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import type { BillingOverview, PlusPrice } from '../api/types';
  import type { Identity } from '../crypto/identity.svelte';
  import { formatDisplayDate } from '../datePreference.svelte';
  import NotFound from '../pages/NotFound.svelte';
  import AdminGate from './AdminGate.svelte';
  import {
    planPageView,
    priceLine,
    type PlanPageView,
    type PriceLine,
  } from './planPage';

  /**
   * The company's plan, in the Cloud deployment only (GitHub issue #286):
   * the plan and its seats, Plus with its price, and the way to Stripe's
   * Checkout and customer portal. Card details, invoices and cancelling are
   * all on Stripe's pages; this one only sends the admin there.
   */

  // A self-hosted instance has no plans: to it, and to anyone on it, this page
  // doesn't exist. Known only from the identity, so until AdminGate has it
  // nothing here says "Plan".
  let deployment = $state<'unknown' | 'self-hosted' | 'cloud'>('unknown');
  let overview = $state<BillingOverview | null>(null);
  let loadError = $state<string | null>(null);
  // Asked only when Plus is offered. Kept as Stripe's answer, so that the
  // amount follows a change of the UI language like every other string; a
  // failed request is the same as no price.
  let price = $state<{ answer: PlusPrice | null } | 'asking'>('asking');
  // `null` when it can't be named: the page then says the price is on the
  // payment page.
  const priceShown = $derived<PriceLine | null>(
    price === 'asking' ? null : priceLine(price.answer, $locale ?? 'en'),
  );

  /**
   * The Stripe page being opened. One at a time: while either button's
   * request runs both are disabled, and a failure is shown under the button
   * that failed. On success the browser leaves for Stripe, so `running`
   * stays: a navigation stopped before Stripe's page loads leaves the buttons
   * disabled until a reload, as nothing tells the page it was stopped.
   */
  type StripePage = 'checkout' | 'portal';
  type Redirect =
    | { status: 'idle' }
    | { status: 'running'; to: StripePage }
    | { status: 'failed'; to: StripePage; message: string };
  let redirect = $state<Redirect>({ status: 'idle' });

  const view = $derived<PlanPageView | null>(
    overview ? planPageView(overview) : null,
  );

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
      return;
    }
    if (view?.offer === 'plus') await loadPrice();
  }

  async function loadPrice(): Promise<void> {
    try {
      const answer = await apiGet<PlusPrice | null>(
        '/api/admin/billing/plus-price',
        { signal: readAbort },
      );
      price = { answer };
    } catch (error) {
      if (isAbortError(error)) return;
      // The button works without a price: Checkout shows it anyway.
      price = { answer: null };
    }
  }

  async function openStripe(to: StripePage): Promise<void> {
    if (redirect.status === 'running') return;
    redirect = { status: 'running', to };
    try {
      const answer = await apiPost<{ url?: unknown } | null>(
        to === 'checkout'
          ? '/api/admin/billing/checkout-session'
          : '/api/admin/billing/portal-session',
        {},
      );
      // Anything but an https address is a failure, not a page to go to.
      if (!isHttpsUrl(answer?.url)) throw new Error('No Stripe URL.');
      // Not from another page: the admin may have left this one meanwhile.
      if (readAbort.aborted) return;
      window.location.assign(answer.url);
    } catch (error) {
      redirect = {
        status: 'failed',
        to,
        message:
          error instanceof ApiError
            ? error.message
            : $_('adminPlan.errorRedirect'),
      };
    }
  }

  function isHttpsUrl(url: unknown): url is string {
    if (typeof url !== 'string') return false;
    try {
      return new URL(url).protocol === 'https:';
    } catch {
      return false;
    }
  }

  // Back from Stripe with the browser's Back button, the page may come back
  // from the back/forward cache as it was left: its buttons disabled, and the
  // plan from before a payment or a cancellation there. Loaded afresh
  // instead, a whole page, so that no state of the old one lingers (a request
  // still pending, a load still running) to race the new answer.
  function restored(event: PageTransitionEvent): void {
    if (event.persisted) window.location.reload();
  }

  function planName(view: PlanPageView): string {
    if (view.plan === 'free') return $_('adminPlan.planFree');
    if (view.plan === 'plus') return $_('adminPlan.planPlus');
    return view.customPlanName ?? '';
  }

  const PRICE_KEYS = {
    added: 'adminPlan.priceExclTax',
    included: 'adminPlan.priceInclTax',
    none: 'adminPlan.priceNoTax',
  } as const;

  const portalLabels = {
    manage: 'adminPlan.portalManage',
    'update-payment': 'adminPlan.portalUpdatePayment',
    invoices: 'adminPlan.portalInvoices',
  } as const;
</script>

<svelte:window onpageshow={restored} />

{#snippet failure(to: StripePage)}
  {#if redirect.status === 'failed' && redirect.to === to}
    <p class="banner-error" role="alert">{redirect.message}</p>
  {/if}
{/snippet}

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
      {:else if !view}
        <p class="text-muted">{$_('common.loading')}</p>
      {:else}
        <section class="card elev-md" aria-labelledby="plan-current">
          <h2 id="plan-current" class="card-title">
            {$_('adminPlan.yourPlan', { values: { plan: planName(view) } })}
          </h2>

          {#if view.state === 'plus-past-due'}
            <p class="banner-error" role="alert">{$_('adminPlan.pastDue')}</p>
          {/if}

          {#if view.seats}
            <p>
              {$_('adminPlan.seats', {
                values: {
                  used: view.seats.used,
                  limit: view.seats.limit,
                  people: view.seats.people,
                  pending: view.seats.pendingInvites,
                },
              })}
            </p>
            {#if view.seats.full}
              <p>{$_('adminPlan.seatsFull')}</p>
            {/if}
          {/if}

          {#if view.nextPaymentOn}
            <p>
              {$_('adminPlan.nextPayment', {
                values: { date: formatDisplayDate(view.nextPaymentOn) },
              })}
            </p>
          {/if}

          {#if view.endsOn}
            <p>
              {#if view.seatLimitAfterEnd === null}
                {$_('adminPlan.endsOnNoLimit', {
                  values: { date: formatDisplayDate(view.endsOn) },
                })}
              {:else}
                {$_('adminPlan.endsOn', {
                  values: {
                    date: formatDisplayDate(view.endsOn),
                    limit: view.seatLimitAfterEnd,
                  },
                })}
              {/if}
            </p>
            {#if view.noInvitesAfterEnd}
              <p>{$_('adminPlan.noInvitesAfterEnd')}</p>
            {/if}
            <p>{$_('adminPlan.undoCancel')}</p>
          {/if}

          <!-- By the plan, not the state: a Free plan without a seat limit is
               in the custom state, and its terms are still Free's. -->
          {#if view.plan === 'custom'}
            <p>{$_('adminPlan.customTerms')}</p>
          {/if}

          {#if view.portal}
            <div class="action">
              <button
                type="button"
                class="btn {view.portal === 'update-payment'
                  ? 'btn-primary'
                  : 'btn-secondary'}"
                disabled={redirect.status === 'running'}
                onclick={() => openStripe('portal')}
              >
                {$_(portalLabels[view.portal])}
              </button>
              <p class="text-muted hint">
                {$_(
                  view.portal === 'invoices'
                    ? 'adminPlan.portalInvoicesHint'
                    : 'adminPlan.portalHint',
                )}
              </p>
              {@render failure('portal')}
            </div>
          {/if}
        </section>

        {#if view.offer === 'plus'}
          <section class="card elev-md" aria-labelledby="plan-plus">
            <h2 id="plan-plus" class="card-title">
              {$_('adminPlan.planPlus')}
            </h2>
            <p>
              {$_('adminPlan.plusSeats', {
                values: { limit: view.plusSeatLimit },
              })}
            </p>
            {#if price !== 'asking'}
              <p>
                {#if priceShown}
                  {$_(PRICE_KEYS[priceShown.tax], {
                    values: {
                      amount: priceShown.amount,
                      interval: priceShown.interval,
                    },
                  })}
                {:else}
                  {$_('adminPlan.priceUnknown')}
                {/if}
              </p>
            {/if}
            <div class="action">
              <button
                type="button"
                class="btn btn-primary"
                disabled={redirect.status === 'running'}
                onclick={() => openStripe('checkout')}
              >
                {$_('adminPlan.upgrade')}
              </button>
              <p class="text-muted hint">{$_('adminPlan.payHint')}</p>
              <p class="text-muted hint">{$_('adminPlan.cancelHint')}</p>
              {@render failure('checkout')}
            </div>
          </section>
        {:else if view.offer === 'unavailable'}
          <p class="text-muted">{$_('adminPlan.unavailable')}</p>
        {/if}

        {#if view.supportEmail}
          <p class="support">
            {$_('adminPlan.support')}
            <a href="mailto:{view.supportEmail}">{view.supportEmail}</a>
          </p>
        {/if}
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

  h2 {
    margin: 0;
  }

  section + section,
  section + p {
    margin-top: 20px;
  }

  p {
    margin: 0;
  }

  .action {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 6px;
    margin-top: 8px;
  }

  .hint {
    font-size: 13px;
  }

  /* Also after the "not available" line, which isn't a section. */
  .support {
    margin-top: 20px;
  }
</style>
