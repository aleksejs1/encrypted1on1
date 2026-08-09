<script lang="ts">
  import { apiGet, apiPost, apiPut, ApiError } from '../api/client';
  import AnswerField from '../anketa/AnswerField.svelte';
  import { QUESTIONS_BY_SIDE, type Side, type Answers } from '../anketa/questions';
  import { decryptBlob, encryptBlob, unsealAnketaKey } from '../crypto/anketaKey';
  import { ensureUnlocked } from '../crypto/identity';
  import { loadMasterKey } from '../crypto/session';

  const { id }: { id: string } = $props();

  interface AnketaDetail {
    id: string;
    myRole: Side;
    counterpartEmail: string;
    meetingDate: string;
    archivedAt: string | null;
    mySealedKey: string;
    employeeBlob: string | null;
    employeePublishedAt: string | null;
    managerBlob: string | null;
    managerPublishedAt: string | null;
  }

  let loadError = $state<string | null>(null);
  let detail = $state<AnketaDetail | null>(null);
  let counterpartSide = $state<Side | null>(null);
  let anketaKey = $state<Uint8Array | null>(null);
  let masterKey = $state<Uint8Array | null>(null);

  let myAnswers = $state<Answers>({});
  let counterpartAnswers = $state<Answers | null>(null);
  let myPublished = $state(false);
  let counterpartPublished = $state(false);
  let archived = $state(false);

  let saveState = $state<'idle' | 'saving' | 'saved' | 'error'>('idle');
  let publishing = $state(false);
  let archiving = $state(false);
  let actionError = $state<string | null>(null);

  let saveTimer: ReturnType<typeof setTimeout> | undefined;
  let loaded = false;

  $effect(() => {
    void id;
    load();
  });

  async function load() {
    try {
      const [identity, mk, anketa] = await Promise.all([
        ensureUnlocked(),
        loadMasterKey(),
        apiGet<AnketaDetail>(`/api/anketas/${id}`),
      ]);
      if (!mk) throw new Error('Not logged in.');

      detail = anketa;
      masterKey = mk;
      counterpartSide = anketa.myRole === 'employee' ? 'manager' : 'employee';
      archived = anketa.archivedAt !== null;

      const key = await unsealAnketaKey(anketa.mySealedKey, identity.publicKey, identity.privateKey);
      anketaKey = key;

      const myBlob = anketa.myRole === 'employee' ? anketa.employeeBlob : anketa.managerBlob;
      const myPublishedAt =
        anketa.myRole === 'employee' ? anketa.employeePublishedAt : anketa.managerPublishedAt;
      myPublished = myPublishedAt !== null;
      if (myBlob) {
        const envelope = await decryptBlob<Answers>(myBlob, myPublished ? key : mk);
        myAnswers = envelope.data;
      }

      const counterpartBlob =
        anketa.myRole === 'employee' ? anketa.managerBlob : anketa.employeeBlob;
      const counterpartPublishedAt =
        anketa.myRole === 'employee' ? anketa.managerPublishedAt : anketa.employeePublishedAt;
      counterpartPublished = counterpartPublishedAt !== null;
      if (counterpartBlob && counterpartPublished) {
        const envelope = await decryptBlob<Answers>(counterpartBlob, key);
        counterpartAnswers = envelope.data;
      }

      loaded = true;
    } catch (error) {
      loadError = error instanceof ApiError ? error.message : 'Could not load this anketa.';
    }
  }

  function scheduleSave() {
    if (!loaded || myPublished || !masterKey) return;
    saveState = 'saving';
    clearTimeout(saveTimer);
    saveTimer = setTimeout(saveDraft, 1000);
  }

  async function saveDraft() {
    if (!masterKey) return;
    try {
      const blob = await encryptBlob(myAnswers, masterKey);
      await apiPut(`/api/anketas/${id}/draft`, { blob });
      saveState = 'saved';
    } catch {
      saveState = 'error';
    }
  }

  async function handlePublish() {
    if (!anketaKey) return;
    publishing = true;
    actionError = null;
    try {
      clearTimeout(saveTimer);
      const blob = await encryptBlob(myAnswers, anketaKey);
      await apiPost(`/api/anketas/${id}/publish`, { blob });
      myPublished = true;
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not publish.';
    } finally {
      publishing = false;
    }
  }

  async function handleArchive() {
    archiving = true;
    actionError = null;
    try {
      await apiPost(`/api/anketas/${id}/archive`, {});
      archived = true;
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not archive.';
    } finally {
      archiving = false;
    }
  }

  // Reactive autosave: fires whenever myAnswers changes (property-level mutations from AnswerField included).
  $effect(() => {
    void JSON.stringify(myAnswers);
    scheduleSave();
  });
</script>

<main>
  {#if loadError}
    <p class="error">{loadError}</p>
  {:else if !detail}
    <p>Loading…</p>
  {:else}
    <h1>Anketa with {detail.counterpartEmail}</h1>
    <p class="meta">
      Meeting: {new Date(detail.meetingDate).toLocaleDateString()}
      {#if archived}<span class="badge">archived</span>{/if}
    </p>

    <section>
      <h2>
        My side ({detail.myRole})
        {#if myPublished}<span class="badge">published</span>{/if}
      </h2>
      {#each QUESTIONS_BY_SIDE[detail.myRole] as question (question.id)}
        <fieldset>
          <legend>{question.title}</legend>
          {#each question.fields as field (field.id)}
            <AnswerField {field} bind:value={myAnswers[field.id]} readonly={myPublished} />
          {/each}
        </fieldset>
      {/each}

      {#if !myPublished}
        <p class="save-state">
          {#if saveState === 'saving'}Saving…{:else if saveState === 'saved'}Saved.{:else if saveState === 'error'}Could
            not save draft.{/if}
        </p>
        <button type="button" onclick={handlePublish} disabled={publishing}>
          {publishing ? 'Publishing…' : 'Publish'}
        </button>
      {/if}
    </section>

    <section>
      <h2>
        {detail.counterpartEmail}'s side ({counterpartSide})
        {#if counterpartPublished}<span class="badge">published</span>{/if}
      </h2>
      {#if !counterpartAnswers}
        <p>Not published yet.</p>
      {:else if counterpartSide}
        {#each QUESTIONS_BY_SIDE[counterpartSide] as question (question.id)}
          <fieldset>
            <legend>{question.title}</legend>
            {#each question.fields as field (field.id)}
              <AnswerField {field} value={counterpartAnswers[field.id]} readonly />
            {/each}
          </fieldset>
        {/each}
      {/if}
    </section>

    {#if actionError}
      <p class="error">{actionError}</p>
    {/if}

    {#if !archived}
      <button type="button" onclick={handleArchive} disabled={archiving}>
        {archiving ? 'Archiving…' : 'Archive'}
      </button>
    {/if}
  {/if}
</main>

<style>
  main {
    max-width: 40rem;
    margin: 4rem auto;
    padding: 0 1rem;
    font-family: system-ui, sans-serif;
  }

  .meta {
    color: #6b6b6b;
  }

  .badge {
    margin-left: 0.5rem;
    font-size: 0.75rem;
    color: #6b6b6b;
  }

  fieldset {
    border: 1px solid #ddd;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
  }

  .save-state {
    font-size: 0.8rem;
    color: #6b6b6b;
  }

  .error {
    color: #c0392b;
  }
</style>
