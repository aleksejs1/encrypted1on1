<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { ApiError } from '../api/client';
  import { resolveAuthorLabel } from './authorLabel';
  import InlineMarkdown from './InlineMarkdown.svelte';
  import LockIcon from './LockIcon.svelte';
  import {
    beginAction,
    fallbackFocusOptions,
    findRow,
    ignoreHeldEnter,
    refocus,
  } from './keepFocus';
  import {
    addTopic,
    newTopic,
    deleteTopic,
    editTopic,
    setTopicDiscussed,
    type TopicAction,
    type TopicItem,
  } from './topics';

  /**
   * The shared "Topics to discuss" card (GitHub issue #206), above both
   * sides' answers. The page keys it by anketa id, so its state never
   * follows the page to another meeting. `updateTopics` queues a change in
   * the page's TopicsSync and resolves once it's saved; `items` follows the
   * sync's list, which can change under any form open here.
   */
  let {
    items,
    myUserId,
    authorNames,
    archived,
    locked,
    oneOff,
    updateTopics,
  }: {
    items: TopicItem[];
    myUserId: string;
    authorNames: Record<string, string>;
    /** Frozen: the server refuses topic saves on an archived meeting. */
    archived: boolean;
    /** Being archived right now: nothing can be changed, and the forms stay as they are. */
    locked: boolean;
    /** A one-off meeting has no successor, so its topics carry nowhere. */
    oneOff: boolean;
    updateTopics: (
      apply: (current: TopicItem[]) => TopicItem[],
    ) => Promise<void>;
  } = $props();

  /**
   * The one action open in the card, as last set here. What the card acts
   * on is `action` below: an archived meeting has none, and neither does an
   * edit or delete whose topic is no longer in the list (deleted from
   * another tab), so a row that is gone can't leave the card stuck.
   */
  let openAction = $state<TopicAction>({ kind: 'idle' });
  const action = $derived.by((): TopicAction => {
    const open = openAction;
    if (archived) return { kind: 'idle' };
    if (
      (open.kind === 'editing' || open.kind === 'confirmingDelete') &&
      !items.some((item) => item.id === open.id)
    ) {
      return { kind: 'idle' };
    }
    return open;
  });

  let newText = $state('');
  let editText = $state('');
  /** The topic of the last add that failed, kept for its retry. */
  let failedAdd: TopicItem | null = null;
  let error = $state<string | null>(null);
  /**
   * Ticks whose save is in flight, by topic id: the box shows the intended
   * state at once, and goes back to the saved one if the save fails.
   */
  // $state.raw: entries are compared by identity, which a proxy would break.
  let pendingDiscussed = $state.raw<Record<string, { discussed: boolean }>>({});

  let section = $state<HTMLElement>();
  let heading = $state<HTMLHeadingElement>();
  let addForm = $state<HTMLFormElement>();

  function topicRow(itemId: string): HTMLElement | undefined {
    return findRow(section, 'data-topic-id', itemId);
  }

  function messageFor(failure: unknown, fallbackKey: string): string {
    return failure instanceof ApiError ? failure.message : $_(fallbackKey);
  }

  function isDiscussed(item: TopicItem): boolean {
    return pendingDiscussed[item.id]?.discussed ?? item.discussed;
  }

  async function handleAdd(event: SubmitEvent): Promise<void> {
    event.preventDefault();
    const text = newText.trim();
    if (!text || action.kind !== 'idle' || locked) return;

    const startedOn = beginAction();
    // The same item again for a retry of the same text: the failed save may
    // have landed with its response lost, and must not add the topic twice.
    // Different text is a new topic: treating it as a rewording could
    // overwrite the first one if this is really another topic.
    const item =
      failedAdd?.text === text ? failedAdd : newTopic(myUserId, text);
    openAction = { kind: 'adding' };
    error = null;
    try {
      await updateTopics((current) => addTopic(current, item));
      newText = '';
      failedAdd = null;
    } catch (failure) {
      failedAdd = item;
      error = messageFor(failure, 'anketa.errorAddOutcome');
    }
    if (openAction.kind === 'adding') openAction = { kind: 'idle' };
    // The add input is disabled while adding, which drops its focus.
    void refocus(addForm, '.topic-add-input', { startedOn });
  }

  /**
   * The box stays enabled while its save runs, so it keeps keyboard focus,
   * and may be changed again meanwhile: the page saves one change after
   * another. Only the latest change of a topic clears its pending state.
   */
  async function handleSetDiscussed(
    itemId: string,
    discussed: boolean,
  ): Promise<void> {
    if (archived || locked) return;
    const mine = { discussed };
    pendingDiscussed = { ...pendingDiscussed, [itemId]: mine };
    error = null;
    try {
      await updateTopics((current) =>
        setTopicDiscussed(current, itemId, discussed),
      );
    } catch (failure) {
      error = messageFor(failure, 'anketa.errorUpdateOutcome');
    }
    if (pendingDiscussed[itemId] === mine) {
      pendingDiscussed = Object.fromEntries(
        Object.entries(pendingDiscussed).filter(([id]) => id !== itemId),
      );
    }
  }

  function startEdit(item: TopicItem): void {
    openAction = { kind: 'editing', id: item.id, saving: false };
    editText = item.text;
    error = null;
    void refocus(topicRow(item.id), '.topic-edit-input');
  }

  function cancelEdit(itemId: string): void {
    openAction = { kind: 'idle' };
    void refocus(topicRow(itemId), '.topic-edit-btn');
  }

  async function handleEditSubmit(
    event: SubmitEvent,
    itemId: string,
  ): Promise<void> {
    event.preventDefault();
    const text = editText.trim();
    if (!text || !isEditing(itemId) || savingEdit || locked) return;

    const row = topicRow(itemId);
    const startedOn = beginAction();
    openAction = { kind: 'editing', id: itemId, saving: true };
    error = null;
    let saved = false;
    try {
      await updateTopics((current) =>
        editTopic(current, itemId, myUserId, text),
      );
      saved = true;
    } catch (failure) {
      error = messageFor(failure, 'anketa.errorEditOutcome');
    }
    // Still this save's: not closed, or replaced, in the meantime.
    if (openAction.kind === 'editing' && openAction.id === itemId) {
      openAction = saved
        ? { kind: 'idle' }
        : { kind: 'editing', id: itemId, saving: false };
    }
    void refocus(row, saved ? '.topic-edit-btn' : '.topic-edit-input', {
      startedOn,
    });
  }

  function startDelete(itemId: string): void {
    openAction = { kind: 'confirmingDelete', id: itemId, deleting: false };
    error = null;
    // The safe choice first: Enter held down on Delete can't also confirm.
    void refocus(topicRow(itemId), '.topic-cancel-delete-btn');
  }

  function cancelDelete(itemId: string): void {
    openAction = { kind: 'idle' };
    void refocus(topicRow(itemId), '.topic-delete-btn');
  }

  async function handleDeleteConfirm(
    itemId: string,
    click: MouseEvent,
  ): Promise<void> {
    // A double-click's second click: Confirm delete renders where Delete
    // was, so it would otherwise confirm with no real confirmation.
    if (click.detail > 1 || !isConfirmingDelete(itemId) || deleting || locked) {
      return;
    }

    const row = topicRow(itemId);
    const startedOn = beginAction();
    openAction = { kind: 'confirmingDelete', id: itemId, deleting: true };
    error = null;
    let deleted = false;
    try {
      await updateTopics((current) => deleteTopic(current, itemId, myUserId));
      deleted = true;
    } catch (failure) {
      error = messageFor(failure, 'anketa.errorDeleteOutcome');
    }
    if (openAction.kind === 'confirmingDelete' && openAction.id === itemId) {
      openAction = deleted
        ? { kind: 'idle' }
        : { kind: 'confirmingDelete', id: itemId, deleting: false };
    }
    // On success the row is gone, and focus goes to the heading: not the add
    // input, which would open the on-screen keyboard after a tap.
    const focusOptions = fallbackFocusOptions(click);
    void refocus(
      row,
      deleted ? '.topic-delete-btn' : '.topic-confirm-delete-btn',
      { startedOn, onRootGone: () => heading?.focus(focusOptions) },
    );
  }

  function isEditing(itemId: string): boolean {
    return action.kind === 'editing' && action.id === itemId;
  }

  function isConfirmingDelete(itemId: string): boolean {
    return action.kind === 'confirmingDelete' && action.id === itemId;
  }

  const savingEdit = $derived(action.kind === 'editing' && action.saving);
  const deleting = $derived(
    action.kind === 'confirmingDelete' && action.deleting,
  );
