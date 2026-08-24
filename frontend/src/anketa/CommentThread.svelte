<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { Comment } from './comments';

  const {
    comments,
    authorEmails,
    currentUserId,
    onSubmit,
    onEdit,
    onDelete,
  }: {
    comments: Comment[];
    authorEmails: Record<string, string>;
    currentUserId: string;
    onSubmit: (text: string) => Promise<void>;
    onEdit: (commentId: string, text: string) => Promise<void>;
    onDelete: (commentId: string) => Promise<void>;
  } = $props();

  let text = $state('');
  let submitting = $state(false);
  let error = $state<string | null>(null);
  let expanded = $state(false);

  let editingId = $state<string | null>(null);
  let editText = $state('');
  let editBusy = $state(false);
  let editError = $state<string | null>(null);

  let confirmingDeleteId = $state<string | null>(null);
  let deleteBusy = $state(false);
  let deleteError = $state<string | null>(null);

  /**
   * editingId/confirmingDeleteId are single, un-scoped state for the whole
   * thread — opening a second comment's edit/delete while a first one is
   * already open (typed-but-unsaved, or mid-save) would silently reassign
   * that shared state out from under it, discarding whatever the first one
   * had pending with no warning. Disabling every *other* comment's Edit/
   * Delete-opening buttons whenever either is non-null enforces "one open
   * at a time" for real, rather than only guarding the in-flight-request
   * window and leaving the (far more common) open-but-unsubmitted window
   * unguarded. Doesn't need editBusy/deleteBusy itself: they're only ever
   * true while editingId/confirmingDeleteId already point at the comment
   * being saved.
   */
  const anotherActionOpen = $derived(
    editingId !== null || confirmingDeleteId !== null,
  );

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!text.trim() || submitting) return;

    submitting = true;
    error = null;
    try {
      await onSubmit(text.trim());
      text = '';
    } catch {
      error = $_('commentThread.error');
    } finally {
      submitting = false;
    }
  }

  function startEdit(comment: Comment) {
    editingId = comment.id;
    editText = comment.text;
    editError = null;
  }

  function cancelEdit() {
    editingId = null;
    editText = '';
    editError = null;
  }

  async function handleEditSubmit(event: SubmitEvent, commentId: string) {
    event.preventDefault();
    if (!editText.trim() || editBusy) return;

    editBusy = true;
    editError = null;
    try {
      await onEdit(commentId, editText.trim());
      editingId = null;
      editText = '';
    } catch {
      editError = $_('commentThread.error');
    } finally {
      editBusy = false;
    }
  }

  async function handleDeleteConfirm(commentId: string) {
    deleteBusy = true;
    deleteError = null;
    try {
      await onDelete(commentId);
      confirmingDeleteId = null;
    } catch {
      deleteError = $_('commentThread.error');
    } finally {
      deleteBusy = false;
    }
  }
</script>

<div class="thread">
  <button
    type="button"
    class="btn btn-ghost toggle"
    onclick={() => (expanded = !expanded)}
  >
    <svg
      class="icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2.2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path
        d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.35 0-2.62-.32-3.73-.9L4 21l1.9-4.77A8.5 8.5 0 1 1 21 11.5z"
      />
    </svg>
    {$_('commentThread.toggle', { values: { count: comments.length } })}
  </button>

  {#if expanded}
    <div class="comments">
      {#each comments as comment (comment.id)}
        <div class="comment">
          {#if editingId === comment.id}
            <form
              class="edit-form"
              onsubmit={(event) => handleEditSubmit(event, comment.id)}
            >
              <input
                type="text"
                class="input"
                bind:value={editText}
                disabled={editBusy}
              />
              <button
                type="submit"
                class="btn btn-secondary"
                disabled={editBusy || !editText.trim()}
              >
                {$_('commentThread.save')}
              </button>
              <button
                type="button"
                class="btn btn-ghost"
                onclick={cancelEdit}
                disabled={editBusy}
              >
                {$_('commentThread.cancel')}
              </button>
            </form>
            {#if editError}
              <p class="banner-error">{editError}</p>
            {/if}
          {:else}
            <span class="author"
              >{authorEmails[comment.authorId] ?? comment.authorId}:</span
            >
            <span class="text">{comment.text}</span>
            {#if comment.authorId === currentUserId}
              {#if confirmingDeleteId === comment.id}
                <span class="comment-actions">
                  <button
                    type="button"
                    class="btn btn-ghost action-btn"
                    onclick={() => handleDeleteConfirm(comment.id)}
                    disabled={deleteBusy}
                  >
                    {$_('commentThread.confirmDelete')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost action-btn"
                    onclick={() => (confirmingDeleteId = null)}
                    disabled={deleteBusy}
                  >
                    {$_('commentThread.cancel')}
                  </button>
                </span>
              {:else}
                <span class="comment-actions">
                  <button
                    type="button"
                    class="btn btn-ghost action-btn"
                    onclick={() => startEdit(comment)}
                    disabled={anotherActionOpen}
                  >
                    {$_('commentThread.edit')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost action-btn"
                    onclick={() => (confirmingDeleteId = comment.id)}
                    disabled={anotherActionOpen}
                  >
                    {$_('commentThread.delete')}
                  </button>
                </span>
              {/if}
            {/if}
          {/if}
        </div>
      {/each}
      {#if deleteError}
        <p class="banner-error">{deleteError}</p>
      {/if}
    </div>

    <form onsubmit={handleSubmit}>
      <input
        type="text"
        class="input"
        bind:value={text}
        placeholder={$_('commentThread.placeholder')}
      />
      <button
        type="submit"
        class="btn btn-secondary"
        disabled={submitting || !text.trim()}
      >
        {submitting ? $_('commentThread.posting') : $_('commentThread.post')}
      </button>
    </form>
    {#if error}
      <p class="banner-error">{error}</p>
    {/if}
  {/if}
</div>

<style>
  .thread {
    font-size: 13px;
  }

  .toggle {
    font-size: 11px;
    padding: 2px 4px;
  }

  .icon {
    width: 14px;
    height: 14px;
  }

  .comments {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 6px;
  }

  .comment {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 6px;
  }

  .author {
    font-weight: 600;
    white-space: nowrap;
  }

  .comment-actions {
    display: flex;
    gap: 2px;
  }

  .action-btn {
    font-size: 11px;
    padding: 0 4px;
    opacity: 0.7;
  }

  .action-btn:hover {
    opacity: 1;
  }

  .edit-form {
    flex: 1;
    min-width: 200px;
  }

  form {
    display: flex;
    gap: 6px;
    margin-top: 6px;
  }

  form .input {
    flex: 1;
    min-height: 32px;
    font-size: 12px;
  }

  form .btn-secondary {
    padding: 4px 10px;
    font-size: 12px;
  }
</style>
