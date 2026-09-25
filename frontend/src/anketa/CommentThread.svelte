<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { Comment } from './comments';
  import {
    beginAction,
    fallbackFocusOptions,
    findRow,
    ignoreHeldEnter,
    refocus,
    type RefocusOptions,
  } from './keepFocus';

  let {
    comments,
    authorNames,
    currentUserId,
    onSubmit,
    onEdit,
    onDelete,
    hasOpenAction = $bindable<boolean | undefined>(),
    recentlyArrivedIds = {},
    onFocusLost,
  }: {
    comments: Comment[];
    authorNames: Record<string, string>;
    currentUserId: string;
    onSubmit: (text: string) => Promise<void>;
    onEdit: (commentId: string, text: string) => Promise<void>;
    onDelete: (commentId: string) => Promise<void>;
    /**
     * Mirrors whether this thread has an own-comment edit/delete open and
     * uncommitted — bound straight through to Anketa.svelte's
     * `commentThreadsBusy` regardless of which component actually renders
     * this instance (Anketa.svelte itself for the two answer sides,
     * AnketaOutcomes.svelte/AnketaGoals.svelte for outcomes/goals/
     * checkpoints), which aggregates it across every CommentThread instance
     * on the page (there can be dozens — see comments-default-open-
     * proposal.md §2) into one "any thread busy" flag, so its live-update
     * poll knows not to wholesale-replace `allComments` out from under an
     * in-progress edit/delete. Same `bind:`/`$effect` shape as AnswerField's
     * `hasOpenEntryEdit` → `fieldsWithOpenEntryEdit`.
     * The unsent "new comment" draft (`text` below) deliberately isn't
     * included: it's local state independent of the `comments` prop, so a
     * wholesale list replace underneath it doesn't touch or discard it —
     * as long as this thread stays mounted. The collapsed read-only view
     * (GitHub issue #131 §4.5) unmounts a thread whose field becomes empty
     * with no comments left, and the draft goes with it; that edge is
     * accepted there.
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
    /**
     * Called when deleting my own comment unmounted this whole thread (the
     * collapsed read-only view hides an empty field once its last comment
     * is gone), so the parent can put keyboard focus on something that
     * still exists. See `afterRowGone()` below and GitHub issue #149.
     */
    onFocusLost?: (options: FocusOptions) => void;
  } = $props();

  let root = $state<HTMLDivElement>();

  let newCommentForm = $state<HTMLFormElement>();

  /** The `.comment` row for `commentId`; see keepFocus.ts's findRow(). */
  function commentRow(commentId: string): HTMLElement | undefined {
    return findRow(root, 'data-comment-id', commentId);
  }

  /**
   * keepFocus.ts's refocus() for a comment row (GitHub issues #149, #151).
   * With `fallback` (a delete), a gone row hands focus on via afterRowGone().
   */
  function refocusComment(
    row: HTMLElement | undefined,
    selector: string,
    {
      fallback,
      startedOn,
    }: { fallback?: FocusOptions } & Pick<RefocusOptions, 'startedOn'> = {},
  ): Promise<void> {
    return refocus(row, selector, {
      startedOn,
      onRootGone: fallback && (() => afterRowGone(fallback)),
    });
  }

  /**
   * A deleted comment's row is gone: the toggle is always there while the
   * thread is (its count now one lower). If the whole thread went with it
   * (the collapsed view hides an empty field), the parent takes over.
   */
  function afterRowGone(options: FocusOptions): void {
    if (root?.isConnected) {
      root.querySelector<HTMLElement>('.toggle')?.focus(options);
    } else {
      onFocusLost?.(options);
    }
  }

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

  /**
   * Text for the aria-live announcer below — every comment `recentlyArrivedIds`
   * currently flags, joined. Derived (not written imperatively) so it stays in
   * sync however that Record changes, and goes back to '' once the parent
   * clears it a few seconds later — nothing left for a screen reader to
   * re-announce on the next unrelated re-render.
   */
  const newlyArrivedAnnouncement = $derived(
    comments
      .filter((c) => recentlyArrivedIds[c.id])
      .map((c) => `${authorNames[c.authorId] ?? c.authorId}: ${c.text}`)
      .join('. '),
  );

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
    // Self-clears on unmount — belt-and-suspenders alongside the explicit
    // pruneStaleBusyEntries() calls in Anketa.svelte's live-update poll and
    // AnketaOutcomes.svelte's delete handler (kept as-is; this doesn't
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

    const startedOn = beginAction();
    const posted = text.trim();
    submitting = true;
    error = null;
    try {
      await onSubmit(posted);
      // Keep anything typed while the post was in flight.
      if (text.trim() === posted) text = '';
    } catch {
      error = $_('commentThread.error');
    } finally {
      submitting = false;
    }
    // The Post button is disabled while posting, which drops its focus.
    void refocus(newCommentForm, '.new-comment-input', { startedOn });
  }

  function startEdit(comment: Comment) {
    editingId = comment.id;
    editText = comment.text;
    editError = null;
    void refocusComment(commentRow(comment.id), '.edit-input');
  }

  function cancelEdit() {
    const commentId = editingId;
    editingId = null;
    editText = '';
    editError = null;
    if (commentId) void refocusComment(commentRow(commentId), '.edit-btn');
  }

  async function handleEditSubmit(event: SubmitEvent, commentId: string) {
    event.preventDefault();
    if (!editText.trim() || editBusy) return;
    const row = commentRow(commentId);
    const startedOn = beginAction();
    editBusy = true;
    editError = null;
    let saved = false;
    try {
      await onEdit(commentId, editText.trim());
      editingId = null;
      editText = '';
      saved = true;
    } catch {
      editError = $_('commentThread.error');
    } finally {
      editBusy = false;
    }
    void refocusComment(row, saved ? '.edit-btn' : '.edit-input', {
      startedOn,
    });
  }

  function startDelete(commentId: string) {
    confirmingDeleteId = commentId;
    // The safe choice first: Enter held down on Delete can't also confirm.
    void refocusComment(commentRow(commentId), '.cancel-delete-btn');
  }

  function cancelDelete() {
    const commentId = confirmingDeleteId;
    confirmingDeleteId = null;
    if (commentId) void refocusComment(commentRow(commentId), '.delete-btn');
  }

  async function handleDeleteConfirm(commentId: string, click: MouseEvent) {
    // A double-click's second click: Confirm delete renders where Delete
    // was, so it would otherwise confirm with no real confirmation.
    if (click.detail > 1) return;
    const row = commentRow(commentId);
    const startedOn = beginAction();
    deleteBusy = true;
    deleteError = null;
    let deleted = false;
    try {
      await onDelete(commentId);
      confirmingDeleteId = null;
      deleted = true;
    } catch {
      deleteError = $_('commentThread.error');
    } finally {
      deleteBusy = false;
    }
    // On success the row is normally gone, and afterRowGone() takes over. If
    // it's still there, its Delete button is back.
    void refocusComment(row, deleted ? '.delete-btn' : '.confirm-delete-btn', {
      fallback: fallbackFocusOptions(click),
      startedOn,
    });
  }
