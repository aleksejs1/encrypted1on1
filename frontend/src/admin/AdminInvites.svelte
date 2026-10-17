<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { latestReads } from '../api/latestRead';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { beginAction, refocus } from '../anketa/keepFocus';
  import {
    inviteSenderLabel,
    inviteStatusTagClass,
    type Invite,
  } from './inviteDisplay';
  import InviteForm from './InviteForm.svelte';
  import InviteSettings from './InviteSettings.svelte';
  import AdminGate from './AdminGate.svelte';
  import {
    inviteError,
    seatLimitStep,
    type InviteError,
    type SeatLimitStep,
  } from './seatLimit';
  import SeatLimitNextStep from './SeatLimitNextStep.svelte';
  import type { BillingOverview } from '../api/types';
  import { seatLine, type SeatLine } from './planPage';
  import SeatUsage from './SeatUsage.svelte';
  import type { Identity } from '../crypto/identity.svelte';

  /** `resendable`: only this company-scoped list has it (InviteController::list()). */
  type AdminInvite = Invite & { resendable: boolean };

  let invites = $state<AdminInvite[]>([]);
  let loadError = $state<string | null>(null);
  let resendingId = $state<string | null>(null);
  // As in InviteForm: a re-send can be refused for want of a seat too.
  let resendError = $state<InviteError | null>(null);
  let resentTo = $state<string | null>(null);
  // The re-send banners and the list: what a re-send's focus stays within.
  let list = $state<HTMLElement>();
  // Known before the gate shows the form (AdminGate calls onIdentity first).
  let seatStep = $state<SeatLimitStep>('none');
  // GitHub issue #310: the Plan page's seat line, in the Cloud deployment and
  // for a company with a limit only. Read again with the list and after a
  // refusal for want of a seat; a failed read keeps the line last shown.
  let seats = $state<SeatLine | null>(null);
  let cloudMode = false;

  // Cancels loadInvites()'s fetch below on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();
  // Seat reads: only the one started last is answered, as on the Plan page,
  // and a failed one leaves the line as it was. Not in the list's queue, so
  // a slow read never holds up the list or Re-send.
  const startSeatRead = latestReads(readAbort);

  // The list is reloaded after a re-send and after the form sends an invite. One
  // reload at a time, in the order asked for: the last answer shown is the newest,
  // and whoever awaits a reload gets a list at least as new as their own write.
  let reloads: Promise<void> = Promise.resolve();
  // Reloads asked for and not yet answered. Until the list is the reloaded one, a
  // row may still offer a Re-send that has already been done, so none is offered.
  let reloading = $state(0);

  function loadInvites(): Promise<void> {
    reloading += 1;
    reloads = reloads.then(fetchInvites).finally(() => {
      reloading -= 1;
    });
    return reloads;
  }

  /** Never rejects: a failure is shown in place of the list. */
  async function fetchInvites(): Promise<void> {
    try {
      invites = await apiGet<AdminInvite[]>('/api/admin/invites', {
        signal: readAbort,
      });
      loadError = null;
    } catch (error) {
      if (isAbortError(error)) return;
      loadError =
        error instanceof ApiError
          ? error.message
          : $_('adminInvites.errorLoad');
    }
  }

  /** Never rejects. */
  async function loadSeats(): Promise<void> {
    if (!cloudMode) return;
    const read = startSeatRead();
    let overview: BillingOverview;
    try {
      overview = await apiGet<BillingOverview>('/api/admin/billing', {
        signal: read.signal,
      });
    } catch (error) {
      // Refused: the numbers are no longer this viewer's to see.
      if (
        read.current() &&
        error instanceof ApiError &&
        (error.status === 401 || error.status === 403)
      ) {
        seats = null;
      }
      return;
    } finally {
      read.end();
    }
    if (read.current()) seats = seatLine(overview);
  }

  function identified(identity: Identity): void {
    seatStep = seatLimitStep(identity);
    cloudMode = identity.cloudMode;
  }

  function loadAll(): Promise<void> {
    void loadSeats();
    return loadInvites();
  }

  // GitHub issue #169: sends a fresh invite to an expired row's address,
  // through the same endpoint as the invite form (same permission, rate limit
  // and seat-limit checks), then reloads, so the new pending row replaces
  // this one's button and badge.
  async function resend(invite: Invite): Promise<void> {
    if (null !== resendingId || reloading > 0) return;
    const startedOn = beginAction();
    resendingId = invite.id;
    resendError = null;
    resentTo = null;
    try {
      await apiPost('/api/invites', { email: invite.email });
    } catch (error) {
      resendError = inviteError(error, $_('adminInvites.resendError'));
      if (resendError.seatLimit) void loadSeats();
      return;
    } finally {
      resendingId = null;
    }
    resentTo = invite.email;
    await loadAll();
    // The clicked button is gone after the reload; the confirmation takes focus,
    // unless the admin has gone on to something else meanwhile, the invite form
    // above included (keepFocus.ts).
    void refocus(list, '[data-resent-message]', { startedOn });
  }

  /** The form sent an invite: an earlier re-send's outcome is no longer the news. */
  function inviteSent(): void {
    resentTo = null;
    resendError = null;
    void loadAll();
  }

  function senderLabel(invite: Invite): string {
    return inviteSenderLabel(invite.invitedBy, {
      selfRegistered: $_('adminInvites.senderSelfRegistered'),
      senderDeleted: $_('adminInvites.senderDeleted'),
    });
  }

  function statusLabel(invite: Invite): string {
    return $_(`adminInvites.status.${invite.status}`);
  }
