<script lang="ts">
  import { tick } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { formatDisplayDate } from '../datePreference.svelte';
  import {
    inviteSenderLabel,
    inviteStatusTagClass,
    type Invite,
  } from './inviteDisplay';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';

  /** `resendable`: only this company-scoped list has it (InviteController::list()). */
  type AdminInvite = Invite & { resendable: boolean };

  let invites = $state<AdminInvite[]>([]);
  let loadError = $state<string | null>(null);
  let resendingId = $state<string | null>(null);
  let resendError = $state<string | null>(null);
  let resentTo = $state<string | null>(null);
  let resentMessage = $state<HTMLElement | null>(null);

  // Cancels loadInvites()'s fetch below on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();

  async function loadInvites(): Promise<void> {
    try {
      invites = await apiGet<AdminInvite[]>('/api/admin/invites', {
        signal: readAbort,
      });
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
    if (null !== resendingId) return;
    resendingId = invite.id;
    resendError = null;
    resentTo = null;
    try {
      await apiPost('/api/invites', { email: invite.email });
    } catch (error) {
      resendError =
        error instanceof ApiError
          ? error.message
          : $_('adminInvites.resendError');
      return;
    } finally {
      resendingId = null;
    }
    resentTo = invite.email;
    await loadInvites();
    // The clicked button is gone after the reload; the confirmation takes focus.
    await tick();
    resentMessage?.focus();
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

  <AdminGate onReady={loadInvites} errorLoadKey="adminInvites.errorLoad">
    <AdminTabStrip active="invites" />

    <!-- Outside the list's branches: a re-send that went out stays confirmed even
         if the reload after it fails. -->
    {#if resentTo}
      <p
        class="banner-success"
        role="status"
        tabindex="-1"
        bind:this={resentMessage}
      >
        {$_('adminInvites.resent', { values: { email: resentTo } })}
      </p>
    {/if}
    {#if resendError}
      <p class="banner-error" role="alert">{resendError}</p>
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
                ><span class="sr-only">{$_('adminInvites.actionsHeader')}</span
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
                      disabled={null !== resendingId}
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
