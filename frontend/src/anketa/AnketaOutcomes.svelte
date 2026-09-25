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
  import {
    beginAction,
    fallbackFocusOptions,
    findRow,
    ignoreHeldEnter,
    refocus,
    type RefocusOptions,
  } from './keepFocus';

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

  let section = $state<HTMLElement>();
  let heading = $state<HTMLHeadingElement>();
  let addForm = $state<HTMLFormElement>();

  /**
   * The outcome's own row (checkbox, text, buttons — not its comment
   * thread); see keepFocus.ts's findRow().
   */
  function outcomeRow(itemId: string): HTMLElement | undefined {
    return findRow(section, 'data-outcome-id', itemId);
  }

  /**
   * keepFocus.ts's refocus() for an outcome row (GitHub issue #151). With
   * `fallback` (a delete), a gone row hands focus to the heading with those
   * options: not the add input, which would open the on-screen keyboard after
   * a tap.
   */
  function refocusOutcome(
    row: HTMLElement | undefined,
    selector: string,
    {
      fallback,
      startedOn,
    }: { fallback?: FocusOptions } & Pick<RefocusOptions, 'startedOn'> = {},
  ): Promise<void> {
    return refocus(row, selector, {
      startedOn,
      onRootGone: fallback && (() => heading?.focus(fallback)),
    });
  }

  async function handleAddOutcome(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    if (!newOutcomeText.trim() || addingOutcome) return;

    const startedOn = beginAction();
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
    // The add input is disabled while adding, which drops its focus.
    void refocus(addForm, '.outcome-add-input', { startedOn });
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
    void refocusOutcome(outcomeRow(item.id), '.outcome-edit-input');
  }

  function cancelEditOutcome(): void {
    const itemId = editingOutcomeId;
    editingOutcomeId = null;
    editOutcomeText = '';
    editOutcomeError = null;
    if (itemId) void refocusOutcome(outcomeRow(itemId), '.outcome-edit-btn');
  }

  function startDeleteOutcome(itemId: string): void {
    confirmingDeleteOutcomeId = itemId;
    deleteOutcomeError = null;
    // The safe choice first: Enter held down on Delete can't also confirm.
    void refocusOutcome(outcomeRow(itemId), '.outcome-cancel-delete-btn');
  }

  function cancelDeleteOutcome(): void {
    const itemId = confirmingDeleteOutcomeId;
    confirmingDeleteOutcomeId = null;
    if (itemId) void refocusOutcome(outcomeRow(itemId), '.outcome-delete-btn');
  }

  async function handleEditOutcomeSubmit(
    event: SubmitEvent,
    itemId: string,
  ): Promise<void> {
    event.preventDefault();
    if (!editOutcomeText.trim() || editOutcomeBusy) return;
    const row = outcomeRow(itemId);
    const startedOn = beginAction();
    editOutcomeBusy = true;
    editOutcomeError = null;
    let saved = false;
    try {
      await updateOutcomes((current) =>
        editOutcome(current, itemId, myUserId, editOutcomeText.trim()),
      );
      editingOutcomeId = null;
      editOutcomeText = '';
      saved = true;
    } catch (error) {
      editOutcomeError =
        error instanceof ApiError
          ? error.message
          : $_('anketa.errorEditOutcome');
    } finally {
      editOutcomeBusy = false;
    }
    void refocusOutcome(
      row,
      saved ? '.outcome-edit-btn' : '.outcome-edit-input',
      { startedOn },
    );
  }

  async function handleDeleteOutcomeConfirm(
    itemId: string,
    click: MouseEvent,
  ): Promise<void> {
    // A double-click's second click: Confirm delete renders where Delete
    // was, so it would otherwise confirm with no real confirmation.
    if (click.detail > 1) return;
    const row = outcomeRow(itemId);
    const startedOn = beginAction();
    deleteOutcomeBusy = true;
    deleteOutcomeError = null;
    let deleted = false;
    try {
      await updateOutcomes((current) =>
        deleteOutcome(current, itemId, myUserId),
      );
      confirmingDeleteOutcomeId = null;
      deleted = true;
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
    // On success the row is normally gone, and focus goes to the heading. If
    // it's still there, its Delete button is back.
    void refocusOutcome(
      row,
      deleted ? '.outcome-delete-btn' : '.outcome-confirm-delete-btn',
      { fallback: fallbackFocusOptions(click), startedOn },
    );
  }
</script>

<section class="card" bind:this={section} onkeydowncapture={ignoreHeldEnter}>
  <div class="heading-row heading-row-tight">
    <!-- tabindex="-1": focusable from script only, where focus lands once a
         deleted outcome's row is gone (GitHub issue #151). -->
    <h2 tabindex="-1" bind:this={heading}>{$_('anketa.outcomesHeading')}</h2>
    <LockIcon encrypted />
  </div>
  <p class="text-muted outcomes-note">{$_('anketa.outcomesNote')}</p>

  <div class="outcomes-list">
    {#each items as item (item.id)}
      <div class="outcome-item">
        <div class="entry outcome-entry" data-outcome-id={item.id}>
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
                class="input outcome-edit-input"
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
                    class="btn btn-ghost btn-action outcome-confirm-delete-btn"
                    onclick={(click) =>
                      handleDeleteOutcomeConfirm(item.id, click)}
                    disabled={deleteOutcomeBusy}
                  >
                    {$_('commentThread.confirmDelete')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action outcome-cancel-delete-btn"
                    onclick={cancelDeleteOutcome}
                    disabled={deleteOutcomeBusy}
                  >
                    {$_('commentThread.cancel')}
                  </button>
                </span>
              {:else}
                <span class="outcome-actions">
                  <button
                    type="button"
                    class="btn btn-ghost btn-action outcome-edit-btn"
                    onclick={() => startEditOutcome(item)}
                    disabled={anotherOutcomeActionOpen}
                  >
                    {$_('commentThread.edit')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action outcome-delete-btn"
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

  <form class="add-row" onsubmit={handleAddOutcome} bind:this={addForm}>
    <input
      type="text"
      class="input outcome-add-input"
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
