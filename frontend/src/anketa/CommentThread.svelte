<script lang="ts">
  import { _ } from 'svelte-i18n';
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
      error = $_('commentThread.error');
    } finally {
      submitting = false;
    }
  }
</script>

<div class="thread">
  <button type="button" class="btn btn-ghost toggle" onclick={() => (expanded = !expanded)}>
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.35 0-2.62-.32-3.73-.9L4 21l1.9-4.77A8.5 8.5 0 1 1 21 11.5z" />
    </svg>
    {$_('commentThread.toggle', { values: { count: comments.length } })}
  </button>

  {#if expanded}
    <div class="comments">
      {#each comments as comment (comment.id)}
        <div class="comment">
          <span class="author">{authorEmails[comment.authorId] ?? comment.authorId}:</span>
          <span class="text">{comment.text}</span>
        </div>
      {/each}
    </div>

    <form onsubmit={handleSubmit}>
      <input type="text" class="input" bind:value={text} placeholder={$_('commentThread.placeholder')} />
      <button type="submit" class="btn btn-secondary" disabled={submitting || !text.trim()}>
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
    gap: 6px;
  }

  .author {
    font-weight: 600;
    white-space: nowrap;
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
