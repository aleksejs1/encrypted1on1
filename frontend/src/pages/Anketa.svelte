<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiGet, apiPost, apiPut, ApiError } from '../api/client';
  import { formatDisplayDate } from '../datePreference.svelte';
  import DateInput from '../design/DateInput.svelte';
  import AnswerField from '../anketa/AnswerField.svelte';
  import CommentThread from '../anketa/CommentThread.svelte';
  import { addComment, type Comment } from '../anketa/comments';
  import {
    clearDraftBackup,
    loadDraftBackup,
    saveDraftBackup,
  } from '../anketa/draftBackup';
  import {
    addOutcome,
    carryForwardOutcomes,
    toggleDone,
    type OutcomeItem,
  } from '../anketa/outcomes';
  import {
    addCheckpoint,
    type CheckpointStatusTag,
    type Goal,
    type GoalCheckpoint,
  } from '../anketa/goals';
  import {
    QUESTIONS_BY_SIDE,
    type Side,
    type Answers,
  } from '../anketa/questions';
  import { updateBlobWithRetry } from '../anketa/blobSync';
  import type { AnketaDetail } from '../api/types';
  import {
    decryptBlob,
    encryptBlob,
    generateAnketaKey,
    sealAnketaKey,
    unsealAnketaKey,
  } from '../crypto/anketaKey';
  import { fromBase64 } from '../crypto/encoding';
  import { ensureUnlocked } from '../crypto/identity';
  import { loadMasterKey } from '../crypto/session';

  const { id }: { id: string } = $props();

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

  let periodicityDays = $state<number | null>(null);
  let missed = $state(false);
  let skipNextMeeting = $state(false);
  let nextMeetingDate = $state('');
  let rescheduleDate = $state('');
  let rescheduling = $state(false);

  const isOverdue = $derived(
    detail !== null &&
      !archived &&
      new Date(detail.meetingDate).getTime() < Date.now(),
  );

  let myUserId = $state('');
  let authorEmails = $state<Record<string, string>>({});
  let allComments = $state<Comment[]>([]);
  let allOutcomes = $state<OutcomeItem[]>([]);
  let newOutcomeText = $state('');
  let addingOutcome = $state(false);

  let goals = $state<Goal[]>([]);
  let allCheckpoints = $state<GoalCheckpoint[]>([]);
  let newGoalTitle = $state('');
  let newGoalDescription = $state('');
  let newGoalTargetDate = $state('');
  let addingGoal = $state(false);
  let goalSaving = $state<Record<string, boolean>>({});
  let checkpointDraftText = $state<Record<string, string>>({});
  let checkpointDraftStatusTag = $state<
    Record<string, CheckpointStatusTag | ''>
  >({});
  let addingCheckpoint = $state<Record<string, boolean>>({});
  let goalsInfoOpen = $state(false);

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
      if (!mk) throw new Error($_('anketa.errorNotLoggedIn'));

      detail = anketa;
      masterKey = mk;
      myUserId = identity.userId;
      authorEmails = {
        [identity.userId]: identity.email,
        [anketa.counterpartId]: anketa.counterpartEmail,
      };
      counterpartSide = anketa.myRole === 'employee' ? 'manager' : 'employee';
      archived = anketa.archivedAt !== null;
      periodicityDays = anketa.periodicityDays;
      missed = anketa.missed;
      if (periodicityDays !== null) {
        const defaultNext = new Date();
        defaultNext.setDate(defaultNext.getDate() + periodicityDays);
        nextMeetingDate = defaultNext.toISOString().slice(0, 10);
      }

      let key: Uint8Array;
      try {
        key = await unsealAnketaKey(
          anketa.mySealedKey,
          identity.publicKey,
          identity.privateKey,
        );
      } catch {
        // Wrong-key AEAD failure — this anketa was sealed under a keypair that no
        // longer matches identity.privateKey, most likely because the account went
        // through a password reset (a fresh keypair, see ResetPassword.svelte) since
        // this anketa was created. Nothing else in `detail` is usable without the
        // anketa key, so this is a distinct, terminal state for the page, not just
        // one field failing.
        loadError = $_('anketa.errorStaleKey');
        return;
      }
      anketaKey = key;

      if (anketa.commentsBlob) {
        const envelope = await decryptBlob<Comment[]>(anketa.commentsBlob, key);
        allComments = envelope.data;
      }

      if (anketa.outcomesBlob) {
        const envelope = await decryptBlob<OutcomeItem[]>(
          anketa.outcomesBlob,
          key,
        );
        allOutcomes = envelope.data;
      }

      goals = anketa.goals;
      if (anketa.goalCheckpointsBlob) {
        const envelope = await decryptBlob<GoalCheckpoint[]>(
          anketa.goalCheckpointsBlob,
          key,
        );
        allCheckpoints = envelope.data;
      }

      const myBlob =
        anketa.myRole === 'employee' ? anketa.employeeBlob : anketa.managerBlob;
      const myPublishedAt =
        anketa.myRole === 'employee'
          ? anketa.employeePublishedAt
          : anketa.managerPublishedAt;
      myPublished = myPublishedAt !== null;
      if (myBlob) {
        const envelope = await decryptBlob<Answers>(
          myBlob,
          myPublished ? key : mk,
        );
        myAnswers = envelope.data;
      }
      if (!myPublished) {
        // A present local backup is always at least as fresh as the last
        // confirmed server save (written on every edit, not debounced) —
        // safe to prefer unconditionally. See anketa/draftBackup.ts.
        const localBackup = await loadDraftBackup(id, mk);
        if (localBackup) myAnswers = localBackup;
      }

      const counterpartBlob =
        anketa.myRole === 'employee' ? anketa.managerBlob : anketa.employeeBlob;
      const counterpartPublishedAt =
        anketa.myRole === 'employee'
          ? anketa.managerPublishedAt
          : anketa.employeePublishedAt;
      counterpartPublished = counterpartPublishedAt !== null;
      if (counterpartBlob && counterpartPublished) {
        const envelope = await decryptBlob<Answers>(counterpartBlob, key);
        counterpartAnswers = envelope.data;
      }

      loaded = true;
    } catch (error) {
      loadError =
        error instanceof ApiError ? error.message : $_('anketa.errorLoad');
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
      clearDraftBackup(id);
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorPublish');
    } finally {
      publishing = false;
    }
  }

  /**
   * Auto-recreation (Phase 6d) is triggered from right here, not a server-side
   * background job — this browser already has the current anketa's key unsealed, so
   * it generates and seals the *next* anketa's key itself (same dance as
   * CreateAnketa.svelte) and sends the sealed keys along with the archive request.
   * The server never generates or even transiently holds an anketa key.
   */
  async function handleArchive(missedFlag: boolean): Promise<void> {
    if (!detail) return;
    archiving = true;
    actionError = null;
    try {
      let body: Record<string, unknown> = { missed: missedFlag };

      if (skipNextMeeting) {
        body.skipNextMeeting = true;
      } else {
        if (!anketaKey) throw new Error($_('anketa.errorNotReadyToArchive'));
        const identity = await ensureUnlocked();
        const nextKey = await generateAnketaKey();
        const mySealedKeyNext = await sealAnketaKey(
          nextKey,
          identity.publicKey,
        );
        const counterpartSealedKeyNext = await sealAnketaKey(
          nextKey,
          await fromBase64(detail.counterpartPublicKey),
        );
        const outcomesBlobNext = await carryForwardOutcomes(
          detail.outcomesBlob,
          anketaKey,
          nextKey,
        );

        body = {
          ...body,
          nextMeetingDate: new Date(nextMeetingDate).toISOString(),
          mySealedKey: mySealedKeyNext,
          counterpartSealedKey: counterpartSealedKeyNext,
          ...(outcomesBlobNext ? { outcomesBlob: outcomesBlobNext } : {}),
        };
      }

      await apiPost(`/api/anketas/${id}/archive`, body);
      archived = true;
      missed = missedFlag;
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorArchive');
    } finally {
      archiving = false;
    }
  }

  async function handleReschedule(): Promise<void> {
    if (!detail || !rescheduleDate) return;
    rescheduling = true;
    actionError = null;
    try {
      const isoDate = new Date(rescheduleDate).toISOString();
      await apiPut(`/api/anketas/${id}/meeting-date`, { meetingDate: isoDate });
      detail = { ...detail, meetingDate: isoDate };
      rescheduleDate = '';
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorReschedule');
    } finally {
      rescheduling = false;
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
    await updateComments((current) =>
      addComment(current, targetId, myUserId, text),
    );
  }

  /**
   * Shared reapply-on-conflict update for the anketa's three optimistic-
   * concurrency blobs (comments, outcomes, goal checkpoints — see blobSync.ts):
   * refetch, apply the caller's mutation, save, and on a 409 retry once
   * against whatever the conflict response carries under the same field names.
   */
  async function updateField<T>(
    blobKey: 'commentsBlob' | 'outcomesBlob' | 'goalCheckpointsBlob',
    versionKey:
      'commentsVersion' | 'outcomesVersion' | 'goalCheckpointsVersion',
    endpoint: string,
    apply: (current: T) => T,
  ): Promise<T | undefined> {
    if (!anketaKey) return undefined;
    const fresh = await apiGet<AnketaDetail>(`/api/anketas/${id}`);
    return await updateBlobWithRetry<T>(
      anketaKey,
      { blob: fresh[blobKey], version: fresh[versionKey] },
      apply,
      async (blob, expectedVersion) => {
        await apiPut(`/api/anketas/${id}/${endpoint}`, {
          blob,
          expectedVersion,
        });
      },
      (error) => {
        if (!(error instanceof ApiError) || error.status !== 409)
          return undefined;
        const conflict = error.body as Record<string, string | number | null>;
        return {
          blob: conflict[blobKey] as string | null,
          version: conflict[versionKey] as number,
        };
      },
    );
  }

  async function updateComments(
    apply: (current: Comment[]) => Comment[],
  ): Promise<void> {
    const result = await updateField<Comment[]>(
      'commentsBlob',
      'commentsVersion',
      'comments',
      apply,
    );
    if (result !== undefined) allComments = result;
  }

  async function handleAddOutcome(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!newOutcomeText.trim() || addingOutcome) return;

    addingOutcome = true;
    actionError = null;
    try {
      await updateOutcomes((current) =>
        addOutcome(current, myUserId, newOutcomeText.trim()),
      );
      newOutcomeText = '';
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorAddOutcome');
    } finally {
      addingOutcome = false;
    }
  }

  async function handleToggleOutcome(itemId: string): Promise<void> {
    try {
      await updateOutcomes((current) => toggleDone(current, itemId));
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorUpdateOutcome');
    }
  }

  async function updateOutcomes(
    apply: (current: OutcomeItem[]) => OutcomeItem[],
  ): Promise<void> {
    const result = await updateField<OutcomeItem[]>(
      'outcomesBlob',
      'outcomesVersion',
      'outcomes',
      apply,
    );
    if (result !== undefined) allOutcomes = result;
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
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorAddGoal');
    } finally {
      addingGoal = false;
    }
  }

  /** Saves the goal's title/description/targetDate as currently edited in place — see the template's bind:value on the goal object fields. */
  async function handleSaveGoal(goal: Goal): Promise<void> {
    goalSaving = { ...goalSaving, [goal.id]: true };
    actionError = null;
    try {
      const updated = await apiPut<Goal>(
        `/api/anketas/${id}/goals/${goal.id}`,
        {
          title: goal.title,
          description: goal.description,
          targetDate: goal.targetDate,
        },
      );
      goals = goals.map((g) => (g.id === goal.id ? updated : g));
    } catch (error) {
      actionError =
        error instanceof ApiError ? error.message : $_('anketa.errorSaveGoal');
    } finally {
      goalSaving = { ...goalSaving, [goal.id]: false };
    }
  }

  async function handleUpdateGoalStatus(goal: Goal): Promise<void> {
    actionError = null;
    try {
      const updated = await apiPut<Goal>(
        `/api/anketas/${id}/goals/${goal.id}`,
        { status: goal.status },
      );
      goals = goals.map((g) => (g.id === goal.id ? updated : g));
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorUpdateGoalStatus');
    }
  }

  /**
   * Checkpoints are keyed by the goal's stable `goalUuid`, not its per-anketa row
   * `id` — a carried-forward goal gets a fresh row id every cycle (see the Phase 6c
   * plan), so only goalUuid lets a checkpoint's history survive carry-forward and be
   * reconstructed across anketas later (the report, Phase 6f).
   */
  async function handleAddCheckpoint(goalUuid: string): Promise<void> {
    const text = (checkpointDraftText[goalUuid] ?? '').trim();
    const statusTag = checkpointDraftStatusTag[goalUuid] || undefined;
    if (!text && !statusTag) return;

    addingCheckpoint = { ...addingCheckpoint, [goalUuid]: true };
    actionError = null;
    try {
      await updateGoalCheckpoints((current) =>
        addCheckpoint(
          current,
          goalUuid,
          myUserId,
          text || undefined,
          statusTag,
        ),
      );
      checkpointDraftText = { ...checkpointDraftText, [goalUuid]: '' };
      checkpointDraftStatusTag = {
        ...checkpointDraftStatusTag,
        [goalUuid]: '',
      };
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorAddCheckpoint');
    } finally {
      addingCheckpoint = { ...addingCheckpoint, [goalUuid]: false };
    }
  }

  async function updateGoalCheckpoints(
    apply: (current: GoalCheckpoint[]) => GoalCheckpoint[],
  ): Promise<void> {
    const result = await updateField<GoalCheckpoint[]>(
      'goalCheckpointsBlob',
      'goalCheckpointsVersion',
      'goal-checkpoints',
      apply,
    );
    if (result !== undefined) allCheckpoints = result;
  }

  /** "You" for the current viewer's own items, otherwise the counterpart's email — used for outcome-item and goal author tags. */
  function authorLabel(authorId: string): string {
    return authorId === myUserId
      ? $_('anketa.you')
      : (authorEmails[authorId] ?? authorId);
  }

  const GOAL_STATUS_KEYS: Record<Goal['status'], string> = {
    in_progress: 'anketa.goalStatusInProgress',
    achieved: 'anketa.goalStatusAchieved',
    cancelled: 'anketa.goalStatusCancelled',
  };
  const GOAL_STATUS_TAG_CLASSES: Record<Goal['status'], string> = {
    in_progress: 'tag-accent',
    achieved: 'tag-accent-2',
    cancelled: 'tag-neutral',
  };

  const CHECKPOINT_STATUS_TAG_KEYS: Record<CheckpointStatusTag, string> = {
    on_track: 'anketa.statusTagOnTrack',
    at_risk: 'anketa.statusTagAtRisk',
    blocked: 'anketa.statusTagBlocked',
  };
  const CHECKPOINT_STATUS_TAG_CLASSES: Record<CheckpointStatusTag, string> = {
    on_track: 'tag-accent-2',
    at_risk: 'tag-outline',
    blocked: 'tag-neutral',
  };

  // Reactive autosave: fires whenever myAnswers changes (property-level mutations from AnswerField included).
  $effect(() => {
    void JSON.stringify(myAnswers);
    scheduleSave();
    // Local backup, written immediately (not debounced like the server sync) —
    // protects against a silent server-sync failure within the debounce
    // window, not just its timing. See anketa/draftBackup.ts.
    if (loaded && !myPublished && masterKey) {
      void saveDraftBackup(id, myAnswers, masterKey);
    }
  });
