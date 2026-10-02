<script lang="ts">
  import { _ } from 'svelte-i18n';
  import AnswerField from './AnswerField.svelte';
  import CommentThread from './CommentThread.svelte';
  import type { Comment } from './comments';
  import { displayText, type Answers, type Question } from './questions';
  import { readonlyVisibleFields } from './answerDisplay';

  /**
   * One question block of an anketa side: its title, its fields, and each
   * field's comment thread. Anketa.svelte renders one per question for my
   * side and for the counterpart's side (GitHub issue #149). The keyed state
   * this binds into (`answers`, `fieldsWithOpenEntryEdit`,
   * `commentThreadsBusy`) stays owned by Anketa.svelte, whose live-update poll
   * reads it — see docs/decisions/2026-09-18-anketa-component-decomposition.md.
   */
  let {
    question,
    answers = $bindable<Answers>(),
    readonly,
    collapsed,
    showComments,
    fieldsWithOpenEntryEdit = $bindable<Record<string, boolean>>({}),
    commentThreadsBusy = $bindable<Record<string, boolean>>(),
    commentsByTarget,
    authorNames,
    myUserId,
    recentlyArrivedCommentIds,
    submitComment,
    onEditComment,
    onDeleteComment,
    anketaId,
    isDiscussed = false,
    onToggleDiscussed,
    archived = false,
    disabled = false,
  }: {
    question: Question;
    /**
     * Bound on both sides. The counterpart's side is always collapsed, so
     * AnswerField never writes to it, but this component binds it down to
     * AnswerField, and Svelte warns (ownership_invalid_binding) when that
     * passes through a prop the parent didn't bind.
     */
    answers: Answers;
    readonly: boolean;
    /** The collapsed read-only view — see AnswerField's `collapsed` prop and answerDisplay.ts. */
    collapsed: boolean;
    showComments: boolean;
    /** Only bound for my side. The counterpart's readonly fields never open an entry edit, so the fallback `{}` just absorbs their `false`. */
    fieldsWithOpenEntryEdit?: Record<string, boolean>;
    commentThreadsBusy: Record<string, boolean>;
    commentsByTarget: Map<string, Comment[]>;
    authorNames: Record<string, string>;
    myUserId: string;
    recentlyArrivedCommentIds: Record<string, true>;
    submitComment: (targetId: string, text: string) => Promise<void>;
    onEditComment: (commentId: string, text: string) => Promise<void>;
    onDeleteComment: (commentId: string) => Promise<void>;
    anketaId: string;
    isDiscussed?: boolean;
    /**
     * Omitted where the counterpart can't see this block yet (my own
     * unpublished side): no "discussed" checkbox there (GitHub issue #168).
     */
    onToggleDiscussed?: () => void;
    /** Archived: only a ticked "discussed" box is still shown. */
    archived?: boolean;
    /** The "discussed" box can't be clicked (archived, or being archived). */
    disabled?: boolean;
  } = $props();

  let heading = $state<HTMLHeadingElement>();

  /**
   * Where focus goes when a field's thread or a list entry took it along
   * (GitHub issues #149, #151), with the child's options: see keepFocus.ts's
   * fallbackFocusOptions().
   */
  function focusHeading(options: FocusOptions): void {
    heading?.focus(options);
  }

  let discussedToggle = $state<HTMLButtonElement>();
  // Read-only (archived), only a ticked box is worth showing: an empty
  // disabled one on every past anketa would read as "not discussed".
  const showDiscussedToggle = $derived(
    onToggleDiscussed !== undefined && (!archived || isDiscussed),
  );

  // A focused toggle that is about to be disabled or removed (the anketa
  // got archived) would drop focus to <body>; hand it to the heading, as
  // #149/#151 do. $effect.pre: before the DOM update, while it still has it.
  $effect.pre(() => {
    if (
      (disabled || !showDiscussedToggle) &&
      discussedToggle !== undefined &&
      document.activeElement === discussedToggle
    ) {
      heading?.focus();
    }
  });

  // One keyed loop over shownFields (not an {#if} between two loops), so
  // toggling Edit/Save/Cancel only mounts/unmounts the fields whose
  // visibility actually changes — see GitHub issue #131 §4.2.
  const shownFields = $derived(
    collapsed
      ? readonlyVisibleFields(question, answers, (fieldId) =>
          commentsByTarget.has(fieldId),
        )
      : question.fields,
  );
