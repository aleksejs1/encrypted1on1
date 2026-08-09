<script lang="ts">
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { decryptBlob, encryptBlob, generateAnketaKey, sealAnketaKey, unsealAnketaKey } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity';
  import { navigate } from '../router.svelte';
  import type { OutcomeItem } from '../anketa/outcomes';

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
  let myUserId = $state<string | null>(null);
  let loadError = $state<string | null>(null);

  let counterpartId = $state('');
  let myRole = $state<'employee' | 'manager'>('employee');
  let meetingDate = $state('');
  let submitting = $state(false);
  let submitError = $state<string | null>(null);

  const canSubmit = $derived(counterpartId !== '' && meetingDate !== '' && !submitting);

  $effect(() => {
    Promise.all([ensureUnlocked(), apiGet<UserSummary[]>('/api/users')])
      .then(([identity, allUsers]) => {
        myUserId = identity.userId;
        users = allUsers.filter((u) => u.id !== identity.userId);
      })
      .catch((error: unknown) => {
        loadError = error instanceof ApiError ? error.message : 'Could not load users.';
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
      if (!counterpart) throw new Error('Counterpart not found.');

      // Outcomes carry-forward (Phase 6c plan): goals carry forward server-side (plaintext,
      // no client involvement needed), but outcomes are still an encrypted blob, so unchecked
      // items from the pair's most recent archived anketa have to be decrypted and re-encrypted
      // here, client-side, before the new anketa exists. `GET /api/anketas` is already sorted by
      // meetingDate DESC, so the first archived match for this counterpart is the most recent one.
      let carriedOutcomes: OutcomeItem[] = [];
      const priorAnketas = await apiGet<AnketaSummary[]>('/api/anketas');
      const previous = priorAnketas.find((a) => a.counterpartId === counterpartId && a.archivedAt !== null);
      if (previous) {
        const previousDetail = await apiGet<AnketaDetailForCarry>(`/api/anketas/${previous.id}`);
        if (previousDetail.outcomesBlob) {
          const previousKey = await unsealAnketaKey(previousDetail.mySealedKey, identity.publicKey, identity.privateKey);
          const envelope = await decryptBlob<OutcomeItem[]>(previousDetail.outcomesBlob, previousKey);
          carriedOutcomes = envelope.data.filter((item) => !item.done);
        }
      }

      const anketaKey = await generateAnketaKey();
      const mySealedKey = await sealAnketaKey(anketaKey, identity.publicKey);
      const counterpartSealedKey = await sealAnketaKey(anketaKey, await fromBase64(counterpart.publicKey));

      const result = await apiPost<{ id: string }>('/api/anketas', {
        counterpartId,
        myRole,
        meetingDate: new Date(meetingDate).toISOString(),
        mySealedKey,
        counterpartSealedKey,
        ...(carriedOutcomes.length > 0 ? { outcomesBlob: await encryptBlob(carriedOutcomes, anketaKey) } : {}),
      });

      navigate(`/anketas/${result.id}`);
    } catch (error) {
      submitError = error instanceof ApiError ? error.message : 'Something went wrong.';
    } finally {
      submitting = false;
    }
  }
</script>

<main>
  <h1>New anketa</h1>

  {#if loadError}
    <p class="error">{loadError}</p>
  {:else}
    <form onsubmit={handleSubmit}>
      <label>
        Counterpart
        <select bind:value={counterpartId}>
          <option value="" disabled>Select a person…</option>
          {#each users as user (user.id)}
            <option value={user.id}>{user.email}</option>
          {/each}
        </select>
      </label>

      <fieldset>
        <legend>My role in this anketa</legend>
        <label><input type="radio" bind:group={myRole} value="employee" /> Employee</label>
        <label><input type="radio" bind:group={myRole} value="manager" /> Manager</label>
      </fieldset>

      <label>
        Meeting date
        <input type="date" bind:value={meetingDate} />
      </label>

      {#if submitError}
        <p class="error">{submitError}</p>
      {/if}

      <button type="submit" disabled={!canSubmit}>
        {submitting ? 'Creating…' : 'Create anketa'}
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
