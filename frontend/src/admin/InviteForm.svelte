<script lang="ts">
  import { _ } from 'svelte-i18n';
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
      error = err instanceof ApiError ? err.message : $_('inviteForm.error');
    } finally {
      submitting = false;
    }
  }
</script>

<form class="card" onsubmit={handleSubmit}>
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
    <p class="banner-error">{error}</p>
  {/if}
  {#if sent}
    <p class="banner-success">{$_('inviteForm.sent')}</p>
  {/if}
</form>

<style>
  form {
    max-width: 26rem;
  }

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
