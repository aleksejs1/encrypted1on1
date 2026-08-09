<script lang="ts">
  import type { Comment } from './comments';

  const {
    comments,
    authorEmails,
    onSubmit,
  }: {
    comments: Comment[];
    authorEmails: Record<string, string>;
    onSubmit: (text: string) => Promise<void>;
  } = $props();

  let text = $state('');
  let submitting = $state(false);
  let error = $state<string | null>(null);
  let expanded = $state(false);

  async function handleSubmit(event: SubmitEvent) {
    event.preventDefault();
    if (!text.trim() || submitting) return;

    submitting = true;
    error = null;
    try {
      await onSubmit(text.trim());
      text = '';
    } catch {
      error = 'Could not post the comment.';
    } finally {
      submitting = false;
    }
  }
</script>

<div class="thread">
  <button type="button" class="toggle" onclick={() => (expanded = !expanded)}>
    {comments.length > 0 ? `${comments.length} comment${comments.length === 1 ? '' : 's'}` : 'Comment'}
  </button>

  {#if expanded}
    {#each comments as comment (comment.id)}
      <div class="comment">
        <span class="author">{authorEmails[comment.authorId] ?? comment.authorId}</span>
        <span class="text">{comment.text}</span>
      </div>
    {/each}

    <form onsubmit={handleSubmit}>
      <input type="text" bind:value={text} placeholder="Add a comment…" />
      <button type="submit" disabled={submitting || !text.trim()}>
        {submitting ? 'Posting…' : 'Post'}
      </button>
    </form>
    {#if error}
      <p class="error">{error}</p>
    {/if}
  {/if}
</div>

<style>
  .thread {
    margin: -0.5rem 0 1rem;
    font-size: 0.85rem;
  }

  .toggle {
    background: none;
    border: none;
    color: #6b6b6b;
    cursor: pointer;
    padding: 0;
  }

  .comment {
    display: flex;
    gap: 0.5rem;
    padding: 0.25rem 0;
  }

  .author {
    font-weight: 600;
    white-space: nowrap;
  }

  form {
    display: flex;
    gap: 0.5rem;
    margin-top: 0.25rem;
  }

  input {
    flex: 1;
  }

  .error {
    color: #c0392b;
  }
</style>
