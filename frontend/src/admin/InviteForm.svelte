<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiPost } from '../api/client';
  import {
    inviteError,
    type InviteError,
    type SeatLimitStep,
  } from './seatLimit';
  import SeatLimitNextStep from './SeatLimitNextStep.svelte';

  // Prefilled from the "requested a new invitation" email's link (GitHub
  // issue #169), so re-inviting is one click on Send.
  const {
    initialEmail = '',
    seatStep,
    onSent,
    onSeatLimit,
  }: {
    initialEmail?: string;
    /** What follows a "no seats left" error: `seatLimitStep()` of the viewer. */
    seatStep: SeatLimitStep;
    /** An invite went out: a list of invites on the same page reloads. */
    onSent?: () => void;
    /** Refused for want of a seat: a seat line on the same page reads again. */
    onSeatLimit?: () => void;
  } = $props();

  // svelte-ignore state_referenced_locally
  let email = $state(initialEmail);
  let submitting = $state(false);
  let error = $state<InviteError | null>(null);
  let sent = $state(false);

  async function handleSubmit(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!email.trim() || submitting) return;

    submitting = true;
    error = null;
    sent = false;
    try {
      await apiPost('/api/invites', { email: email.trim() });
      sent = true;
      email = '';
    } catch (err) {
      error = inviteError(err, $_('inviteForm.error'));
      if (error.seatLimit) onSeatLimit?.();
      return;
    } finally {
      submitting = false;
    }
    onSent?.();
  }
</script>

<form class="card elev-md" onsubmit={handleSubmit}>
  <div class="field">
    <label for="invite-email">{$_('inviteForm.label')}</label>
    <div class="row">
      <input
        id="invite-email"
        class="input"
        type="email"
        bind:value={email}
        placeholder={$_('inviteForm.placeholder')}
        disabled={submitting}
      />
      <button
        type="submit"
        class="btn btn-primary"
        disabled={submitting || !email.trim()}
      >
        {submitting ? $_('inviteForm.submitting') : $_('inviteForm.submit')}
      </button>
    </div>
  </div>
  {#if error}
    <p class="banner-error" role="alert">
      {error.message}
      {#if error.seatLimit}<SeatLimitNextStep step={seatStep} />{/if}
    </p>
  {/if}
  {#if sent}
    <p class="banner-success" role="status">{$_('inviteForm.sent')}</p>
  {/if}
</form>

<style>
  .row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .row .input {
    flex: 1;
    min-width: 11rem;
  }
</style>
