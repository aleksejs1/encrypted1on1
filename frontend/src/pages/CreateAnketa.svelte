<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiGetAllPages, apiPost, ApiError } from '../api/client';
  import type { AnketaDetail, AnketaSummary, UserSummary } from '../api/types';
  import {
    generateAnketaKey,
    sealAnketaKey,
    unsealAnketaKey,
  } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity';
  import { navigate } from '../router.svelte';
  import { carryForwardOutcomes } from '../anketa/outcomes';
  import { sortByRecentCounterparts } from '../anketa/recentCounterparts';
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
  let meetingDate = $state('');
  let periodicityDays = $state(7);
  let submitting = $state(false);
  let submitError = $state<string | null>(null);

  // The pair's most recent archived anketa, if any — `GET /api/anketas` is already sorted
  // by meetingDate DESC, so the first archived match is the most recent one. Its presence
  // decides whether periodicity needs asking (new pair) or is inherited server-side
  // (continuing pair, see AnketaController::create — Phase 6d).
  const previousAnketa = $derived(
    priorAnketas.find(
      (a) => a.counterpartId === counterpartId && a.archivedAt !== null,
    ),
  );

  // Recent counterparts (from this user's own anketa history) surface at the top of the
  // typeahead's suggestion list, per the spec — no full-company-list scrolling every time.
  const sortedUsers = $derived(sortByRecentCounterparts(users, priorAnketas));

  const canSubmit = $derived(
    counterpartId !== '' && meetingDate !== '' && !submitting,
  );

  $effect(() => {
    Promise.all([
      ensureUnlocked(),
      apiGetAllPages<UserSummary>('/api/users'),
      apiGet<AnketaSummary[]>('/api/anketas'),
    ])
      .then(([identity, allUsers, allAnketas]) => {
        users = allUsers.filter((u) => u.id !== identity.userId);
        priorAnketas = allAnketas;
      })
      .catch((error: unknown) => {
        loadError =
          error instanceof ApiError
            ? error.message
            : $_('createAnketa.errorLoad');
      });
  });

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
      if (previousAnketa) {
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
          // periodicity inheritance below is unaffected, since it only depends on
          // previousAnketa existing, not on successfully reading its key.
        }
      }

      const result = await apiPost<{ id: string }>('/api/anketas', {
        counterpartId,
        myRole,
        meetingDate: new Date(meetingDate).toISOString(),
        mySealedKey,
        counterpartSealedKey,
        // Periodicity (Phase 6d) is only asked for a brand-new pair — a continuing pair
        // inherits it server-side from previousAnketa, so this is ignored there anyway.
        ...(previousAnketa ? {} : { periodicityDays }),
        ...(outcomesBlob ? { outcomesBlob } : {}),
      });

      navigate(`/anketas/${result.id}`);
    } catch (error) {
      submitError =
        error instanceof ApiError
          ? error.message
          : $_('createAnketa.genericError');
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

      <div class="field">
        <label for="meeting-date">{$_('createAnketa.meetingDateLabel')}</label>
        <DateInput id="meeting-date" bind:value={meetingDate} />
      </div>

      {#if counterpartId && !previousAnketa}
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
      {:else if counterpartId && previousAnketa}
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

  .periodicity-note {
    font-size: 12px;
    margin: 0;
  }
</style>
