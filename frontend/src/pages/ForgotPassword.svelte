<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiPost, ApiError } from '../api/client';

  let email = $state('');
  let submitting = $state(false);
  let submitted = $state(false);
  let error = $state<string | null>(null);

  const canSubmit = $derived(email.length > 0 && !submitting);

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit) return;

    submitting = true;
    error = null;
    try {
      await apiPost('/api/password-reset', { email });
      // Always shown, regardless of whether the email actually has an account —
      // the backend itself never reveals that distinction either (see
      // PasswordResetController::request()), and the frontend must not leak it
      // by branching on the response.
      submitted = true;
    } catch (err) {
      error =
        err instanceof ApiError
          ? err.message
          : $_('forgotPassword.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="card elev-md">
    <h1>{$_('forgotPassword.title')}</h1>

    {#if submitted}
      <p class="text-muted">{$_('forgotPassword.sentConfirmation')}</p>
      <a href="/" class="btn btn-secondary btn-block"
        >{$_('forgotPassword.backToLogin')}</a
      >
    {:else}
      <p class="text-muted subtitle">{$_('forgotPassword.subtitle')}</p>

      <form onsubmit={handleSubmit}>
        <div class="field">
          <label for="forgot-email">{$_('forgotPassword.emailLabel')}</label>
          <input
            id="forgot-email"
            class="input"
            type="email"
            bind:value={email}
            autocomplete="username"
            required
          />
        </div>

        {#if error}
          <div role="alert" class="banner-error">{error}</div>
        {/if}

        <button
          type="submit"
          class="btn btn-primary btn-block"
          disabled={!canSubmit}
        >
          {submitting
            ? $_('forgotPassword.submitting')
            : $_('forgotPassword.submit')}
        </button>

        <a href="/" class="back-link">{$_('forgotPassword.backToLogin')}</a>
      </form>
    {/if}
  </div>
</main>

<style>
  main {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
  }

  .card {
    width: min(400px, 100%);
    padding: 28px;
  }

  h1 {
    font-size: 26px;
    margin: 0 0 4px;
  }

  .subtitle {
    font-size: 13px;
    margin: 0 0 20px;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .back-link {
    font-size: 13px;
    text-align: center;
  }
</style>
