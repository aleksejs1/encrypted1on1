<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { formatDisplayDate } from '../datePreference.svelte';
  import {
    inviteSenderLabel,
    inviteStatusTagClass,
    type Invite,
  } from './inviteDisplay';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';

  let invites = $state<Invite[]>([]);
  let loadError = $state<string | null>(null);

  async function loadInvites(): Promise<void> {
    try {
      invites = await apiGet<Invite[]>('/api/admin/invites');
    } catch (error) {
      loadError =
        error instanceof ApiError
          ? error.message
          : $_('adminInvites.errorLoad');
    }
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
</style>
