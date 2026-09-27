<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { onMount } from 'svelte';
  import { apiGet, apiGetAllPages, apiPost, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import type {
    AnketaDetail,
    AnketaSummary,
    CompanyTemplate,
    UserSummary,
  } from '../api/types';
  import { fetchCompanyTemplates } from '../api/templates';
  import {
    generateAnketaKey,
    sealAnketaKey,
    unsealAnketaKey,
  } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import { navigate } from '../router.svelte';
  import { carryForwardOutcomes } from '../anketa/outcomes';
  import { sortByRecentCounterparts } from '../anketa/recentCounterparts';
  import { ANKETA_TEMPLATES, templatePickerKeys } from '../anketa/questions';
  import {
    customChoice,
    templateFields,
    type TemplateChoice,
  } from '../anketa/templateChoice';
  import { PATHS } from '../routes';
  import { pairChainState } from '../anketa/pairChain';
  import UserTypeahead from '../anketa/UserTypeahead.svelte';
  import DateInput from '../design/DateInput.svelte';

  type AnketaDetailForCarry = Pick<
    AnketaDetail,
    'mySealedKey' | 'outcomesBlob'
  >;

  let users = $state<UserSummary[]>([]);
  let priorAnketas = $state<AnketaSummary[]>([]);
  let loadError = $state<string | null>(null);

  let counterpartId = $state('');
  let myRole = $state<'employee' | 'manager'>('employee');
  let templateChoice = $state<TemplateChoice>('regular');
  /**
   * The company's active templates (GitHub issue #144), listed after the
   * built-ins. Null if they couldn't be loaded: the built-ins still work.
   */
  let companyTemplates = $state<CompanyTemplate[] | null>(null);
  let isAdmin = $state(false);
  let meetingDate = $state('');
  let periodicityDays = $state(7);
  let submitting = $state(false);
  let submitError = $state<string | null>(null);

  // The pair's chain state — see pairChainState(). previousAnketa is the outcomes
  // carry-forward source; inheritedPeriodicityDays decides whether periodicity needs
  // asking. GitHub issue #111: with an open chain anketa, this one is created as a
  // one-off — no carry-forward, no auto-recreated successor. The server decides that for
  // itself (AnketaController::create); this just skips the outcomes re-encryption (the
  // server would drop it for a one-off) and tells the user up front. If this list is
  // stale and the open anketa got archived in the meantime, the server carries goals
  // from it but gets no outcomes — computing them here from previousAnketa wouldn't
  // help, since that's then an older anketa than the one the server carries from.
  const pairChain = $derived(pairChainState(priorAnketas, counterpartId));
  const previousAnketa = $derived(pairChain.previousAnketa);
  const pairHasOpenAnketa = $derived(pairChain.openAnketa !== undefined);
  const inheritedPeriodicityDays = $derived(pairChain.inheritedPeriodicityDays);

  // Recent counterparts (from this user's own anketa history) surface at the top of the
  // typeahead's suggestion list, per the spec — no full-company-list scrolling every time.
  const sortedUsers = $derived(sortByRecentCounterparts(users, priorAnketas));

  const canSubmit = $derived(
    counterpartId !== '' && meetingDate !== '' && !submitting,
  );

  // Cancels the two mount-time reads below on unmount — see GitHub issue #66.
  const readAbort = abortOnDestroy();

  // onMount (unlike an $effect) doesn't track reactive reads inside it, so
  // ensureUnlocked()'s synchronous read of getGeneration() can't re-trigger
  // this when an unrelated 401 elsewhere bumps the identity generation —
  // see App.svelte's own checkAuth() mount check and GitHub issue #62/#94.
  onMount(() => {
    Promise.all([
      ensureUnlocked(),
      apiGetAllPages<UserSummary>('/api/users', { signal: readAbort }),
      apiGet<AnketaSummary[]>('/api/anketas', { signal: readAbort }),
      loadCompanyTemplates(),
    ])
      .then(([identity, allUsers, allAnketas, templates]) => {
        users = allUsers.filter((u) => u.id !== identity.userId);
        priorAnketas = allAnketas;
        isAdmin = identity.isAdmin;
        companyTemplates = templates;
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        loadError =
          error instanceof ApiError
            ? error.message
            : $_('createAnketa.errorLoad');
      });
  });

  /** The company templates, or null (never a rejection) if they can't be loaded. */
  function loadCompanyTemplates(): Promise<CompanyTemplate[] | null> {
    return fetchCompanyTemplates(readAbort).catch(() => null);
  }

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!canSubmit) return;

    submitting = true;
    submitError = null;
    try {
      const identity = await ensureUnlocked();
      const counterpart = users.find((u) => u.id === counterpartId);
      if (!counterpart)
        throw new Error($_('createAnketa.errorCounterpartNotFound'));

      const anketaKey = await generateAnketaKey();
      const mySealedKey = await sealAnketaKey(anketaKey, identity.publicKey);
      const counterpartSealedKey = await sealAnketaKey(
        anketaKey,
        await fromBase64(counterpart.publicKey),
      );

      // Outcomes carry-forward (Phase 6c plan): goals carry forward server-side (plaintext,
      // no client involvement needed), but outcomes are still an encrypted blob, so unchecked
      // items from the pair's most recent archived anketa have to be decrypted and re-encrypted
      // here, client-side, before the new anketa exists.
      let outcomesBlob: string | undefined;
      if (previousAnketa && !pairHasOpenAnketa) {
        try {
          const previousDetail = await apiGet<AnketaDetailForCarry>(
            `/api/anketas/${previousAnketa.id}`,
          );
          const previousKey = await unsealAnketaKey(
            previousDetail.mySealedKey,
            identity.publicKey,
            identity.privateKey,
          );
          outcomesBlob = await carryForwardOutcomes(
            previousDetail.outcomesBlob,
            previousKey,
            anketaKey,
          );
        } catch {
          // The previous anketa's key may no longer unseal (e.g. after a password
          // reset — see ResetPassword.svelte). Forgetting a password shouldn't also
          // block starting a fresh anketa with the same counterpart, so this is
          // treated the same as having no previous anketa to carry forward from —
          // periodicity inheritance below is unaffected, since it never depends on
          // successfully reading the previous anketa's key.
        }
      }

      const result = await apiPost<{ id: string }>('/api/anketas', {
        counterpartId,
        myRole,
        meetingDate: new Date(meetingDate).toISOString(),
        mySealedKey,
        counterpartSealedKey,
        // Periodicity (Phase 6d) is only asked when there's nothing to inherit — see
        // inheritedPeriodicityDays; the server ignores it otherwise anyway.
        ...(inheritedPeriodicityDays !== null ? {} : { periodicityDays }),
        ...(outcomesBlob ? { outcomesBlob } : {}),
        // Unlike periodicity, template choice is never inherited-only — the picker is
        // shown (and sent) on every creation, continuing pair or not, since a manager
        // may deliberately want an ad-hoc template mid-cadence.
        ...templateFields(templateChoice),
      });

      navigate(`/anketas/${result.id}`);
    } catch (error) {
      submitError =
        error instanceof ApiError
          ? error.message
          : $_('createAnketa.genericError');
      // The chosen company template was archived since this page loaded
      // (#133 §7.2): back to Regular with a fresh list; everything else the
      // user filled in stays.
      if (
        error instanceof ApiError &&
        (error.body as { code?: string } | null)?.code ===
          'template_unavailable'
      ) {
        templateChoice = 'regular';
        companyTemplates = await loadCompanyTemplates();
      }
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <h1>{$_('createAnketa.title')}</h1>

  {#if loadError}
    <p class="banner-error">{loadError}</p>
  {:else}
    <form onsubmit={handleSubmit}>
      <div class="field typeahead-field">
        <label for="counterpart">{$_('createAnketa.counterpartLabel')}</label>
        <UserTypeahead
          users={sortedUsers}
          bind:value={counterpartId}
          placeholder={$_('createAnketa.counterpartPlaceholder')}
          noResultsText={$_('createAnketa.counterpartNoResults')}
        />
      </div>

      <fieldset class="card">
        <legend>{$_('createAnketa.roleLegend')}</legend>
        <div class="radio-row">
          <label class="radio">
            <input type="radio" bind:group={myRole} value="employee" /><span
              class="dot"
            ></span>
            {$_('common.roleEmployee')}
          </label>
          <label class="radio">
            <input type="radio" bind:group={myRole} value="manager" /><span
              class="dot"
            ></span>
            {$_('common.roleManager')}
          </label>
        </div>
      </fieldset>

      <fieldset class="card">
        <legend>{$_('createAnketa.templateLegend')}</legend>
        <div class="template-options">
          {#each ANKETA_TEMPLATES as key (key)}
            {@const pickerKeys = templatePickerKeys(key)}
            <div class="template-option">
              <label class="radio">
                <input
                  type="radio"
                  bind:group={templateChoice}
                  value={key}
                /><span class="dot"></span>
                {$_(pickerKeys.labelKey)}
              </label>
              <p class="text-muted template-description">
                {$_(pickerKeys.descriptionKey)}
              </p>
            </div>
          {/each}
        </div>
        {#if companyTemplates}
          <p class="company-templates-heading">
            {$_('createAnketa.companyTemplatesHeading')}
          </p>
          {#if companyTemplates.length === 0}
            {#if isAdmin}
              <a class="company-templates-empty" href={PATHS.adminTemplateNew}
                >{$_('createAnketa.companyTemplatesCreate')}</a
              >
            {:else}
              <p class="text-muted company-templates-empty">
                {$_('createAnketa.companyTemplatesNone')}
              </p>
            {/if}
          {:else}
            <div class="template-options">
              {#each companyTemplates as template (template.id)}
                <div class="template-option">
                  <label class="radio">
                    <input
                      type="radio"
                      bind:group={templateChoice}
                      value={customChoice(template.id)}
                    /><span class="dot"></span>
                    <span class="template-name">{template.name}</span>
                  </label>
                  {#if template.description}
                    <p class="text-muted template-description">
                      {template.description}
                    </p>
                  {/if}
                </div>
              {/each}
            </div>
          {/if}
        {/if}
      </fieldset>

      <div class="field">
        <label for="meeting-date">{$_('createAnketa.meetingDateLabel')}</label>
        <DateInput id="meeting-date" bind:value={meetingDate} />
      </div>

      {#if counterpartId && pairHasOpenAnketa}
        <p class="text-muted periodicity-note">
          {$_('createAnketa.pairHasOpenAnketa')}
          {#if templateChoice !== 'regular'}
            {$_('createAnketa.pairHasOpenAnketaHowToSwitch')}
          {/if}
        </p>
      {/if}

      {#if counterpartId && inheritedPeriodicityDays === null}
        <fieldset class="card">
          <legend>{$_('createAnketa.periodicityLabel')}</legend>
          <div class="radio-row">
            <label class="radio">
              <input type="radio" bind:group={periodicityDays} value={7} /><span
                class="dot"
              ></span>
              {$_('createAnketa.periodicityWeekly')}
            </label>
            <label class="radio">
              <input
                type="radio"
                bind:group={periodicityDays}
                value={14}
              /><span class="dot"></span>
              {$_('createAnketa.periodicityBiweekly')}
            </label>
            <label class="radio">
              <input
                type="radio"
                bind:group={periodicityDays}
                value={30}
              /><span class="dot"></span>
              {$_('createAnketa.periodicityMonthly')}
            </label>
          </div>
        </fieldset>
      {:else if counterpartId && !pairHasOpenAnketa}
        <p class="text-muted periodicity-note">
          {$_('createAnketa.periodicityInherited')}
        </p>
      {/if}

      {#if submitError}
        <p class="banner-error">{submitError}</p>
      {/if}

      <button
        type="submit"
        class="btn btn-primary btn-block"
        disabled={!canSubmit}
      >
        {submitting ? $_('createAnketa.submitting') : $_('createAnketa.submit')}
      </button>
    </form>
  {/if}
</main>

<style>
  main {
    max-width: 32rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 18px;
  }

  .typeahead-field {
    position: relative;
  }

  fieldset.card {
    border: none;
  }

  fieldset legend {
    font-size: 13px;
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
    padding: 0 4px;
  }

  .radio-row {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
  }

  .template-options {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .template-option {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  .company-templates-heading {
    font-size: 13px;
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
    margin: 16px 0 8px;
  }

  .company-templates-empty {
    font-size: 12px;
    margin: 0;
  }

  .template-name,
  .template-description {
    overflow-wrap: anywhere;
  }

  .template-description {
    /* Lines up under the radio label text, past the 16px dot + 8px gap
       components.css's .radio already uses. */
    margin: 0 0 0 24px;
    font-size: 12px;
  }

  .periodicity-note {
    font-size: 12px;
    margin: 0;
  }
</style>
