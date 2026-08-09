<script lang="ts">
  import { apiGet, apiPost, apiPut, ApiError } from '../api/client';
  import AnswerField from '../anketa/AnswerField.svelte';
  import CommentThread from '../anketa/CommentThread.svelte';
  import { addComment, type Comment } from '../anketa/comments';
  import { addOutcome, toggleDone, type OutcomeItem } from '../anketa/outcomes';
  import { addCheckpoint, type CheckpointStatusTag, type Goal, type GoalCheckpoint } from '../anketa/goals';
  import { QUESTIONS_BY_SIDE, type Side, type Answers } from '../anketa/questions';
  import { decryptBlob, encryptBlob, unsealAnketaKey } from '../crypto/anketaKey';
  import { ensureUnlocked } from '../crypto/identity';
  import { loadMasterKey } from '../crypto/session';

  const { id }: { id: string } = $props();

  interface AnketaDetail {
    id: string;
    myRole: Side;
    counterpartId: string;
    counterpartEmail: string;
    meetingDate: string;
    archivedAt: string | null;
    mySealedKey: string;
    employeeBlob: string | null;
    employeePublishedAt: string | null;
    managerBlob: string | null;
    managerPublishedAt: string | null;
    commentsBlob: string | null;
    commentsVersion: number;
    outcomesBlob: string | null;
    outcomesVersion: number;
    goals: Goal[];
    goalCheckpointsBlob: string | null;
    goalCheckpointsVersion: number;
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

  let myUserId = $state('');
  let authorEmails = $state<Record<string, string>>({});
  let allComments = $state<Comment[]>([]);
  let commentsVersion = $state(0);
  let allOutcomes = $state<OutcomeItem[]>([]);
  let outcomesVersion = $state(0);
  let newOutcomeText = $state('');
  let addingOutcome = $state(false);

  let goals = $state<Goal[]>([]);
  let allCheckpoints = $state<GoalCheckpoint[]>([]);
  let goalCheckpointsVersion = $state(0);
  let newGoalTitle = $state('');
  let newGoalDescription = $state('');
  let newGoalTargetDate = $state('');
  let addingGoal = $state(false);
  let goalSaving = $state<Record<string, boolean>>({});
  let checkpointDraftText = $state<Record<string, string>>({});
  let checkpointDraftStatusTag = $state<Record<string, CheckpointStatusTag | ''>>({});
  let addingCheckpoint = $state<Record<string, boolean>>({});

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
      myUserId = identity.userId;
      authorEmails = { [identity.userId]: identity.email, [anketa.counterpartId]: anketa.counterpartEmail };
      counterpartSide = anketa.myRole === 'employee' ? 'manager' : 'employee';
      archived = anketa.archivedAt !== null;

      const key = await unsealAnketaKey(anketa.mySealedKey, identity.publicKey, identity.privateKey);
      anketaKey = key;

      commentsVersion = anketa.commentsVersion;
      if (anketa.commentsBlob) {
        const envelope = await decryptBlob<Comment[]>(anketa.commentsBlob, key);
        allComments = envelope.data;
      }

      outcomesVersion = anketa.outcomesVersion;
      if (anketa.outcomesBlob) {
        const envelope = await decryptBlob<OutcomeItem[]>(anketa.outcomesBlob, key);
        allOutcomes = envelope.data;
      }

      goals = anketa.goals;
      goalCheckpointsVersion = anketa.goalCheckpointsVersion;
      if (anketa.goalCheckpointsBlob) {
        const envelope = await decryptBlob<GoalCheckpoint[]>(anketa.goalCheckpointsBlob, key);
        allCheckpoints = envelope.data;
      }

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

  /**
   * Always re-reads the current commentsBlob/commentsVersion fresh rather than
   * trusting local state (which may be stale), per the Phase 6a plan. On a 409
   * (someone else wrote first), re-applies the same operation to the *latest*
   * remote state and retries once more, rather than merging a stale
   * already-computed result — the latter silently drops edits to an id the
   * remote write also touched (found while building outcomes' toggleDone,
   * which edits an existing item in place; add-only comments happened to work
   * either way, but this is the version that's actually correct in general).
   * A second conflict is rare enough to just surface as an error, not loop.
   */
  async function submitComment(targetId: string, text: string): Promise<void> {
    if (!anketaKey) return;
    await updateComments((current) => addComment(current, targetId, myUserId, text));
  }

  async function updateComments(apply: (current: Comment[]) => Comment[]): Promise<void> {
    if (!anketaKey) return;

    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${id}`);
    const freshComments = fresh.commentsBlob
      ? (await decryptBlob<Comment[]>(fresh.commentsBlob, anketaKey)).data
      : [];

    try {
      await saveComments(apply(freshComments), fresh.commentsVersion);
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 409) throw error;

      const conflict = error.body as { commentsBlob: string | null; commentsVersion: number };
      const remoteComments = conflict.commentsBlob
        ? (await decryptBlob<Comment[]>(conflict.commentsBlob, anketaKey)).data
        : [];
      await saveComments(apply(remoteComments), conflict.commentsVersion);
    }
  }

  async function saveComments(comments: Comment[], expectedVersion: number): Promise<void> {
    if (!anketaKey) return;
    const blob = await encryptBlob(comments, anketaKey);
    const result = await apiPut<{ commentsVersion: number }>(`/api/anketas/${id}/comments`, {
      blob,
      expectedVersion,
    });
    allComments = comments;
    commentsVersion = result.commentsVersion;
  }

  async function handleAddOutcome(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!newOutcomeText.trim() || addingOutcome) return;

    addingOutcome = true;
    actionError = null;
    try {
      await updateOutcomes((current) => addOutcome(current, myUserId, newOutcomeText.trim()));
      newOutcomeText = '';
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not add the item.';
    } finally {
      addingOutcome = false;
    }
  }

  async function handleToggleOutcome(itemId: string): Promise<void> {
    try {
      await updateOutcomes((current) => toggleDone(current, itemId));
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not update the item.';
    }
  }

  /** Same shape as updateComments — see the comment above it. */
  async function updateOutcomes(apply: (current: OutcomeItem[]) => OutcomeItem[]): Promise<void> {
    if (!anketaKey) return;

    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${id}`);
    const freshOutcomes = fresh.outcomesBlob
      ? (await decryptBlob<OutcomeItem[]>(fresh.outcomesBlob, anketaKey)).data
      : [];

    try {
      await saveOutcomes(apply(freshOutcomes), fresh.outcomesVersion);
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 409) throw error;

      const conflict = error.body as { outcomesBlob: string | null; outcomesVersion: number };
      const remoteOutcomes = conflict.outcomesBlob
        ? (await decryptBlob<OutcomeItem[]>(conflict.outcomesBlob, anketaKey)).data
        : [];
      await saveOutcomes(apply(remoteOutcomes), conflict.outcomesVersion);
    }
  }

  async function saveOutcomes(items: OutcomeItem[], expectedVersion: number): Promise<void> {
    if (!anketaKey) return;
    const blob = await encryptBlob(items, anketaKey);
    const result = await apiPut<{ outcomesVersion: number }>(`/api/anketas/${id}/outcomes`, {
      blob,
      expectedVersion,
    });
    allOutcomes = items;
    outcomesVersion = result.outcomesVersion;
  }

  async function handleAddGoal(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!newGoalTitle.trim() || addingGoal) return;

    addingGoal = true;
    actionError = null;
    try {
      const goal = await apiPost<Goal>(`/api/anketas/${id}/goals`, {
        goalUuid: crypto.randomUUID(),
        title: newGoalTitle.trim(),
        description: newGoalDescription.trim() || null,
        targetDate: newGoalTargetDate || null,
      });
      goals = [...goals, goal];
      newGoalTitle = '';
      newGoalDescription = '';
      newGoalTargetDate = '';
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not add the goal.';
    } finally {
      addingGoal = false;
    }
  }

  /** Saves the goal's title/description/targetDate as currently edited in place — see the template's bind:value on the goal object fields. */
  async function handleSaveGoal(goal: Goal): Promise<void> {
    goalSaving = { ...goalSaving, [goal.id]: true };
    actionError = null;
    try {
      const updated = await apiPut<Goal>(`/api/anketas/${id}/goals/${goal.id}`, {
        title: goal.title,
        description: goal.description,
        targetDate: goal.targetDate,
      });
      goals = goals.map((g) => (g.id === goal.id ? updated : g));
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not save the goal.';
    } finally {
      goalSaving = { ...goalSaving, [goal.id]: false };
    }
  }

  async function handleUpdateGoalStatus(goal: Goal): Promise<void> {
    actionError = null;
    try {
      const updated = await apiPut<Goal>(`/api/anketas/${id}/goals/${goal.id}`, { status: goal.status });
      goals = goals.map((g) => (g.id === goal.id ? updated : g));
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not update the goal status.';
    }
  }

  async function handleAddCheckpoint(goalId: string): Promise<void> {
    const text = (checkpointDraftText[goalId] ?? '').trim();
    const statusTag = checkpointDraftStatusTag[goalId] || undefined;
    if (!text && !statusTag) return;

    addingCheckpoint = { ...addingCheckpoint, [goalId]: true };
    actionError = null;
    try {
      await updateGoalCheckpoints((current) => addCheckpoint(current, goalId, myUserId, text || undefined, statusTag));
      checkpointDraftText = { ...checkpointDraftText, [goalId]: '' };
      checkpointDraftStatusTag = { ...checkpointDraftStatusTag, [goalId]: '' };
    } catch (error) {
      actionError = error instanceof ApiError ? error.message : 'Could not add the checkpoint.';
    } finally {
      addingCheckpoint = { ...addingCheckpoint, [goalId]: false };
    }
  }

  /** Same reapply-on-conflict shape as updateComments/updateOutcomes — see the comment above updateComments. */
  async function updateGoalCheckpoints(apply: (current: GoalCheckpoint[]) => GoalCheckpoint[]): Promise<void> {
    if (!anketaKey) return;

    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${id}`);
    const freshCheckpoints = fresh.goalCheckpointsBlob
      ? (await decryptBlob<GoalCheckpoint[]>(fresh.goalCheckpointsBlob, anketaKey)).data
      : [];

    try {
      await saveGoalCheckpoints(apply(freshCheckpoints), fresh.goalCheckpointsVersion);
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 409) throw error;

      const conflict = error.body as { goalCheckpointsBlob: string | null; goalCheckpointsVersion: number };
      const remoteCheckpoints = conflict.goalCheckpointsBlob
        ? (await decryptBlob<GoalCheckpoint[]>(conflict.goalCheckpointsBlob, anketaKey)).data
        : [];
      await saveGoalCheckpoints(apply(remoteCheckpoints), conflict.goalCheckpointsVersion);
    }
  }

  async function saveGoalCheckpoints(checkpoints: GoalCheckpoint[], expectedVersion: number): Promise<void> {
    if (!anketaKey) return;
    const blob = await encryptBlob(checkpoints, anketaKey);
    const result = await apiPut<{ goalCheckpointsVersion: number }>(`/api/anketas/${id}/goal-checkpoints`, {
      blob,
      expectedVersion,
    });
    allCheckpoints = checkpoints;
    goalCheckpointsVersion = result.goalCheckpointsVersion;
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
            {#if myPublished}
              <CommentThread
                comments={allComments.filter((c) => c.targetId === field.id)}
                {authorEmails}
                onSubmit={(text) => submitComment(field.id, text)}
              />
            {/if}
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
              <CommentThread
                comments={allComments.filter((c) => c.targetId === field.id)}
                {authorEmails}
                onSubmit={(text) => submitComment(field.id, text)}
              />
            {/each}
          </fieldset>
        {/each}
      {/if}
    </section>

    <section>
      <h2>Meeting outcomes</h2>
      {#each allOutcomes as item (item.id)}
        <fieldset>
          <label>
            <input
              type="checkbox"
              checked={item.done}
              disabled={item.authorId !== myUserId}
              onchange={() => handleToggleOutcome(item.id)}
            />
            {item.text}
          </label>
          <CommentThread
            comments={allComments.filter((c) => c.targetId === item.id)}
            {authorEmails}
            onSubmit={(text) => submitComment(item.id, text)}
          />
        </fieldset>
      {:else}
        <p>No outcomes recorded yet.</p>
      {/each}

      <form onsubmit={handleAddOutcome}>
        <input type="text" bind:value={newOutcomeText} placeholder="Add an outcome…" disabled={addingOutcome} />
        <button type="submit" disabled={addingOutcome || !newOutcomeText.trim()}>
          {addingOutcome ? 'Adding…' : 'Add'}
        </button>
      </form>
    </section>

    <section>
      <h2>Goals</h2>
      {#each goals as goal (goal.id)}
        {@const isMyGoal = goal.authorId === myUserId}
        <fieldset>
          <label>
            Title
            <input type="text" bind:value={goal.title} readonly={!isMyGoal} />
          </label>
          <label>
            Description
            <textarea
              value={goal.description ?? ''}
              oninput={(e) => (goal.description = e.currentTarget.value)}
              readonly={!isMyGoal}
            ></textarea>
          </label>
          <label>
            Target date
            <input
              type="date"
              value={goal.targetDate ?? ''}
              oninput={(e) => (goal.targetDate = e.currentTarget.value || null)}
              disabled={!isMyGoal}
            />
          </label>
          <label>
            Status
            <select bind:value={goal.status} disabled={!isMyGoal} onchange={() => handleUpdateGoalStatus(goal)}>
              <option value="in_progress">In progress</option>
              <option value="achieved">Achieved</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </label>
          {#if isMyGoal}
            <button type="button" onclick={() => handleSaveGoal(goal)} disabled={goalSaving[goal.id]}>
              {goalSaving[goal.id] ? 'Saving…' : 'Save'}
            </button>
          {/if}

          <CommentThread
            comments={allComments.filter((c) => c.targetId === goal.id)}
            {authorEmails}
            onSubmit={(text) => submitComment(goal.id, text)}
          />

          <h3>Checkpoints</h3>
          {#each allCheckpoints.filter((c) => c.goalId === goal.id) as checkpoint (checkpoint.id)}
            <div class="checkpoint">
              {#if checkpoint.statusTag}<span class="badge">{checkpoint.statusTag}</span>{/if}
              {#if checkpoint.text}<p>{checkpoint.text}</p>{/if}
              <CommentThread
                comments={allComments.filter((c) => c.targetId === checkpoint.id)}
                {authorEmails}
                onSubmit={(text) => submitComment(checkpoint.id, text)}
              />
            </div>
          {:else}
            <p>No checkpoints yet.</p>
          {/each}

          {#if isMyGoal}
            <div class="checkpoint-form">
              <input
                type="text"
                placeholder="Progress update…"
                value={checkpointDraftText[goal.id] ?? ''}
                oninput={(e) => (checkpointDraftText = { ...checkpointDraftText, [goal.id]: e.currentTarget.value })}
                disabled={addingCheckpoint[goal.id]}
              />
              <select
                value={checkpointDraftStatusTag[goal.id] ?? ''}
                onchange={(e) =>
                  (checkpointDraftStatusTag = {
                    ...checkpointDraftStatusTag,
                    [goal.id]: e.currentTarget.value as CheckpointStatusTag | '',
                  })}
                disabled={addingCheckpoint[goal.id]}
              >
                <option value="">No status tag</option>
                <option value="on_track">On track</option>
                <option value="at_risk">At risk</option>
                <option value="blocked">Blocked</option>
              </select>
              <button
                type="button"
                onclick={() => handleAddCheckpoint(goal.id)}
                disabled={addingCheckpoint[goal.id] || (!checkpointDraftText[goal.id]?.trim() && !checkpointDraftStatusTag[goal.id])}
              >
                {addingCheckpoint[goal.id] ? 'Adding…' : 'Add checkpoint'}
              </button>
            </div>
          {/if}
        </fieldset>
      {:else}
        <p>No goals yet.</p>
      {/each}

      <form onsubmit={handleAddGoal}>
        <input type="text" bind:value={newGoalTitle} placeholder="Goal title…" disabled={addingGoal} />
        <input
          type="text"
          bind:value={newGoalDescription}
          placeholder="Description (optional)…"
          disabled={addingGoal}
        />
        <input type="date" bind:value={newGoalTargetDate} disabled={addingGoal} />
        <button type="submit" disabled={addingGoal || !newGoalTitle.trim()}>
          {addingGoal ? 'Adding…' : 'Add goal'}
        </button>
      </form>
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

  .checkpoint {
    border-top: 1px solid #eee;
    padding-top: 0.5rem;
    margin-top: 0.5rem;
  }

  .checkpoint-form {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.5rem;
  }

  .error {
    color: #c0392b;
  }
</style>