</script>

{#snippet lockIcon()}
  <span class="lock-hint" title={$_('anketa.encryptedHint')} aria-hidden="true">
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <rect x="3" y="11" width="18" height="10" rx="2"></rect>
      <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
    </svg>
  </span>
{/snippet}

{#snippet openLockIcon()}
  <span
    class="lock-hint"
    title={$_('anketa.notEncryptedHint')}
    aria-hidden="true"
  >
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.5"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <rect x="3" y="11" width="18" height="10" rx="2"></rect>
      <path d="M7 11V7a5 5 0 0 1 9.5-1.5"></path>
    </svg>
  </span>
{/snippet}

<main>
  {#if loadError}
    <p class="banner-error">{loadError}</p>
  {:else if !detail}
    <p class="text-muted">{$_('anketa.loading')}</p>
  {:else}
    <h1>
      {$_('anketa.titleWithCounterpart', {
        values: { email: detail.counterpartEmail },
      })}
    </h1>
    <p class="meta">
      <span class="text-muted"
        >{$_('anketa.meetingLabel')}
        {formatDisplayDate(detail.meetingDate)}</span
      >
      {#if archived}<span class="tag tag-neutral"
          >{$_('anketa.badgeArchived')}</span
        >{/if}
      {#if missed}<span class="tag tag-neutral">{$_('anketa.badgeMissed')}</span
        >{/if}
      {#if isOverdue}<span class="tag tag-outline"
          >{$_('anketa.badgeOverdue')}</span
        >{/if}
    </p>

    {#if isOverdue}
      <div class="card elev-sm overdue-card">
        <strong>{$_('anketa.overdueHeading')}</strong>
        <div class="reschedule-row">
          <DateInput bind:value={rescheduleDate} disabled={rescheduling} />
          <button
            type="button"
            class="btn btn-secondary"
            onclick={handleReschedule}
            disabled={rescheduling || !rescheduleDate}
          >
            {rescheduling ? $_('anketa.rescheduling') : $_('anketa.reschedule')}
          </button>
        </div>
        <p class="text-muted overdue-note">{$_('anketa.orIfDidNotHappen')}</p>
        <button
          type="button"
          class="btn btn-ghost cancel-missed-btn"
          onclick={() => handleArchive(true)}
          disabled={archiving}
        >
          {archiving ? $_('anketa.cancelling') : $_('anketa.cancelAsMissed')}
        </button>
      </div>
    {/if}

    <!-- My side -->
    <section class="card side-card">
      <div class="heading-row">
        <h2>
          {$_('anketa.mySideHeading', {
            values: {
              role: $_(
                detail.myRole === 'employee'
                  ? 'common.roleEmployee'
                  : 'common.roleManager',
              ),
            },
          })}
        </h2>
        {@render lockIcon()}
      </div>

      <div class="blocks">
        {#each QUESTIONS_BY_SIDE[detail.myRole] as question (question.id)}
          <div class="block">
            <h4>{$_(question.titleKey)}</h4>
            {#each question.fields as field (field.id)}
              <AnswerField
                {field}
                bind:value={myAnswers[field.id]}
                readonly={myPublished}
              />
              {#if myPublished}
                <CommentThread
                  comments={allComments.filter((c) => c.targetId === field.id)}
                  {authorEmails}
                  onSubmit={(text) => submitComment(field.id, text)}
                />
              {/if}
            {/each}
          </div>
        {/each}
      </div>

      {#if !myPublished}
        <p class="text-muted save-state">
          {#if saveState === 'saving'}{$_(
              'anketa.savingDraft',
            )}{:else if saveState === 'saved'}{$_(
              'anketa.savedDraft',
            )}{:else if saveState === 'error'}{$_('anketa.saveError')}{/if}
        </p>
        <button
          type="button"
          class="btn btn-primary side-publish-btn"
          onclick={handlePublish}
          disabled={publishing}
        >
          {publishing ? $_('anketa.publishing') : $_('anketa.publish')}
        </button>
      {:else}
        <span class="tag tag-accent side-publish-btn"
          >{$_('anketa.badgePublished')}</span
        >
      {/if}
    </section>

    <!-- Counterpart side -->
    <section class="card side-card">
      <div class="heading-row">
        <h2>
          {$_('anketa.counterpartSideHeading', {
            values: {
              email: detail.counterpartEmail,
              role: counterpartSide
                ? $_(
                    counterpartSide === 'employee'
                      ? 'common.roleEmployee'
                      : 'common.roleManager',
                  )
                : '',
            },
          })}
        </h2>
        {@render lockIcon()}
      </div>
      {#if !counterpartAnswers}
        <p class="text-muted">{$_('anketa.notPublishedYet')}</p>
      {:else if counterpartSide}
        <div class="blocks">
          {#each QUESTIONS_BY_SIDE[counterpartSide] as question (question.id)}
            <div class="block">
              <h4>{$_(question.titleKey)}</h4>
              {#each question.fields as field (field.id)}
                <AnswerField
                  {field}
                  value={counterpartAnswers[field.id]}
                  readonly
                />
                <CommentThread
                  comments={allComments.filter((c) => c.targetId === field.id)}
                  {authorEmails}
                  onSubmit={(text) => submitComment(field.id, text)}
                />
              {/each}
            </div>
          {/each}
        </div>
      {/if}
    </section>

    <!-- Outcomes -->
    <section class="card">
      <div class="heading-row heading-row-tight">
        <h2>{$_('anketa.outcomesHeading')}</h2>
        {@render lockIcon()}
      </div>
      <p class="text-muted outcomes-note">{$_('anketa.outcomesNote')}</p>

      <div class="outcomes-list">
        {#each allOutcomes as item (item.id)}
          <div class="outcome-item">
            <div class="entry outcome-entry">
              <input
                type="checkbox"
                class="outcome-checkbox"
                checked={item.done}
                disabled={item.authorId !== myUserId}
                onchange={() => handleToggleOutcome(item.id)}
              />
              <span class="entry-text" class:done={item.done}>{item.text}</span>
              <span class="tag tag-neutral">{authorLabel(item.authorId)}</span>
            </div>
            <CommentThread
              comments={allComments.filter((c) => c.targetId === item.id)}
              {authorEmails}
              onSubmit={(text) => submitComment(item.id, text)}
            />
          </div>
        {:else}
          <p class="text-muted">{$_('anketa.outcomesEmpty')}</p>
        {/each}
      </div>

      <form class="add-row" onsubmit={handleAddOutcome}>
        <input
          type="text"
          class="input"
          bind:value={newOutcomeText}
          placeholder={$_('anketa.outcomesPlaceholder')}
          disabled={addingOutcome}
        />
        <button
          type="submit"
          class="btn btn-secondary"
          disabled={addingOutcome || !newOutcomeText.trim()}
        >
          {addingOutcome ? $_('anketa.adding') : $_('anketa.add')}
        </button>
      </form>
    </section>

    <!-- Goals -->
    <section class="card">
      <div class="heading-row">
        <h2>{$_('anketa.goalsHeading')}</h2>
        {@render openLockIcon()}
        <button
          type="button"
          class="btn btn-icon btn-secondary goals-info-toggle"
          onclick={() => (goalsInfoOpen = !goalsInfoOpen)}
          aria-label={$_('anketa.moreInfo')}
        >
          <svg
            class="icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.3"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="12" y1="16" x2="12" y2="11"></line>
            <line x1="12" y1="8" x2="12.01" y2="8"></line>
          </svg>
        </button>
      </div>
      {#if goalsInfoOpen}
        <p class="text-muted goals-info-note">
          {$_('anketa.goalsUnencryptedNote')}
        </p>
      {/if}

      <div class="goal-list">
        {#each goals as goal (goal.id)}
          {@const isMyGoal = goal.authorId === myUserId}
          <div class="goal-card">
            <div class="goal-header">
              {#if isMyGoal}
                <div class="field goal-title-field">
                  <label for="goal-title-{goal.id}"
                    >{$_('anketa.goalTitleLabel')}</label
                  >
                  <input
                    id="goal-title-{goal.id}"
                    type="text"
                    class="input"
                    bind:value={goal.title}
                  />
                </div>
              {:else}
                <strong class="goal-title-display">{goal.title}</strong>
              {/if}
              <span class="tag {GOAL_STATUS_TAG_CLASSES[goal.status]}"
                >{$_(GOAL_STATUS_KEYS[goal.status])}</span
              >
              <span class="tag tag-neutral">{authorLabel(goal.authorId)}</span>
            </div>

            {#if isMyGoal}
              <div class="field">
                <label for="goal-description-{goal.id}"
                  >{$_('anketa.goalDescriptionLabel')}</label
                >
                <textarea
                  id="goal-description-{goal.id}"
                  class="input"
                  value={goal.description ?? ''}
                  oninput={(e) => (goal.description = e.currentTarget.value)}
                ></textarea>
              </div>
            {:else if goal.description}
              <p class="goal-description">{goal.description}</p>
            {/if}

            {#if isMyGoal}
              <div class="field goal-target-date-field">
                <label for="goal-target-date-{goal.id}"
                  >{$_('anketa.goalTargetDateLabel')}</label
                >
                <DateInput
                  id="goal-target-date-{goal.id}"
                  bind:value={
                    () => goal.targetDate ?? '',
                    (v) => (goal.targetDate = v || null)
                  }
                />
              </div>
            {:else if goal.targetDate}
              <p class="text-muted goal-target-date-display">
                {$_('anketa.goalTargetDateLabel')}: {formatDisplayDate(
                  goal.targetDate,
                )}
              </p>
            {/if}

            {#if isMyGoal}
              <div class="goal-actions">
                <div class="field goal-status-field">
                  <label for="goal-status-{goal.id}"
                    >{$_('anketa.goalStatusLabel')}</label
                  >
                  <select
                    id="goal-status-{goal.id}"
                    class="input goal-status-select"
                    bind:value={goal.status}
                    onchange={() => handleUpdateGoalStatus(goal)}
                  >
                    <option value="in_progress"
                      >{$_('anketa.goalStatusInProgress')}</option
                    >
                    <option value="achieved"
                      >{$_('anketa.goalStatusAchieved')}</option
                    >
                    <option value="cancelled"
                      >{$_('anketa.goalStatusCancelled')}</option
                    >
                  </select>
                </div>
                <button
                  type="button"
                  class="btn btn-secondary goal-save-btn"
                  onclick={() => handleSaveGoal(goal)}
                  disabled={goalSaving[goal.id]}
                >
                  {goalSaving[goal.id]
                    ? $_('anketa.saving')
                    : $_('anketa.save')}
                </button>
              </div>
            {/if}

            <CommentThread
              comments={allComments.filter((c) => c.targetId === goal.id)}
              {authorEmails}
              onSubmit={(text) => submitComment(goal.id, text)}
            />

            <h4 class="checkpoints-heading">
              {$_('anketa.checkpointsHeading')}
            </h4>
            <div class="checkpoints">
              {#each allCheckpoints.filter((c) => c.goalId === goal.goalUuid) as checkpoint (checkpoint.id)}
                <div class="checkpoint-row">
                  <span class="text-muted checkpoint-date"
                    >{formatDisplayDate(checkpoint.createdAt)}</span
                  >
                  {#if checkpoint.text}<span class="checkpoint-text"
                      >{checkpoint.text}</span
                    >{/if}
                  {#if checkpoint.statusTag}
                    <span
                      class="tag {CHECKPOINT_STATUS_TAG_CLASSES[
                        checkpoint.statusTag
                      ]}"
                    >
                      {$_(CHECKPOINT_STATUS_TAG_KEYS[checkpoint.statusTag])}
                    </span>
                  {/if}
                  <CommentThread
                    comments={allComments.filter(
                      (c) => c.targetId === checkpoint.id,
                    )}
                    {authorEmails}
                    onSubmit={(text) => submitComment(checkpoint.id, text)}
                  />
                </div>
              {:else}
                <p class="text-muted">{$_('anketa.noCheckpointsYet')}</p>
              {/each}
            </div>

            {#if isMyGoal}
              <div class="checkpoint-form">
                <input
                  type="text"
                  class="input"
                  placeholder={$_('anketa.checkpointPlaceholder')}
                  value={checkpointDraftText[goal.goalUuid] ?? ''}
                  oninput={(e) =>
                    (checkpointDraftText = {
                      ...checkpointDraftText,
                      [goal.goalUuid]: e.currentTarget.value,
                    })}
                  disabled={addingCheckpoint[goal.goalUuid]}
                />
                <select
                  class="input"
                  value={checkpointDraftStatusTag[goal.goalUuid] ?? ''}
                  onchange={(e) =>
                    (checkpointDraftStatusTag = {
                      ...checkpointDraftStatusTag,
                      [goal.goalUuid]: e.currentTarget.value as
                        CheckpointStatusTag | '',
                    })}
                  disabled={addingCheckpoint[goal.goalUuid]}
                >
                  <option value="">{$_('anketa.noStatusTag')}</option>
                  <option value="on_track"
                    >{$_('anketa.statusTagOnTrack')}</option
                  >
                  <option value="at_risk">{$_('anketa.statusTagAtRisk')}</option
                  >
                  <option value="blocked"
                    >{$_('anketa.statusTagBlocked')}</option
                  >
                </select>
                <button
                  type="button"
                  class="btn btn-secondary"
                  onclick={() => handleAddCheckpoint(goal.goalUuid)}
                  disabled={addingCheckpoint[goal.goalUuid] ||
                    (!checkpointDraftText[goal.goalUuid]?.trim() &&
                      !checkpointDraftStatusTag[goal.goalUuid])}
                >
                  {addingCheckpoint[goal.goalUuid]
                    ? $_('anketa.addingCheckpoint')
                    : $_('anketa.addCheckpoint')}
                </button>
              </div>
            {/if}
          </div>
        {:else}
          <p class="text-muted">{$_('anketa.noGoalsYet')}</p>
        {/each}
      </div>

      <form class="add-goal-row" onsubmit={handleAddGoal}>
        <input
          type="text"
          class="input"
          bind:value={newGoalTitle}
          placeholder={$_('anketa.goalTitlePlaceholder')}
          disabled={addingGoal}
        />
        <input
          type="text"
          class="input"
          bind:value={newGoalDescription}
          placeholder={$_('anketa.goalDescriptionPlaceholder')}
          disabled={addingGoal}
        />
        <DateInput bind:value={newGoalTargetDate} disabled={addingGoal} />
        <button
          type="submit"
          class="btn btn-secondary"
          disabled={addingGoal || !newGoalTitle.trim()}
        >
          {addingGoal ? $_('anketa.addingGoal') : $_('anketa.addGoal')}
        </button>
      </form>
    </section>

    {#if actionError}
      <p class="banner-error">{actionError}</p>
    {/if}

    {#if !archived}
      <section class="card">
        <h2>{$_('anketa.archiveHeading')}</h2>
        <label class="radio archive-skip">
          <input
            type="checkbox"
            class="native-checkbox"
            bind:checked={skipNextMeeting}
          />
          {$_('anketa.skipNextMeeting')}
        </label>
        {#if !skipNextMeeting}
          <div class="field archive-date-field">
            <label for="next-meeting-date"
              >{$_('anketa.nextMeetingDateLabel')}</label
            >
            <DateInput id="next-meeting-date" bind:value={nextMeetingDate} />
          </div>
        {/if}
        <button
          type="button"
          class="btn btn-primary"
          onclick={() => handleArchive(false)}
          disabled={archiving}
        >
          {archiving ? $_('anketa.archiving') : $_('anketa.archive')}
        </button>
      </section>
    {/if}
  {/if}
</main>

<style>
  main {
    max-width: 46rem;
    margin: 0 auto;
    padding: 28px 24px 60px;
    display: flex;
    flex-direction: column;
    gap: 20px;
  }

  h1 {
    font-size: 26px;
    margin-bottom: 4px;
  }

  .meta {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 13px;
    margin: 0;
  }

  .overdue-card {
    border: 1px solid color-mix(in srgb, var(--color-accent) 45%, transparent);
    gap: 10px;
  }

  .reschedule-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }

  .overdue-note {
    font-size: 12px;
    margin: 0;
  }

  .cancel-missed-btn {
    align-self: flex-start;
    padding: 4px 0;
  }

  .heading-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 4px;
  }

  .heading-row h2 {
    margin: 0;
    font-size: 19px;
  }

  .heading-row-tight {
    margin-bottom: 2px;
  }

  .lock-hint {
    display: inline-flex;
    width: 14px;
    height: 14px;
    flex: none;
    color: color-mix(in srgb, var(--color-text) 45%, transparent);
  }

  .lock-hint svg {
    width: 100%;
    height: 100%;
  }

  .icon {
    width: 14px;
    height: 14px;
  }

  .blocks {
    display: flex;
    flex-direction: column;
  }

  .block {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--color-divider);
  }

  .block:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
  }

  .block h4 {
    margin: 0;
  }

  .save-state {
    font-size: 12px;
    margin: 0;
  }

  .side-publish-btn {
    align-self: flex-start;
  }

  .outcomes-note {
    font-size: 12px;
    margin: 0 0 8px;
  }

  .outcomes-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .outcome-item {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .entry {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 10px;
    background: var(--color-bg);
    border-radius: var(--radius-sm);
    font-size: 13px;
    flex-wrap: wrap;
  }

  .outcome-entry {
    align-items: center;
  }

  .outcome-checkbox {
    width: 16px;
    height: 16px;
    flex: none;
  }

  .entry-text {
    flex: 1;
  }

  .entry-text.done {
    text-decoration: line-through;
  }

  .add-row {
    display: flex;
    gap: 8px;
  }

  .add-row .input {
    flex: 1;
  }

  .goals-info-toggle {
    width: 20px;
    height: 20px;
  }

  .goals-info-note {
    font-size: 12px;
    margin: 0 0 10px;
    padding: 8px 10px;
    background: var(--color-bg);
    border-radius: var(--radius-sm);
  }

  .goal-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .goal-card {
    border: 1px solid var(--color-divider);
    border-radius: var(--radius-md);
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .goal-header {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }

  .goal-title-field {
    flex: 1;
    min-width: 160px;
  }

  .goal-title-display {
    font-size: 14px;
  }

  .goal-description {
    font-size: 13px;
    margin: 0;
  }

  .goal-target-date-display {
    font-size: 11px;
    margin: 0;
  }

  .goal-target-date-field {
    max-width: 220px;
  }

  .goal-actions {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    flex-wrap: wrap;
  }

  .goal-status-field {
    min-width: 160px;
  }

  .goal-status-select {
    width: auto;
  }

  .goal-save-btn {
    margin-bottom: 1px;
  }

  .checkpoints-heading {
    margin: 0;
  }

  .checkpoints {
    display: flex;
    flex-direction: column;
    gap: 4px;
  }

  .checkpoint-row {
    display: flex;
    gap: 8px;
    font-size: 12px;
    align-items: center;
    flex-wrap: wrap;
  }

  .checkpoint-date {
    width: 70px;
    flex: none;
  }

  .checkpoint-text {
    flex: 1;
  }

  .checkpoint-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .checkpoint-form .input {
    flex: 1;
    min-width: 140px;
  }

  .add-goal-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
  }

  .add-goal-row .input:first-of-type {
    flex: 1;
    min-width: 140px;
  }

  .add-goal-row .input:nth-of-type(2) {
    flex: 2;
    min-width: 160px;
  }

  .archive-skip {
    margin-bottom: 10px;
  }

  .native-checkbox {
    position: static;
    opacity: 1;
    width: auto;
    height: auto;
  }

  .archive-date-field {
    max-width: 220px;
    margin-bottom: 12px;
  }
</style>
