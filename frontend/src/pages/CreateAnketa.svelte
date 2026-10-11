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
  import { formatDisplayDate } from '../datePreference.svelte';
  import { navigate } from '../router.svelte';
  import { carryForwardOutcomes } from '../anketa/outcomes';
  import { carryForwardTopics } from '../anketa/topics';
  import { sortByRecentCounterparts } from '../anketa/recentCounterparts';
  import { ANKETA_TEMPLATES, templatePickerKeys } from '../anketa/questions';
  import {
    customChoice,
    customTemplateIdOf,
    templateFields,
    type TemplateChoice,
  } from '../anketa/templateChoice';
  import { PATHS } from '../routes';
  import { pairChainState, pairRole } from '../anketa/pairChain';
  import {
    reportingLine,
    roleContradiction,
    type MyOrg,
    type ReportingLine,
  } from '../anketa/reportingLine';
  import {
    setJustCreated,
    takeCreateAnother,
    takeCreateWith,
  } from '../anketa/createDefaults';
  import type { Side } from '../anketa/questions';
  import { fullDisplayName } from '../userDisplay';
  import UserTypeahead from '../anketa/UserTypeahead.svelte';
  import DateInput from '../design/DateInput.svelte';

  type AnketaDetailForCarry = Pick<
    AnketaDetail,
    'mySealedKey' | 'outcomesBlob' | 'topicsBlob'
  >;

  let users = $state<UserSummary[]>([]);
  let priorAnketas = $state<AnketaSummary[]>([]);
  let loadError = $state<string | null>(null);
  // My manager and my direct reports (GitHub issue #269). Null if it couldn't be
  // loaded: the form then works with no badges and no warning, as it does in a
  // company that has set no reporting lines.
  let myOrg = $state<MyOrg | null>(null);

  // Set when this form was opened by "Create another" on a just-created
  // meeting's page (GitHub issue #198): template and periodicity start as
  // they were there.
  const another = takeCreateAnother();
  // Set when a pair's permanent link sent the user here to schedule the
  // pair's next meeting (GitHub issue #203).
  const presetCounterpartId = takeCreateWith();

  let counterpartId = $state('');
  // Null until the user picks, and null again whenever the colleague field
  // changes (setCounterpart()): a role is a statement about one pair, so it
  // is asked after the colleague, and the radios are disabled until one is
  // chosen. None is ever carried over from another meeting: not from the
  // pair's history, not from the last 1:1 created here and not by "Create
  // another". Any of them can be the wrong way round, and a preselected
  // radio is easy not to notice (GitHub issue #251).
  let myRole = $state<Side | null>(null);
  let templateChoice = $state<TemplateChoice>(
    another?.templateChoice ?? 'regular',
  );
  /**
   * The company's active templates (GitHub issue #144), listed after the
   * built-ins. Null if they couldn't be loaded: the built-ins still work.
   */
  let companyTemplates = $state<CompanyTemplate[] | null>(null);
  let isAdmin = $state(false);
  let meetingDate = $state('');
  // "Create another" may carry a periodicity its pair inherited; one the
  // radios below don't offer (a legacy value) isn't kept, since it would be
  // sent with nothing shown as selected.
  let periodicityDays = $state(
    another && [7, 14, 30].includes(another.periodicityDays)
      ? another.periodicityDays
      : 7,
  );
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
  const openAnketa = $derived(pairChain.openAnketa);
  const inheritedPeriodicityDays = $derived(pairChain.inheritedPeriodicityDays);

  // The role options name the colleague (GitHub issue #252): "who leads" is
  // easier to get right about a person than about "the manager".
  const counterpartName = $derived.by(() => {
    const counterpart = users.find((u) => u.id === counterpartId);
    return counterpart
      ? fullDisplayName(counterpart.displayName, counterpart.email)
      : null;
  });
  // Shown as a line of fact under the matching card. It never selects one:
  // for a pair whose roles are the wrong way round, "same as last time"
  // would repeat the mistake.
  const lastRole = $derived(pairRole(priorAnketas, counterpartId));
  // Said only when the clicked role is the opposite of the company's reporting
  // line. Like lastRole, it never selects a role, and it never disables "Create
  // 1:1": a 1:1 the other way round is allowed.
  const counterpartLine = $derived(reportingLine(myOrg, counterpartId));
  const roleWarning = $derived.by(() => {
    if (counterpartName === null) return null;
    const values = { name: counterpartName };
    switch (roleContradiction(counterpartLine, myRole)) {
      case 'leadingMyManager':
        return $_('createAnketa.roleWarningLeadingMyManager', { values });
      case 'ledByMyReport':
        return $_('createAnketa.roleWarningLedByMyReport', { values });
      default:
        return null;
    }
  });

  function badge(line: ReportingLine): string | null {
    switch (line) {
      case 'manager':
        return $_('createAnketa.badgeManager');
      case 'directReport':
        return $_('createAnketa.badgeDirectReport');
      default:
        return null;
    }
  }
  const badgeFor = (userId: string) => badge(reportingLine(myOrg, userId));
  const counterpartBadge = $derived(badge(counterpartLine));

  // Recent counterparts (from this user's own anketa history) surface at the top of the
  // typeahead's suggestion list, per the spec — no full-company-list scrolling every time.
  const sortedUsers = $derived(sortByRecentCounterparts(users, priorAnketas));

  // What the disabled "Create 1:1" is waiting for once there is a colleague
  // (before that, the role group says so), one thing at a time, in the
  // order of the form.
  const missingKey = $derived(
    counterpartId === ''
      ? null
      : myRole === null
        ? 'createAnketa.missingRole'
        : meetingDate === ''
          ? 'createAnketa.missingDate'
          : null,
  );

  const canSubmit = $derived(
    counterpartId !== '' && missingKey === null && !submitting,
  );

  // The typeahead also calls this with '' as soon as the user types again.
  function setCounterpart(id: string): void {
    if (id !== counterpartId) myRole = null;
    counterpartId = id;
  }

  // Cancels the two mount-time reads below on unmount — see GitHub issue #66.
  const readAbort = abortOnDestroy();

  // onMount (unlike an $effect) doesn't track reactive reads inside it, so
  // ensureUnlocked()'s synchronous read of getGeneration() can't re-trigger
  // this when an unrelated 401 elsewhere bumps the identity generation —
  // see App.svelte's own checkAuth() mount check and GitHub issue #62/#94.
  onMount(() => {
    // By itself, not in the Promise.all below: the form must not wait for it.
    void loadMyOrg().then((org) => (myOrg = org));
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
        // Only someone still in the company's list can be preselected.
        if (
          presetCounterpartId !== null &&
          counterpartId === '' &&
          users.some((u) => u.id === presetCounterpartId)
        ) {
          setCounterpart(presetCounterpartId);
        }
        // A company template kept by "Create another" that isn't in the
        // picker (archived since, or the list couldn't be loaded) can't stay
        // chosen unseen.
        if (!isOffered(templateChoice, templates)) templateChoice = 'regular';
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        loadError =
          error instanceof ApiError
            ? error.message
            : $_('createAnketa.errorLoad');
      });
  });

  /** Whether the picker has a radio for `choice`: any built-in, or a listed company template. */
  function isOffered(
    choice: TemplateChoice,
    templates: CompanyTemplate[] | null,
  ): boolean {
    return (
      customTemplateIdOf(choice) === null ||
      (templates?.some((t) => customChoice(t.id) === choice) ?? false)
    );
  }

  /** The company templates, or null (never a rejection) if they can't be loaded. */
  function loadCompanyTemplates(): Promise<CompanyTemplate[] | null> {
    return fetchCompanyTemplates(readAbort).catch(() => null);
  }

  /**
   * My reporting lines, or null (never a rejection, and never an answer of another
   * shape): they must not block creating a 1:1.
   */
  function loadMyOrg(): Promise<MyOrg | null> {
    return apiGet<MyOrg>('/api/me/org', { signal: readAbort })
      .then((org) =>
        Array.isArray(org?.directReports) &&
        org.directReports.every((r) => typeof r?.id === 'string')
          ? org
          : null,
      )
      .catch(() => null);
  }

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    const role = myRole;
    if (!canSubmit || role === null) return;
    // The form as submitted, read once before the first await: it stays
    // editable while this runs, and a change made meanwhile (another
    // colleague, most of all) must not end up in a request built partly
    // from the old values. Below, read the form only through this.
    const form = {
      role,
      counterpartId,
      carryFrom: openAnketa ? undefined : previousAnketa,
      inheritedDays: inheritedPeriodicityDays,
      templateChoice,
      meetingDate,
      periodicityDays,
    };

    submitting = true;
    submitError = null;
    try {
      const identity = await ensureUnlocked();
      const counterpart = users.find((u) => u.id === form.counterpartId);
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
      // The same for the topics not yet discussed (GitHub issue #206).
      let topicsBlob: string | undefined;
      if (form.carryFrom) {
        try {
          const previousDetail = await apiGet<AnketaDetailForCarry>(
            `/api/anketas/${form.carryFrom.id}`,
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
          topicsBlob = await carryForwardTopics(
            previousDetail.topicsBlob,
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

      // What "Create another" keeps, with the periodicity the pair inherited
      // if it wasn't asked.
      const settings = {
        templateChoice: form.templateChoice,
        periodicityDays: form.inheritedDays ?? form.periodicityDays,
      };
      const result = await apiPost<{ id: string }>('/api/anketas', {
        counterpartId: form.counterpartId,
        myRole: form.role,
        meetingDate: new Date(form.meetingDate).toISOString(),
        mySealedKey,
        counterpartSealedKey,
        // Periodicity (Phase 6d) is only asked when there's nothing to inherit — see
        // inheritedPeriodicityDays; the server ignores it otherwise anyway.
        ...(form.inheritedDays !== null
          ? {}
          : { periodicityDays: form.periodicityDays }),
        ...(outcomesBlob ? { outcomesBlob } : {}),
        ...(topicsBlob ? { topicsBlob } : {}),
        // Unlike periodicity, template choice is never inherited-only — the picker is
        // shown (and sent) on every creation, continuing pair or not, since a manager
        // may deliberately want an ad-hoc template mid-cadence.
        ...templateFields(form.templateChoice),
      });

      setJustCreated(result.id, settings);
      navigate(`/anketas/${result.id}`);
    } catch (error) {
      submitError =
        error instanceof ApiError
          ? error.message
          : $_('createAnketa.genericError');
      // The chosen company template was archived since this page loaded
      // (#133 §7.2): back to Regular with a fresh list; everything else the
      // user filled in stays, a template they chose meanwhile included.
      if (
        error instanceof ApiError &&
        (error.body as { code?: string } | null)?.code ===
          'template_unavailable'
      ) {
        // At once, not after the reload below, and whatever that returns.
        if (templateChoice === form.templateChoice) templateChoice = 'regular';
        const templates = await loadCompanyTemplates();
        companyTemplates = templates;
        // One chosen while the request or the reload ran may be gone too.
        if (!isOffered(templateChoice, templates)) templateChoice = 'regular';
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
          bind:value={() => counterpartId, setCounterpart}
          placeholder={$_('createAnketa.counterpartPlaceholder')}
          noResultsText={$_('createAnketa.counterpartNoResults')}
          {badgeFor}
        />
        <!-- The same fact as in the list, which is closed once someone is
             picked, and never opens for a colleague preselected by a link. -->
        {#if counterpartBadge !== null}
          <p class="counterpart-fact">
            <span class="tag tag-neutral">{counterpartBadge}</span>
          </p>
        {/if}
      </div>

      <fieldset
        class="card role-card"
        disabled={counterpartId === ''}
        aria-describedby={counterpartId === '' ? 'role-needs-colleague' : null}
      >
        <legend>{$_('createAnketa.roleLegend')}</legend>
        <div class="option-list">
          <div class="option">
            <label class="radio">
              <input
                type="radio"
                bind:group={myRole}
                value="manager"
                aria-describedby={myRole === 'manager'
                  ? 'role-manager-about role-warning'
                  : 'role-manager-about'}
              /><span class="dot"></span>
              {$_('createAnketa.roleManagerOption')}
            </label>
            <div id="role-manager-about">
              {#if counterpartName !== null}
                <p class="text-muted option-description">
                  {$_('createAnketa.roleManagerDescription', {
                    values: { name: counterpartName },
                  })}
                </p>
              {/if}
              {#if lastRole === 'manager'}
                <p class="option-description">
                  {$_('createAnketa.roleLastManager')}
                </p>
              {/if}
            </div>
          </div>
          <div class="option">
            <label class="radio">
              <input
                type="radio"
                bind:group={myRole}
                value="employee"
                aria-describedby={myRole === 'employee'
                  ? 'role-employee-about role-warning'
                  : 'role-employee-about'}
              /><span class="dot"></span>
              <span class="option-name">
                {counterpartName === null
                  ? $_('createAnketa.roleEmployeeOptionNoName')
                  : $_('createAnketa.roleEmployeeOption', {
                      values: { name: counterpartName },
                    })}
              </span>
            </label>
            <div id="role-employee-about">
              {#if counterpartName !== null}
                <p class="text-muted option-description">
                  {$_('createAnketa.roleEmployeeDescription', {
                    values: { name: counterpartName },
                  })}
                </p>
              {/if}
              {#if lastRole === 'employee'}
                <p class="option-description">
                  {$_('createAnketa.roleLastEmployee')}
                </p>
              {/if}
            </div>
          </div>
        </div>
        <!-- Always in the DOM, like the "missing" line below, so that it is
             announced when it appears. -->
        <p
          class="role-warning"
          class:shown={roleWarning !== null}
          id="role-warning"
          role="status"
        >
          {roleWarning ?? ''}
        </p>
        {#if counterpartId === ''}
          <p class="text-muted role-hint" id="role-needs-colleague">
            {$_('createAnketa.roleNeedsCounterpart')}
          </p>
        {/if}
      </fieldset>

      <fieldset class="card">
        <legend>{$_('createAnketa.templateLegend')}</legend>
        <div class="option-list">
          {#each ANKETA_TEMPLATES as key (key)}
            {@const pickerKeys = templatePickerKeys(key)}
            <div class="option">
              <label class="radio">
                <input
                  type="radio"
                  bind:group={templateChoice}
                  value={key}
                /><span class="dot"></span>
                {$_(pickerKeys.labelKey)}
              </label>
              <p class="text-muted option-description">
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
            <div class="option-list">
              {#each companyTemplates as template (template.id)}
                <div class="option">
                  <label class="radio">
                    <input
                      type="radio"
                      bind:group={templateChoice}
                      value={customChoice(template.id)}
                    /><span class="dot"></span>
                    <span class="template-name">{template.name}</span>
                  </label>
                  {#if template.description}
                    <p class="text-muted option-description">
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

      {#if openAnketa}
        <p class="text-muted periodicity-note">
          {$_('createAnketa.pairHasOpenAnketa')}
          <!-- For every type, Regular included: a pair on Quick check-ins or
               on a company template stays on it by default. -->
          {$_('createAnketa.pairHasOpenAnketaHowToSwitch')}
          <a class="open-anketa-link" href="/anketas/{openAnketa.id}"
            >{$_('createAnketa.pairOpenAnketaLink', {
              values: { date: formatDisplayDate(openAnketa.meetingDate) },
            })}</a
          >
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
      {:else if counterpartId && !openAnketa}
        <p class="text-muted periodicity-note">
          {$_('createAnketa.periodicityInherited')}
        </p>
      {/if}

      {#if submitError}
        <p class="banner-error">{submitError}</p>
      {/if}

      <div class="submit">
        <button
          type="submit"
          class="btn btn-primary btn-block"
          disabled={!canSubmit}
        >
          {submitting
            ? $_('createAnketa.submitting')
            : $_('createAnketa.submit')}
        </button>
        <!-- A disabled button is skipped by Tab, so what is still missing
             is said here, where it is announced as the form is filled in.
             Always in the DOM and never display:none, like
             ConnectionBanner's live region. -->
        <p
          class="text-muted missing"
          class:shown={missingKey !== null && !submitting}
          role="status"
        >
          {#if missingKey !== null && !submitting}{$_(missingKey)}{/if}
        </p>
      </div>
    </form>
  {/if}
</main>

<style>
  /* Scoped to this card: .radio is shared, and elsewhere a disabled one
     (a read-only answer mid-save) must not dim. */
  .role-card:disabled .radio {
    cursor: default;
    opacity: 0.55;
  }

  .role-card:disabled .radio:hover .dot {
    border-color: var(--color-divider);
  }

  .role-hint {
    margin: 8px 0 0;
    font-size: 13px;
  }

  .counterpart-fact {
    margin: 6px 0 0;
  }

  .role-warning {
    margin: 0;
    font-size: 13px;
    overflow-wrap: anywhere;
  }

  .role-warning.shown {
    margin-top: 10px;
    padding: 8px 10px;
    border-radius: var(--radius-sm);
    background: color-mix(in srgb, var(--color-accent) 14%, transparent);
  }

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

  .option-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .option {
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
  .option-name,
  .option-description {
    overflow-wrap: anywhere;
  }

  .option-description {
    /* Lines up under the radio label text, past the 16px dot + 8px gap
       components.css's .radio already uses. */
    margin: 0 0 0 24px;
    font-size: 12px;
  }

  .missing {
    font-size: 12px;
    margin: 0;
    text-align: center;
  }

  .missing.shown {
    margin-top: 8px;
  }

  .periodicity-note {
    font-size: 12px;
    margin: 0;
  }

  .open-anketa-link {
    display: block;
    margin-top: 4px;
  }
</style>
