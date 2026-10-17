<script lang="ts">
  import { onDestroy, tick } from 'svelte';
  import { _, locale } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { pause } from '../api/pause';
  import { latestReads } from '../api/latestRead';
  import type { BillingOverview, PlusPrice } from '../api/types';
  import type { Identity } from '../crypto/identity.svelte';
  import { formatDisplayDate } from '../datePreference.svelte';
  import NotFound from '../pages/NotFound.svelte';
  import AdminGate from './AdminGate.svelte';
  import SeatUsage from './SeatUsage.svelte';
  import {
    planPageView,
    priceLine,
    type PlanPageView,
    type PriceLine,
  } from './planPage';
  import {
    focusesPlan,
    initialReturn,
    keepsParam,
    nextReturn,
    offersPayment,
    POLL_MS,
    polls,
    readReturnParam,
    searchWithoutReturn,
    showsPlan,
    waitEndsAt,
    type PlanReturn,
    type PlanReturnEvent,
  } from './planReturn';

  /**
   * The company's plan, in the Cloud deployment only (GitHub issue #286):
   * the plan and its seats, Plus with its price, and the way to Stripe's
   * Checkout and customer portal. Card details, invoices and cancelling are
   * all on Stripe's pages; this one only sends the admin there, and waits
   * for what was done there when the admin is back (planReturn.ts).
   */

  // A self-hosted instance has no plans: to it, and to anyone on it, this page
  // doesn't exist. Known only from the identity, so until AdminGate has it
  // nothing here says "Plan".
  let deployment = $state<'unknown' | 'self-hosted' | 'cloud'>('unknown');
  let overview = $state<BillingOverview | null>(null);
  let loadError = $state<string | null>(null);
  // Asked only once the Plus card is shown. Kept as Stripe's answer, so that the
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

  // Back from Stripe: the parameter of the address the page was opened at.
  let back = $state<PlanReturn>(
    initialReturn(readReturnParam(window.location.search)),
  );
  const showsOffer = $derived(view?.offer === 'plus' && offersPayment(back));

  const readAbort = abortOnDestroy();
  onDestroy(() => advance({ type: 'leave' }));

  let priceAsked = false;
  $effect(() => {
    if (showsOffer && !priceAsked) {
      priceAsked = true;
      void loadPrice();
    }
  });

  /** Runs planReturn.ts, and does what the new state asks of the page. */
  function advance(event: PlanReturnEvent): void {
    const before = back;
    back = nextReturn(back, event);
    syncAddress();
    // The plan card is drawn anew when it was hidden while waiting: focus
    // once it is there.
    if (focusesPlan(before, back)) {
      const thanks = back.phase === 'confirmed';
      void tick().then(async () => {
        // Only when the heading is there to be focused, and set before the
        // focus, which reads it. Only from nowhere (the page was just
        // loaded, or "Check again" is gone): not from wherever the admin has
        // moved it while the page waited.
        if (!planHeading || document.activeElement !== document.body) return;
        thanksOnHeading = thanks;
        await tick();
        // Checked again: the admin may have moved meanwhile.
        if (!planHeading || document.activeElement !== document.body) {
          thanksOnHeading = false;
          return;
        }
        planHeading.focus();
      });
    }
  }

  /**
   * Drops the parameter once the return state no longer keeps it. Not while
   * the browser is on its way to Stripe: a change of the address may stop
   * that navigation. A redirect that fails calls this again.
   */
  function syncAddress(): void {
    if (
      !keepsParam(back) &&
      redirect.status !== 'running' &&
      readReturnParam(window.location.search) !== null
    ) {
      history.replaceState(
        history.state,
        '',
        window.location.pathname +
          searchWithoutReturn(window.location.search) +
          window.location.hash,
      );
    }
  }

  let planHeading = $state<HTMLElement | null>(null);
  // The thanks as the heading's description, for the one focus that
  // confirms a payment, not for every later visit to the heading.
  let thanksOnHeading = $state(false);

  function identified(identity: Identity): void {
    deployment = identity.cloudMode ? 'cloud' : 'self-hosted';
  }

  async function load(identity: Identity): Promise<void> {
    if (!identity.cloudMode) return;
    let read: BillingOverview | null;
    try {
      read = await readPlan();
    } catch (error) {
      if (isAbortError(error) || endsPage(error)) return;
      loadError =
        error instanceof ApiError ? error.message : $_('adminPlan.errorLoad');
      return;
    }
    if (read === null) return;
    advance({ type: 'read', state: read.state, at: performance.now() });
    await follow();
  }

  /**
   * Reads the plan again POLL_MS after each answer for as long as the return
   * state asks
   * for it. A failed read is followed by the next one, and the wait ends by
   * time all the same, unless it was a refusal (endsPage()).
   */
  async function follow(): Promise<void> {
    const end = waitEndsAt(back);
    if (end !== null) {
      // A timer may fire a moment early by the clock it is compared with.
      // Only for the wait it was set for: one that has ended since is left
      // as it is.
      void pause(end - performance.now(), readAbort).then(() => {
        if (waitEndsAt(back) === end) {
          advance({ type: 'time', at: Math.max(performance.now(), end) });
        }
      });
    }
    while (polls(back)) {
      await pause(POLL_MS, readAbort);
      advance({ type: 'time', at: performance.now() });
      if (!polls(back)) return;
      let read: BillingOverview | null;
      try {
        read = await readPlan();
      } catch (error) {
        if (isAbortError(error) || endsPage(error)) return;
        continue;
      }
      // Late, maybe, after the wait ended by time: still the newest answer,
      // unless "Check again" has asked since.
      if (read !== null) {
        advance({ type: 'read', state: read.state, at: performance.now() });
      }
    }
  }

  /**
   * "Check again", once the page has stopped waiting. Not disabled while its
   * read runs: a read may hang, and a press then starts a newer one, which
   * is the one answered (readPlan()).
   */
  async function checkAgain(): Promise<void> {
    if (back.phase !== 'gave-up') return;
    advance({ type: 'check' });
    let read: BillingOverview | null;
    try {
      read = await readPlan();
    } catch (error) {
      if (isAbortError(error) || endsPage(error)) return;
      advance({ type: 'read-failed' });
      return;
    }
    if (read === null) return;
    advance({ type: 'read', state: read.state, at: performance.now() });
  }

  /**
   * A refusal (the session ended, the admin role taken away) won't change by
   * reading again: the page then shows it as a failed load. Anything else,
   * a lost connection, a 5xx or a proxy's 404 during a deploy, may.
   */
  function endsPage(error: unknown): boolean {
    if (
      !(error instanceof ApiError) ||
      (error.status !== 401 && error.status !== 403)
    ) {
      return false;
    }
    loadError = error.message;
    advance({ type: 'leave' });
    return true;
  }

  // Reads can overlap: a poll still running at the end of the wait, and
  // "Check again" after it, or pressed again. Only the one started last is
  // answered, and an older one is stopped.
  const startRead = latestReads(readAbort);

  /**
   * The plan, put on the page, or `null` when a read started later, or the
   * end of the page, has made this one stale; its failure is then nobody's
   * either. Each read gives up after requestTimeout()'s time (latestReads()):
   * a failure then, like a lost connection.
   */
  async function readPlan(): Promise<BillingOverview | null> {
    const mine = startRead();
    const stale = (): boolean => !mine.current() || back.phase === 'left';
    try {
      const read = await apiGet<BillingOverview>('/api/admin/billing', {
        signal: mine.signal,
      });
      if (stale()) return null;
      overview = read;
      return read;
    } catch (error) {
      if (stale()) return null;
      throw error;
    } finally {
      mine.end();
    }
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
      syncAddress();
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

{#snippet support(className: string, lead: string, email: string)}
  <p class={className}>
    {$_(lead)}
    <a href="mailto:{email}">{email}</a>
  </p>
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
      <!-- Under the tabs, above what it is about. There before the first
           message, from the moment the gate lets the page in, which is before
           the plan is read: a screen reader says only what goes into a region
           it already knows. -->
      <div class="return" role="status">
        {#if !loadError}
          {#if back.phase === 'waiting'}
            <p>{$_('adminPlan.returnWaiting')}</p>
          {:else if back.phase === 'confirmed'}
            <p class="banner-success" id="plan-return-message">
              {$_('adminPlan.returnConfirmed')}
            </p>
          {:else if back.phase === 'gave-up'}
            <p>{$_('adminPlan.returnGaveUp')}</p>
            {#if back.check === 'unchanged'}
              <p>{$_('adminPlan.checkUnchanged')}</p>
            {/if}
          {:else if back.phase === 'cancelled'}
            <p>{$_('adminPlan.returnCancelled')}</p>
          {/if}
        {/if}
      </div>

      {#if loadError}
        <p class="banner-error" role="alert">{loadError}</p>
      {:else if !view}
        <p class="text-muted">{$_('common.loading')}</p>
      {:else}
        {#if back.phase === 'gave-up'}
          <div class="action check">
            <button
              type="button"
              class="btn btn-secondary"
              onclick={checkAgain}
            >
              {$_('adminPlan.checkAgain')}
            </button>
            {#if back.check === 'failed'}
              <p class="banner-error" role="alert">
                {$_('adminPlan.errorCheck')}
              </p>
            {/if}
            <p class="text-muted hint">{$_('adminPlan.notPaid')}</p>
            {#if view.supportEmail}
              {@render support(
                'text-muted hint',
                'adminPlan.returnSupport',
                view.supportEmail,
              )}
            {/if}
          </div>
        {/if}

        {#if showsPlan(back)}
          <section class="card elev-md" aria-labelledby="plan-current">
            <!-- Focused on a confirmed payment, which a screen reader then
                 reads with it: the focus would cut the status region short. -->
            <h2
              id="plan-current"
              class="card-title"
              tabindex="-1"
              aria-describedby={thanksOnHeading
                ? 'plan-return-message'
                : undefined}
              onblur={() => (thanksOnHeading = false)}
              bind:this={planHeading}
            >
              {$_('adminPlan.yourPlan', { values: { plan: planName(view) } })}
            </h2>

            {#if view.state === 'plus-past-due'}
              <p class="banner-error" role="alert">{$_('adminPlan.pastDue')}</p>
            {/if}

            {#if view.seats}
              <SeatUsage seats={view.seats} />
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
        {/if}

        {#if showsOffer}
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

        <!-- After giving up the address is above, with the button. -->
        {#if view.supportEmail && back.phase !== 'gave-up'}
          {@render support('support', 'adminPlan.support', view.supportEmail)}
        {/if}
      {/if}
    </AdminGate>
  </main>
{/if}

<style>
  main {
    max-width: min(56rem, 100%);
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

  .return p,
  .check {
    margin-bottom: 20px;
  }
</style>