</script>

<div class="thread" bind:this={root} onkeydowncapture={ignoreHeldEnter}>
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

  <!-- Always mounted (unlike .comments below, which unmounts while
       collapsed) so the live region already exists in the accessibility
       tree before a new comment's text lands in it — a region that mounts
       with its content already inside is often not announced at all. The
       recently-arrived highlight above is a CSS-only cue; this is what
       actually reaches a screen reader for a comment that arrives via the
       live-update poll, including one that auto-expands a collapsed
       thread. -->
  <div class="sr-only" aria-live="polite" aria-atomic="true">
    {newlyArrivedAnnouncement}
  </div>

  {#if expanded}
    <div class="comments">
      {#each comments as comment (comment.id)}
        <div
          class="comment"
          class:recently-arrived={recentlyArrivedIds[comment.id]}
          data-comment-id={comment.id}
        >
          {#if editingId === comment.id}
            <form
              class="edit-form"
              onsubmit={(event) => handleEditSubmit(event, comment.id)}
            >
              <input
                type="text"
                class="input edit-input"
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
              <p role="alert" class="banner-error">{editError}</p>
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
                    class="btn btn-ghost btn-action confirm-delete-btn"
                    onclick={(click) => handleDeleteConfirm(comment.id, click)}
                    disabled={deleteBusy}
                  >
                    {$_('commentThread.confirmDelete')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action cancel-delete-btn"
                    onclick={cancelDelete}
                    disabled={deleteBusy}
                  >
                    {$_('commentThread.cancel')}
                  </button>
                </span>
              {:else}
                <span class="comment-actions">
                  <button
                    type="button"
                    class="btn btn-ghost btn-action edit-btn"
                    onclick={() => startEdit(comment)}
                    disabled={anotherActionOpen}
                  >
                    {$_('commentThread.edit')}
                  </button>
                  <button
                    type="button"
                    class="btn btn-ghost btn-action delete-btn"
                    onclick={() => startDelete(comment.id)}
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
        <p role="alert" class="banner-error">{deleteError}</p>
      {/if}
    </div>

    <form onsubmit={handleSubmit} bind:this={newCommentForm}>
      <input
        type="text"
        class="input new-comment-input"
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
      <p role="alert" class="banner-error">{error}</p>
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
