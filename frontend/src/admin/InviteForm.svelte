<script lang="ts">
  import { apiPost, ApiError } from '../api/client';

  let email = $state('');
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
      error = err instanceof ApiError ? err.message : 'Could not send the invite.';
    } finally {
      submitting = false;
    }
  }
</script>

<form onsubmit={handleSubmit}>
  <label>
    Invite a colleague
    <input type="email" bind:value={email} placeholder="name@example.com" disabled={submitting} />
  </label>
  {#if error}
    <p class="error">{error}</p>
  {/if}
  {#if sent}
    <p class="sent">Invite sent.</p>
  {/if}
  <button type="submit" disabled={submitting || !email.trim()}>
    {submitting ? 'Sending…' : 'Send invite'}
  </button>
</form>

<style>
  form {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    max-width: 20rem;
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  .error {
    color: #c0392b;
    font-size: 0.85rem;
  }

  .sent {
    color: #2e7d32;
    font-size: 0.85rem;
  }
</style>