</script>

<!-- An archived meeting from before this list existed has none: no empty card. -->
{#if !archived || items.length > 0}
  <section class="card" bind:this={section} onkeydowncapture={ignoreHeldEnter}>
    <div class="heading-row heading-row-tight">
      <!-- tabindex="-1": focusable from script only, where focus lands once
           a deleted topic's row is gone. -->
      <h2 tabindex="-1" bind:this={heading}>{$_('anketa.topicsHeading')}</h2>
      <LockIcon encrypted />
    </div>
    {#if !archived}
      <p class="text-muted topics-note">
        {$_('anketa.topicsNote')}
        {#if !oneOff}{$_('anketa.topicsCarryOver')}{/if}
      </p>
    {/if}

    <div class="topics-list">
      {#each items as item (item.id)}
        <div
          class="topic"
          class:discussed={isDiscussed(item)}
          data-topic-id={item.id}
        >
          <input
            type="checkbox"
            class="topic-checkbox"
            aria-label={`${$_('anketa.discussed')}: ${item.text}`}
            title={$_(
              isDiscussed(item) ? 'anketa.discussed' : 'anketa.markAsDiscussed',
            )}
            checked={isDiscussed(item)}
            disabled={archived ||
              locked ||
              isEditing(item.id) ||
              isConfirmingDelete(item.id)}
            onchange={(event) =>
              handleSetDiscussed(item.id, event.currentTarget.checked)}
          />
          {#if isEditing(item.id)}
            <form
              class="edit-form"
              onsubmit={(event) => handleEditSubmit(event, item.id)}
            >
              <input
                type="text"
                class="input topic-edit-input"
                aria-label={$_('anketa.topicsEditLabel')}
                bind:value={editText}
                disabled={savingEdit}
              />
              <button
                type="submit"
                class="btn btn-secondary"
                disabled={savingEdit || locked || !editText.trim()}
              >
                {$_('commentThread.save')}
              </button>
              <button
                type="button"
                class="btn btn-ghost"
                onclick={() => cancelEdit(item.id)}
                disabled={savingEdit}
              >
                {$_('commentThread.cancel')}
              </button>
            </form>
          {:else}
            <!-- Inline Markdown, like the "What else to discuss" entries the
                 list replaces (GitHub issue #165). -->
            <InlineMarkdown class="topic-text" text={item.text} />
            <span class="tag tag-neutral"
              >{resolveAuthorLabel(
                item.authorId,
                myUserId,
                authorNames,
                $_('anketa.you'),
              )}</span
            >
            {#if item.authorId === myUserId && !archived}
              <span class="topic-actions">
                {#if isConfirmingDelete(item.id)}
                  <button
                    type="button"
                    class="btn btn-ghost btn-action topic-confirm-delete-btn"
                    onclick={(click) => handleDeleteConfirm(item.id, click)}
                    disabled={deleting || locked}
                  >
                    {$_('commentThread.confirmDelete')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action topic-cancel-delete-btn"
                    onclick={() => cancelDelete(item.id)}
                    disabled={deleting}
                  >
                    {$_('commentThread.cancel')}
                  </button>
                {:else}
                  <button
                    type="button"
                    class="btn btn-ghost btn-action topic-edit-btn"
                    onclick={() => startEdit(item)}
                    disabled={action.kind !== 'idle' || locked}
                  >
                    {$_('commentThread.edit')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action topic-delete-btn"
                    onclick={() => startDelete(item.id)}
                    disabled={action.kind !== 'idle' || locked}
                  >
                    {$_('commentThread.delete')}
                  </button>
                {/if}
              </span>
            {/if}
          {/if}
        </div>
      {:else}
        <p class="text-muted topics-empty">{$_('anketa.topicsEmpty')}</p>
      {/each}
    </div>

    <!-- Not once archived: nothing here can be retried, and the page says
         why. -->
    {#if error && !archived}
      <p role="alert" class="banner-error">{error}</p>
    {/if}

    {#if !archived}
      <form class="add-row" onsubmit={handleAdd} bind:this={addForm}>
        <input
          type="text"
          class="input topic-add-input"
          aria-label={$_('anketa.topicsPlaceholder')}
          placeholder={$_('anketa.topicsPlaceholder')}
          bind:value={newText}
          disabled={action.kind !== 'idle'}
        />
        <button
          type="submit"
          class="btn btn-secondary"
          disabled={action.kind !== 'idle' || locked || !newText.trim()}
        >
          {action.kind === 'adding' ? $_('anketa.adding') : $_('anketa.add')}
        </button>
      </form>
    {/if}
  </section>
{/if}

<style>
  .heading-row-tight {
    margin-bottom: 2px;
  }

  .topics-note {
    font-size: 12px;
    margin: 0 0 8px;
  }

  .topics-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .topics-empty {
    margin: 0;
    font-size: 13px;
  }

  .topic {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
    padding: 8px 10px;
    background: var(--color-bg);
    border-radius: var(--radius-sm);
    font-size: 13px;
  }

  .topic-checkbox {
    width: 16px;
    height: 16px;
    flex: none;
  }

  /* :global: the span belongs to InlineMarkdown.svelte. */
  .topic :global(.topic-text) {
    flex: 1;
    overflow-wrap: anywhere;
  }

  .topic.discussed :global(.topic-text) {
    text-decoration: line-through;
    color: var(--color-text-muted);
  }

  .topic-actions {
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
