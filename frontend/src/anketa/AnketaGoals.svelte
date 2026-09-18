<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { apiPost, apiPut, ApiError } from '../api/client';
  import { formatDisplayDate } from '../datePreference.svelte';
  import DateInput from '../design/DateInput.svelte';
  import LockIcon from './LockIcon.svelte';
  import CommentThread from './CommentThread.svelte';
  import type { Comment } from './comments';
  import {
    addCheckpoint,
    type CheckpointStatusTag,
    type Goal,
    type GoalCheckpoint,
  } from './goals';
  import { resolveAuthorLabel } from './authorLabel';

  let {
    id,
    goals = $bindable<Goal[]>(),
    allCheckpoints,
    allComments,
    myUserId,
    authorNames,
    commentThreadsBusy = $bindable<Record<string, boolean>>(),
    recentlyArrivedCommentIds,
    addingCheckpoint = $bindable<Record<string, boolean>>(),
    actionError = $bindable<string | null>(),
    submitComment,
    onEditComment,
    onDeleteComment,
    updateGoalCheckpoints,
  }: {
    id: string;
    goals: Goal[];
    allCheckpoints: GoalCheckpoint[];
    allComments: Comment[];
    myUserId: string;
    authorNames: Record<string, string>;
    /**
     * Shared with Anketa.svelte's live-update poll and AnketaOutcomes'
     * delete handler — see commentThreadsBusy's own docblock in
     * Anketa.svelte for the full "one flat record spanning four id
     * namespaces" shape. Neither goals nor checkpoints have a delete path
     * today, so nothing here calls pruneStaleBusyEntries() (from
     * ./commentThreadsBusy) yet — a future one must, the same way
     * AnketaOutcomes' handleDeleteOutcomeConfirm does, or a stale `true`
     * left behind after removing a goal/checkpoint id would permanently
     * block live comment refresh for the rest of the session. Declared
     * `$bindable` now, even though nothing here reassigns it wholesale
     * yet, specifically so that future call is safe by construction: a
     * plain (non-bindable) prop would let a future `commentThreadsBusy =
     * pruneStaleBusyEntries(...)` compile and silently only rebind this
     * component's local copy, never reaching Anketa.svelte's real state —
     * the exact landmine this note exists to prevent, not spun up
     * speculatively for its own sake.
     */
    commentThreadsBusy: Record<string, boolean>;
    recentlyArrivedCommentIds: Record<string, true>;
    addingCheckpoint: Record<string, boolean>;
    actionError: string | null;
    submitComment: (targetId: string, text: string) => Promise<void>;
    onEditComment: (commentId: string, text: string) => Promise<void>;
    onDeleteComment: (commentId: string) => Promise<void>;
    updateGoalCheckpoints: (
      apply: (current: GoalCheckpoint[]) => GoalCheckpoint[],
    ) => Promise<void>;
  } = $props();

  let newGoalTitle = $state('');
  let newGoalDescription = $state('');
  let newGoalTargetDate = $state('');
  let addingGoal = $state(false);
  let goalSaving = $state<Record<string, boolean>>({});
  let checkpointDraftText = $state<Record<string, string>>({});
  let checkpointDraftStatusTag = $state<
    Record<string, CheckpointStatusTag | ''>
  >({});
  let goalsInfoOpen = $state(false);

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
</script>

<section class="card">
  <div class="heading-row">
    <h2>{$_('anketa.goalsHeading')}</h2>
    <LockIcon encrypted={false} />
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
          <span class="tag tag-neutral"
            >{resolveAuthorLabel(
              goal.authorId,
              myUserId,
              authorNames,
              $_('anketa.you'),
            )}</span
          >
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
              {goalSaving[goal.id] ? $_('anketa.saving') : $_('anketa.save')}
            </button>
          </div>
        {/if}

        <CommentThread
          comments={allComments.filter((c) => c.targetId === goal.id)}
          {authorNames}
          currentUserId={myUserId}
          onSubmit={(text) => submitComment(goal.id, text)}
          onEdit={onEditComment}
          onDelete={onDeleteComment}
          bind:hasOpenAction={commentThreadsBusy[goal.id]}
          recentlyArrivedIds={recentlyArrivedCommentIds}
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
                {authorNames}
                currentUserId={myUserId}
                onSubmit={(text) => submitComment(checkpoint.id, text)}
                onEdit={onEditComment}
                onDelete={onDeleteComment}
                bind:hasOpenAction={commentThreadsBusy[checkpoint.id]}
                recentlyArrivedIds={recentlyArrivedCommentIds}
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
              <option value="on_track">{$_('anketa.statusTagOnTrack')}</option>
              <option value="at_risk">{$_('anketa.statusTagAtRisk')}</option>
              <option value="blocked">{$_('anketa.statusTagBlocked')}</option>
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

<style>
  .icon {
    width: 14px;
    height: 14px;
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
</style>
