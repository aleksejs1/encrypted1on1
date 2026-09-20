<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { ApiError } from '../api/client';
  import CommentThread from './CommentThread.svelte';
  import type { Comment } from './comments';
  import {
    addOutcome,
    deleteOutcome,
    editOutcome,
    toggleDone,
    type OutcomeItem,
  } from './outcomes';
  import { pruneStaleBusyEntries } from './commentThreadsBusy';
  import { resolveAuthorLabel } from './authorLabel';
  import LockIcon from './LockIcon.svelte';

  let {
    items,
    myUserId,
    allComments,
    authorNames,
    commentThreadsBusy = $bindable<Record<string, boolean>>(),
    recentlyArrivedCommentIds,
    addingOutcome = $bindable<boolean>(),
    editingOutcomeId = $bindable<string | null>(),
    confirmingDeleteOutcomeId = $bindable<string | null>(),
    anotherOutcomeActionOpen,
    actionError = $bindable<string | null>(),
    submitComment,
    onEditComment,
    onDeleteComment,
    updateOutcomes,
  }: {
    items: OutcomeItem[];
    myUserId: string;
    allComments: Comment[];
    authorNames: Record<string, string>;
    commentThreadsBusy: Record<string, boolean>;
    recentlyArrivedCommentIds: Record<string, true>;
    addingOutcome: boolean;
    editingOutcomeId: string | null;
    confirmingDeleteOutcomeId: string | null;
    /**
     * Same "one open at a time" reasoning as CommentThread's own
     * anotherActionOpen — passed down from Anketa.svelte rather than
     * re-derived here from editingOutcomeId/confirmingDeleteOutcomeId, since
     * that page's pollLiveStateFor already computes the identical `$derived`
     * off those same two (bound) ids for its own willApplyOutcomes gate. One
     * source of truth for the formula, not two copies that could silently
     * drift apart.
     */
    anotherOutcomeActionOpen: boolean;
    actionError: string | null;
    submitComment: (targetId: string, text: string) => Promise<void>;
    onEditComment: (commentId: string, text: string) => Promise<void>;
    onDeleteComment: (commentId: string) => Promise<void>;
    updateOutcomes: (
      apply: (current: OutcomeItem[]) => OutcomeItem[],
    ) => Promise<void>;
  } = $props();

  let newOutcomeText = $state('');

  let editOutcomeText = $state('');
  let editOutcomeBusy = $state(false);
  let editOutcomeError = $state<string | null>(null);

  let deleteOutcomeBusy = $state(false);
  let deleteOutcomeError = $state<string | null>(null);

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

  function startEditOutcome(item: OutcomeItem): void {
    editingOutcomeId = item.id;
    editOutcomeText = item.text;
    editOutcomeError = null;
  }

  function cancelEditOutcome(): void {
    editingOutcomeId = null;
    editOutcomeText = '';
    editOutcomeError = null;
  }

  function startDeleteOutcome(itemId: string): void {
    confirmingDeleteOutcomeId = itemId;
    deleteOutcomeError = null;
  }

  async function handleEditOutcomeSubmit(
    event: SubmitEvent,
    itemId: string,
  ): Promise<void> {
    event.preventDefault();
    if (!editOutcomeText.trim() || editOutcomeBusy) return;

    editOutcomeBusy = true;
    editOutcomeError = null;
    try {
      await updateOutcomes((current) =>
        editOutcome(current, itemId, myUserId, editOutcomeText.trim()),
      );
      editingOutcomeId = null;
      editOutcomeText = '';
    } catch (error) {
      editOutcomeError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorEditOutcome');
    } finally {
      editOutcomeBusy = false;
    }
  }

  async function handleDeleteOutcomeConfirm(itemId: string): Promise<void> {
    deleteOutcomeBusy = true;
    deleteOutcomeError = null;
    try {
      await updateOutcomes((current) =>
        deleteOutcome(current, itemId, myUserId),
      );
      confirmingDeleteOutcomeId = null;
      // The deleted outcome's own CommentThread instance unmounts right along
      // with it — without this, an id left `true` here (e.g. a comment edit
      // was open on this outcome's thread when it got deleted) would stay
      // stuck forever, since nothing else ever prunes commentThreadsBusy, and
      // anyCommentThreadBusy would then block live comment refresh for the
      // rest of the session over an id that no longer exists anywhere. Same
      // helper Anketa.svelte's own live-update poll uses for the equivalent
      // counterpart-deletes-it-via-live-refresh case — `items` above is
      // already the post-delete list by this point.
      commentThreadsBusy = pruneStaleBusyEntries(
        commentThreadsBusy,
        [itemId],
        new Set(items.map((o) => o.id)),
      );
    } catch (error) {
      deleteOutcomeError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorDeleteOutcome');
    } finally {
      deleteOutcomeBusy = false;
    }
  }
