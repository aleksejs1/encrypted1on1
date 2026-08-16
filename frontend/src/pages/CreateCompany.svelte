<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';

  interface RegistrationInfo {
    cloudMode: boolean;
  }

  let registrationInfo = $state<RegistrationInfo | null>(null);
  let infoError = $state<string | null>(null);

  $effect(() => {
    apiGet<RegistrationInfo>('/api/registration-info')
      .then((info) => {
        registrationInfo = info;
      })
      .catch((err: unknown) => {
        infoError =
          err instanceof ApiError
            ? err.message
            : $_('createCompany.genericError');
      });
  });

  let companyName = $state('');
  let email = $state('');
  let submitting = $state(false);
  let submitted = $state(false);
  let error = $state<string | null>(null);

  const canSubmit = $derived(
    companyName.trim().length > 0 && email.length > 0 && !submitting,
  );

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit) return;

    submitting = true;
    error = null;
    try {
      await apiPost('/api/companies', {
        name: companyName.trim(),
        adminEmail: email,
      });
      // Always shown, regardless of whether the email actually got a new company —
      // the backend itself never reveals that distinction either (see
      // CompanyController::create()), and the frontend must not leak it by
      // branching on the response.
      submitted = true;
    } catch (err) {
      error =
        err instanceof ApiError
          ? err.message
          : $_('createCompany.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <div class="card elev-md">
    <h1>{$_('createCompany.title')}</h1>

    {#if infoError}
      <p class="banner-error">{infoError}</p>
    {:else if registrationInfo === null}
      <p class="text-muted">{$_('common.loading')}</p>
    {:else if !registrationInfo.cloudMode}
      <p class="text-muted">{$_('createCompany.notOpenMessage')}</p>
      <a href="/" class="btn btn-secondary btn-block"
        >{$_('signup.backToLogin')}</a
      >
    {:else if submitted}
      <p class="text-muted">{$_('createCompany.sentConfirmation')}</p>
      <a href="/" class="btn btn-secondary btn-block"
        >{$_('signup.backToLogin')}</a
      >
    {:else}
      <p class="text-muted subtitle">{$_('createCompany.subtitle')}</p>

      <form onsubmit={handleSubmit}>
        <div class="field">
          <label for="create-company-name"
            >{$_('createCompany.companyNameLabel')}</label
          >
          <input
            id="create-company-name"
            class="input"
            type="text"
            bind:value={companyName}
            autocomplete="organization"
            required
          />
        </div>

        <div class="field">
          <label for="create-company-email"
            >{$_('createCompany.emailLabel')}</label
          >
          <input
            id="create-company-email"
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
            ? $_('createCompany.submitting')
            : $_('createCompany.submit')}
        </button>

        <a href="/" class="back-link">{$_('signup.backToLogin')}</a>
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
