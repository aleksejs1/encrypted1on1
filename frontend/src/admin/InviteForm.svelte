<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiPost, ApiError } from '../api/client';

  // Prefilled from the "requested a new invitation" email's link (GitHub
  // issue #169), so re-inviting is one click on Send.
  const {
    initialEmail = '',
    onSent,
  }: {
    initialEmail?: string;
    /** An invite went out: a list of invites on the same page reloads. */
    onSent?: () => void;
  } = $props();

  // svelte-ignore state_referenced_locally
  let email = $state(initialEmail);
  let submitting = $state(false);
  let error = $state<string | null>(null);
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
      error = err instanceof ApiError ? err.message : $_('inviteForm.error');
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
    <p class="banner-error" role="alert">{error}</p>
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