</script>

<section class="card">
  <div class="heading-row heading-row-tight">
    <h2>{$_('anketa.outcomesHeading')}</h2>
    <LockIcon encrypted />
  </div>
  <p class="text-muted outcomes-note">{$_('anketa.outcomesNote')}</p>

  <div class="outcomes-list">
    {#each items as item (item.id)}
      <div class="outcome-item">
        <div class="entry outcome-entry">
          <input
            type="checkbox"
            class="outcome-checkbox"
            checked={item.done}
            disabled={item.authorId !== myUserId ||
              editingOutcomeId === item.id ||
              confirmingDeleteOutcomeId === item.id}
            onchange={() => handleToggleOutcome(item.id)}
          />
          {#if editingOutcomeId === item.id}
            <form
              class="edit-form"
              onsubmit={(event) => handleEditOutcomeSubmit(event, item.id)}
            >
              <input
                type="text"
                class="input"
                bind:value={editOutcomeText}
                disabled={editOutcomeBusy}
              />
              <button
                type="submit"
                class="btn btn-secondary"
                disabled={editOutcomeBusy || !editOutcomeText.trim()}
              >
                {$_('commentThread.save')}
              </button>
              <button
                type="button"
                class="btn btn-ghost"
                onclick={cancelEditOutcome}
                disabled={editOutcomeBusy}
              >
                {$_('commentThread.cancel')}
              </button>
            </form>
          {:else}
            <span class="entry-text" class:done={item.done}>{item.text}</span>
            <span class="tag tag-neutral"
              >{resolveAuthorLabel(
                item.authorId,
                myUserId,
                authorNames,
                $_('anketa.you'),
              )}</span
            >
            {#if item.authorId === myUserId}
              {#if confirmingDeleteOutcomeId === item.id}
                <span class="outcome-actions">
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
                    onclick={() => handleDeleteOutcomeConfirm(item.id)}
                    disabled={deleteOutcomeBusy}
                  >
                    {$_('commentThread.confirmDelete')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
                    onclick={() => (confirmingDeleteOutcomeId = null)}
                    disabled={deleteOutcomeBusy}
                  >
                    {$_('commentThread.cancel')}
                  </button>
                </span>
              {:else}
                <span class="outcome-actions">
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
                    onclick={() => startEditOutcome(item)}
                    disabled={anotherOutcomeActionOpen}
                  >
                    {$_('commentThread.edit')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
                    onclick={() => startDeleteOutcome(item.id)}
                    disabled={anotherOutcomeActionOpen}
                  >
                    {$_('commentThread.delete')}
                  </button>
                </span>
              {/if}
            {/if}
          {/if}
        </div>
        {#if editingOutcomeId === item.id && editOutcomeError}
          <p role="alert" class="banner-error">{editOutcomeError}</p>
        {/if}
        {#if confirmingDeleteOutcomeId === item.id && deleteOutcomeError}
          <p role="alert" class="banner-error">{deleteOutcomeError}</p>
        {/if}
        <CommentThread
          comments={allComments.filter((c) => c.targetId === item.id)}
          {authorNames}
          currentUserId={myUserId}
          onSubmit={(text) => submitComment(item.id, text)}
          onEdit={onEditComment}
          onDelete={onDeleteComment}
          bind:hasOpenAction={commentThreadsBusy[item.id]}
          recentlyArrivedIds={recentlyArrivedCommentIds}
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

<style>
  .heading-row-tight {
    margin-bottom: 2px;
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

  .outcome-actions {
    display: flex;
    gap: 2px;
  }

  .edit-form {
    display: flex;
    flex: 1;
    min-width: 200px;
    gap: 6px;
  }

  .edit-form .input {
    flex: 1;
    min-height: 32px;
    font-size: 13px;
  }

  .add-row {
    display: flex;
    gap: 8px;
  }

  .add-row .input {
    flex: 1;
  }
</style>