</script>

<!-- data-question-block: a stable hook for answersEdit.ts's saveShortcutPlace(). -->
<div class="block" class:discussed={isDiscussed} data-question-block>
  <div class="question-header">
    {#if showDiscussedToggle}
      <button
        bind:this={discussedToggle}
        type="button"
        role="checkbox"
        class="discussed-toggle"
        class:checked={isDiscussed}
        aria-checked={isDiscussed}
        {disabled}
        title={isDiscussed
          ? $_('anketa.discussed')
          : $_('anketa.markAsDiscussed')}
        aria-label={`${$_('anketa.discussed')}: ${displayText(question, $_)}`}
        onclick={onToggleDiscussed}
      >
        {#if isDiscussed}
          <svg
            class="check-icon"
            viewBox="0 0 20 20"
            fill="none"
            aria-hidden="true"
          >
            <circle cx="10" cy="10" r="9" fill="var(--color-accent)" />
            <path
              d="M6 10.5l2.5 2.5 5.5-5.5"
              stroke="var(--color-on-accent)"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        {:else}
          <svg
            class="check-icon"
            viewBox="0 0 20 20"
            fill="none"
            aria-hidden="true"
          >
            <circle
              cx="10"
              cy="10"
              r="8"
              stroke="currentColor"
              stroke-width="1.8"
            />
          </svg>
        {/if}
      </button>
    {/if}
    <!-- tabindex="-1": focusable from script only, as the fallback when
         deleting a field's last comment hides the field along with its
         thread (CommentThread's onFocusLost, GitHub issue #149), or when a
         removed list entry takes focus with it (AnswerField's, #151). -->
    <h4 tabindex="-1" bind:this={heading}>{displayText(question, $_)}</h4>
  </div>
  {#if shownFields.length === 0}
    <p class="text-muted answer-empty block-empty">
      {$_('answerField.noAnswer')}
    </p>
  {/if}
  {#each shownFields as field (field.id)}
    <AnswerField
      {field}
      bind:value={answers[field.id]}
      {readonly}
      {collapsed}
      bind:hasOpenEntryEdit={fieldsWithOpenEntryEdit[field.id]}
      {anketaId}
      onFocusLost={focusHeading}
    />
    {#if showComments}
      <CommentThread
        comments={commentsByTarget.get(field.id) ?? []}
        {authorNames}
        currentUserId={myUserId}
        onSubmit={(text) => submitComment(field.id, text)}
        onEdit={onEditComment}
        onDelete={onDeleteComment}
        bind:hasOpenAction={commentThreadsBusy[field.id]}
        recentlyArrivedIds={recentlyArrivedCommentIds}
        onFocusLost={focusHeading}
      />
    {/if}
  {/each}
</div>

<style>
  .block {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding-bottom: 18px;
    margin-bottom: 18px;
    border-bottom: 1px solid var(--color-divider);
    transition: opacity 0.2s ease-in-out;
  }

  .block.discussed {
    opacity: 0.6;
  }

  /* Contrast recovery: interacting restores legibility */
  .block.discussed:hover,
  .block.discussed:focus-within {
    opacity: 0.95;
  }

  .block:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
  }

  .question-header {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .discussed-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: none;
    padding: 0;
    margin: 0;
    cursor: pointer;
    color: var(--color-text-muted);
    border-radius: 50%;
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    transition:
      opacity 0.15s ease,
      color 0.15s ease;
  }

  .discussed-toggle:hover:not(:disabled) {
    color: var(--color-text);
  }

  .discussed-toggle:focus-visible {
    outline: 2px solid var(--color-accent);
    outline-offset: 2px;
  }

  /* No extra fading: the discussed block around it is already dimmed. */
  .discussed-toggle:disabled {
    cursor: default;
  }

  .check-icon {
    width: 18px;
    height: 18px;
    display: block;
  }

  .question-header h4 {
    margin: 0;
  }
</style>