</script>

<main>
  <h1>{$_('adminInvites.title')}</h1>

  <AdminGate
    tab="invites"
    onIdentity={identified}
    onReady={loadAll}
    errorLoadKey="adminInvites.errorLoad"
  >
    <!-- Not a live region: a refusal's own alert says there are no seats. -->
    <div data-seat-usage>
      {#if seats}
        <div class="seat-usage"><SeatUsage {seats} step={seatStep} /></div>
      {/if}
    </div>
    <!-- Above the list and outside its branches: shown with an empty list and
         when the list fails to load. -->
    <div class="invite-cards">
      <InviteForm {seatStep} onSent={inviteSent} onSeatLimit={loadSeats} />
      <InviteSettings />
    </div>

    <div bind:this={list}>
      <!-- Outside the list's branches: a re-send that went out stays confirmed even
         if the reload after it fails. -->
      {#if resentTo}
        <p
          class="banner-success"
          role="status"
          tabindex="-1"
          data-resent-message
        >
          {$_('adminInvites.resent', { values: { email: resentTo } })}
        </p>
      {/if}
      {#if resendError}
        <p class="banner-error" role="alert" data-resend-error>
          {resendError.message}
          {#if resendError.seatLimit}<SeatLimitNextStep step={seatStep} />{/if}
        </p>
      {/if}

      {#if loadError}
        <p class="banner-error">{loadError}</p>
      {:else if 0 === invites.length}
        <p class="text-muted">{$_('adminInvites.empty')}</p>
      {:else}
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>{$_('adminInvites.emailHeader')}</th>
                <th>{$_('adminInvites.senderHeader')}</th>
                <th>{$_('adminInvites.sentHeader')}</th>
                <th>{$_('adminInvites.expiresHeader')}</th>
                <th>{$_('adminInvites.statusHeader')}</th>
                <th
                  ><span class="sr-only"
                    >{$_('adminInvites.actionsHeader')}</span
                  ></th
                >
              </tr>
            </thead>
            <tbody>
              {#each invites as invite (invite.id)}
                <tr>
                  <td>{invite.email}</td>
                  <td>{senderLabel(invite)}</td>
                  <td>{formatDisplayDate(invite.createdAt)}</td>
                  <td>{formatDisplayDate(invite.expiresAt)}</td>
                  <td>
                    <span class="tag {inviteStatusTagClass(invite.status)}">
                      {statusLabel(invite)}
                    </span>
                    {#if invite.resendable && invite.renewalRequestedAt}
                      <!-- Dated: a request stays shown past its cooldown, until
                         the invite is re-sent. -->
                      <span class="tag tag-accent">
                        {$_('adminInvites.renewalRequested', {
                          values: {
                            date: formatDisplayDate(invite.renewalRequestedAt),
                          },
                        })}
                      </span>
                    {/if}
                  </td>
                  <td>
                    {#if invite.resendable}
                      <button
                        type="button"
                        class="btn btn-secondary btn-small"
                        disabled={null !== resendingId || reloading > 0}
                        aria-label={$_('adminInvites.resendTo', {
                          values: { email: invite.email },
                        })}
                        onclick={() => resend(invite)}
                      >
                        {resendingId === invite.id
                          ? $_('adminInvites.resending')
                          : $_('adminInvites.resend')}
                      </button>
                    {/if}
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      {/if}
    </div>
  </AdminGate>
</main>

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

  /* Side by side on a wide screen, stacked on a narrow one. */
  .invite-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(20rem, 100%), 1fr));
    gap: 24px;
    align-items: start;
    margin-bottom: 24px;
  }

  .seat-usage {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin-bottom: 16px;
  }

  .banner-success,
  .banner-error {
    margin-bottom: 12px;
  }

  .btn-small {
    padding: 4px 12px;
    font-size: 12px;
    white-space: nowrap;
  }
</style>
