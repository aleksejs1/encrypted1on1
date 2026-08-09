<script lang="ts">
  import { apiGet, apiPost, ApiError } from '../api/client';
  import { generateAnketaKey, sealAnketaKey } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity';
  import { navigate } from '../router.svelte';

  interface UserSummary {
    id: string;
    email: string;
    publicKey: string;
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

      const anketaKey = await generateAnketaKey();
      const mySealedKey = await sealAnketaKey(anketaKey, identity.publicKey);
      const counterpartSealedKey = await sealAnketaKey(anketaKey, await fromBase64(counterpart.publicKey));

      const result = await apiPost<{ id: string }>('/api/anketas', {
        counterpartId,
        myRole,
        meetingDate: new Date(meetingDate).toISOString(),
        mySealedKey,
        counterpartSealedKey,
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
