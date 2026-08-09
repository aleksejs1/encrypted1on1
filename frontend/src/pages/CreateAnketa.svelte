<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { generateAnketaKey, sealAnketaKey, unsealAnketaKey } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity';
  import { navigate } from '../router.svelte';
  import { carryForwardOutcomes } from '../anketa/outcomes';
  import UserTypeahead from '../anketa/UserTypeahead.svelte';

  interface UserSummary {
    id: string;
    email: string;
    publicKey: string;
  }

  interface AnketaSummary {
    id: string;
    counterpartId: string;
    meetingDate: string;
    archivedAt: string | null;
  }

  interface AnketaDetailForCarry {
    mySealedKey: string;
    outcomesBlob: string | null;
  }

  let users = $state<UserSummary[]>([]);
  let priorAnketas = $state<AnketaSummary[]>([]);
  let myUserId = $state<string | null>(null);
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
  const previousAnketa = $derived(priorAnketas.find((a) => a.counterpartId === counterpartId && a.archivedAt !== null));

  const canSubmit = $derived(counterpartId !== '' && meetingDate !== '' && !submitting);

  $effect(() => {
    Promise.all([ensureUnlocked(), apiGet<UserSummary[]>('/api/users'), apiGet<AnketaSummary[]>('/api/anketas')])
      .then(([identity, allUsers, allAnketas]) => {
        myUserId = identity.userId;
        users = allUsers.filter((u) => u.id !== identity.userId);
        priorAnketas = allAnketas;
      })
      .catch((error: unknown) => {
        loadError = error instanceof ApiError ? error.message : $_('createAnketa.errorLoad');
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
      if (!counterpart) throw new Error($_('createAnketa.errorCounterpartNotFound'));

      const anketaKey = await generateAnketaKey();
      const mySealedKey = await sealAnketaKey(anketaKey, identity.publicKey);
      const counterpartSealedKey = await sealAnketaKey(anketaKey, await fromBase64(counterpart.publicKey));

      // Outcomes carry-forward (Phase 6c plan): goals carry forward server-side (plaintext,
      // no client involvement needed), but outcomes are still an encrypted blob, so unchecked
      // items from the pair's most recent archived anketa have to be decrypted and re-encrypted
      // here, client-side, before the new anketa exists.
      let outcomesBlob: string | undefined;
      if (previousAnketa) {
        const previousDetail = await apiGet<AnketaDetailForCarry>(`/api/anketas/${previousAnketa.id}`);
        const previousKey = await unsealAnketaKey(previousDetail.mySealedKey, identity.publicKey, identity.privateKey);
        outcomesBlob = await carryForwardOutcomes(previousDetail.outcomesBlob, previousKey, anketaKey);
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
      submitError = error instanceof ApiError ? error.message : $_('createAnketa.genericError');
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <h1>{$_('createAnketa.title')}</h1>

  {#if loadError}
    <p class="error">{loadError}</p>
  {:else}
    <form onsubmit={handleSubmit}>
      <label>
        {$_('createAnketa.counterpartLabel')}
        <UserTypeahead
          users={users}
          bind:value={counterpartId}
          placeholder={$_('createAnketa.counterpartPlaceholder')}
          noResultsText={$_('createAnketa.counterpartNoResults')}
        />
      </label>

      <fieldset>
        <legend>{$_('createAnketa.roleLegend')}</legend>
        <label><input type="radio" bind:group={myRole} value="employee" /> {$_('common.roleEmployee')}</label>
        <label><input type="radio" bind:group={myRole} value="manager" /> {$_('common.roleManager')}</label>
      </fieldset>

      <label>
        {$_('createAnketa.meetingDateLabel')}
        <input type="date" bind:value={meetingDate} />
      </label>

      {#if counterpartId && !previousAnketa}
        <label>
          {$_('createAnketa.periodicityLabel')}
          <select bind:value={periodicityDays}>
            <option value={7}>{$_('createAnketa.periodicityWeekly')}</option>
            <option value={14}>{$_('createAnketa.periodicityBiweekly')}</option>
            <option value={30}>{$_('createAnketa.periodicityMonthly')}</option>
          </select>
        </label>
      {/if}

      {#if submitError}
        <p class="error">{submitError}</p>
      {/if}

      <button type="submit" disabled={!canSubmit}>
        {submitting ? $_('createAnketa.submitting') : $_('createAnketa.submit')}
      </button>
    </form>
  {/if}
</main>

<style>
  main {
    max-width: 24rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  form {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
  }

  label {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  fieldset {
    display: flex;
    gap: 1rem;
    align-items: center;
    border: 1px solid #ddd;
    border-radius: 0.25rem;
  }

  fieldset label {
    flex-direction: row;
    align-items: center;
    gap: 0.35rem;
  }

  .error {
    color: #c0392b;
  }
</style>
