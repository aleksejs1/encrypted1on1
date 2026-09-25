<script lang="ts">
  import { _ } from 'svelte-i18n';
  import AnswerField from './AnswerField.svelte';
  import CommentThread from './CommentThread.svelte';
  import type { Comment } from './comments';
  import type { Answers, Question } from './questions';
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

<div class="block">
  <!-- tabindex="-1": focusable from script only, as the fallback when
       deleting a field's last comment hides the field along with its
       thread (CommentThread's onFocusLost, GitHub issue #149), or when a
       removed list entry takes focus with it (AnswerField's, #151). -->
  <h4 tabindex="-1" bind:this={heading}>{$_(question.titleKey)}</h4>
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
  }

  .block:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
  }

  .block h4 {
    margin: 0;
  }
</style>
