<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
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

  // Cancels loadInvites()'s fetch below on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();

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
      return;
    } finally {
      resendingId = null;
    }
    resentTo = invite.email;
    await loadInvites();
    // The clicked button is gone after the reload; the confirmation takes focus,
    // unless the admin has gone on to something else meanwhile, the invite form
    // above included (keepFocus.ts).
    void refocus(list, '[data-resent-message]', { startedOn });
  }

  /** The form sent an invite: an earlier re-send's outcome is no longer the news. */
  function inviteSent(): void {
    resentTo = null;
    resendError = null;
    void loadInvites();
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
    onIdentity={(identity) => (seatStep = seatLimitStep(identity))}
    onReady={loadInvites}
    errorLoadKey="adminInvites.errorLoad"
  >
    <!-- Above the list and outside its branches: shown with an empty list and
         when the list fails to load. -->
    <div class="invite-cards">
      <InviteForm {seatStep} onSent={inviteSent} />
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
    max-width: 56rem;
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

  .table-wrap {
    overflow-x: auto;
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
