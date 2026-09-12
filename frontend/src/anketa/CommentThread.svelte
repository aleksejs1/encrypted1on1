<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { Comment } from './comments';

  let {
    comments,
    authorNames,
    currentUserId,
    onSubmit,
    onEdit,
    onDelete,
    hasOpenAction = $bindable<boolean | undefined>(),
    recentlyArrivedIds = {},
  }: {
    comments: Comment[];
    authorNames: Record<string, string>;
    currentUserId: string;
    onSubmit: (text: string) => Promise<void>;
    onEdit: (commentId: string, text: string) => Promise<void>;
    onDelete: (commentId: string) => Promise<void>;
    /**
     * Mirrors whether this thread has an own-comment edit/delete open and
     * uncommitted — the parent (Anketa.svelte) aggregates this across every
     * CommentThread instance on the page (there can be dozens — see
     * comments-default-open-proposal.md §2) into one "any thread busy" flag,
     * so its live-update poll knows not to wholesale-replace `allComments`
     * out from under an in-progress edit/delete. Same `bind:`/`$effect`
     * shape as AnswerField's `hasOpenEntryEdit` → `fieldsWithOpenEntryEdit`.
     * The unsent "new comment" draft (`text` below) deliberately isn't
     * included: it's local state independent of the `comments` prop, so a
     * wholesale list replace underneath it doesn't touch or discard it.
     */
    hasOpenAction?: boolean;
    /**
     * Comment ids that just arrived via a live update (not the initial page
     * load) — rendered with a brief highlight, cleared by the parent a few
     * seconds after they're added. See private/live-updates-proposal.md §7
     * (not tracked in git) for why comments specifically get this cue while
     * every other live-updated field stays silent.
     */
    recentlyArrivedIds?: Record<string, true>;
  } = $props();

  let text = $state('');
  let submitting = $state(false);
  let error = $state<string | null>(null);
  // Only the initial comment count decides the default — the one-time read below is
  // intentional, not a missed $derived, so a later comment arriving via a live update
  // doesn't fight a *manual* toggle (userToggled below) in either direction.
  // svelte-ignore state_referenced_locally
  let expanded = $state(comments.length > 0);
  // Not $state — read synchronously alongside `expanded` itself, never needs to
  // trigger a render on its own. True once the user has ever clicked the toggle,
  // in either direction; see the auto-expand $effect below for why this matters.
  let userToggled = false;

  /**
   * A thread that starts collapsed (no comments yet — the common case, see
   * comments-default-open-proposal.md §2) would otherwise silently receive a
   * live-updated comment nobody can see, defeating the point of a live
   * update — `expanded`'s one-time initializer above never re-derives from
   * `comments` on its own. Force it open specifically when a *newly-arrived*
   * comment (recentlyArrivedIds, not just any change to `comments`) shows up
   * in an untouched thread. Gated on `!userToggled` so this never overrides
   * an explicit manual collapse — a thread the user deliberately hid stays
   * hidden even if new content arrives, same intent the removed comment
   * above already had for a general "sync" case, just now scoped to real
   * live arrivals instead of applying to every `comments` change.
   */
  $effect(() => {
    if (!userToggled && comments.some((c) => recentlyArrivedIds[c.id])) {
      expanded = true;
    }
  });

  function toggleExpanded(): void {
    userToggled = true;
    expanded = !expanded;
  }

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

  $effect(() => {
    hasOpenAction = anotherActionOpen;
    // Self-clears on unmount — belt-and-suspenders alongside Anketa.svelte's
    // own explicit pruneStaleBusyEntries() calls (kept as-is; this doesn't
    // replace them, since they run synchronously within the same tick that
    // decides to prune, rather than waiting on this effect's own cleanup
    // timing). Structurally closes the same class of "stale true left
    // behind after an id-removal path nobody remembered to prune" bug for
    // any *future* removal path too, not just the ones already handled.
    return () => {
      hasOpenAction = false;
    };
  });

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
  <button type="button" class="btn btn-ghost toggle" onclick={toggleExpanded}>
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
        <div
          class="comment"
          class:recently-arrived={recentlyArrivedIds[comment.id]}
        >
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
              >{authorNames[comment.authorId] ?? comment.authorId}:</span
            >
            <span class="text">{comment.text}</span>
            {#if comment.authorId === currentUserId}
              {#if confirmingDeleteId === comment.id}
                <span class="comment-actions">
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
                    onclick={() => handleDeleteConfirm(comment.id)}
                    disabled={deleteBusy}
                  >
                    {$_('commentThread.confirmDelete')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
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
                    class="btn btn-ghost btn-action"
                    onclick={() => startEdit(comment)}
                    disabled={anotherActionOpen}
                  >
                    {$_('commentThread.edit')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action"
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

  /* Brief cue for a comment that just arrived via a live update (never on
     initial page load) — see the recentlyArrivedIds prop doc above for why
     comments specifically get this while every other live-updated field
     stays silent. Fades on its own; no interaction needed to dismiss it.
     border-radius lives here, not on the base .comment rule above, since
     it's only needed for this rule's own background-color flash to look
     right — every other comment stays exactly as square as before. */
  .comment.recently-arrived {
    border-radius: 4px;
    animation: comment-arrived 3s ease-out;
  }

  @keyframes comment-arrived {
    from {
      background-color: var(--color-accent-100);
    }
    to {
      background-color: transparent;
    }
  }

  .author {
    font-weight: 600;
    white-space: nowrap;
  }

  .comment-actions {
    display: flex;
    gap: 2px;
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
