<script lang="ts">
  import { tick } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { renderAnswerMarkdown } from './markdown';
  import {
    applyLinePrefix as computeLinePrefix,
    insertLink as computeLink,
    wrapSelection as computeWrap,
    type EditResult,
  } from './markdownEditing';

  let {
    value,
    onChange,
  }: {
    value: string;
    onChange: (next: string) => void;
  } = $props();

  let activeTab = $state<'source' | 'preview'>('source');
  let textareaEl = $state<HTMLTextAreaElement | undefined>();

  /**
   * Runs a toolbar action against the current textarea and selection, applies the resulting edit,
   * then restores focus and selection after Svelte re-renders the textarea's `value` — a plain
   * synchronous `el.setSelectionRange()` right after the write would run before the DOM reflects
   * the new value. The one place that needs `textareaEl` to actually exist, so every toolbar
   * button shares this single null-check rather than each re-deriving it.
   */
  function runToolbarAction(compute: (el: HTMLTextAreaElement) => EditResult) {
    const el = textareaEl;
    if (!el) return;
    const { next, cursorStart, cursorEnd } = compute(el);
    onChange(next);
    void tick().then(() => {
      el.focus();
      el.setSelectionRange(cursorStart, cursorEnd);
    });
  }

  /**
   * Declarative toolbar config — each entry is (i18n label key, glyph, edit computation) rather
   * than its own hand-rolled `<button>` block, so a new formatting action is one array entry
   * instead of another near-identical ~6-line template block.
   */
  const TOOLBAR_BUTTONS: {
    labelKey: string;
    glyph: string;
    compute: (el: HTMLTextAreaElement) => EditResult;
  }[] = [
    {
      labelKey: 'answerField.toolbar.bold',
      glyph: 'B',
      compute: (el) =>
        computeWrap(value, el.selectionStart, el.selectionEnd, '**'),
    },
    {
      labelKey: 'answerField.toolbar.italic',
      glyph: 'I',
      compute: (el) =>
        computeWrap(value, el.selectionStart, el.selectionEnd, '*'),
    },
    {
      labelKey: 'answerField.toolbar.heading',
      glyph: 'H',
      compute: (el) =>
        computeLinePrefix(value, el.selectionStart, el.selectionEnd, '## '),
    },
    {
      labelKey: 'answerField.toolbar.quote',
      glyph: '”',
      compute: (el) =>
        computeLinePrefix(value, el.selectionStart, el.selectionEnd, '> '),
    },
    {
      labelKey: 'answerField.toolbar.bulletList',
      glyph: '•',
      compute: (el) =>
        computeLinePrefix(value, el.selectionStart, el.selectionEnd, '- '),
    },
    {
      labelKey: 'answerField.toolbar.numberedList',
      glyph: '1.',
      compute: (el) =>
        computeLinePrefix(value, el.selectionStart, el.selectionEnd, '1. '),
    },
    {
      labelKey: 'answerField.toolbar.code',
      glyph: '</>',
      compute: (el) =>
        computeWrap(value, el.selectionStart, el.selectionEnd, '`'),
    },
    {
      labelKey: 'answerField.toolbar.link',
      glyph: '\u{1F517}',
      compute: (el) =>
        computeLink(
          value,
          el.selectionStart,
          el.selectionEnd,
          $_('answerField.toolbar.linkPlaceholder'),
          $_('answerField.toolbar.urlPlaceholder'),
        ),
    },
  ];
</script>

<div class="markdown-editor">
  <div class="tabs" role="tablist">
    <button
      type="button"
      class="tab-btn"
      class:tab-btn-active={activeTab === 'source'}
      role="tab"
      aria-selected={activeTab === 'source'}
      onclick={() => (activeTab = 'source')}
    >
      {$_('answerField.sourceTab')}
    </button>
    <button
      type="button"
      class="tab-btn"
      class:tab-btn-active={activeTab === 'preview'}
      role="tab"
      aria-selected={activeTab === 'preview'}
      onclick={() => (activeTab = 'preview')}
    >
      {$_('answerField.previewTab')}
    </button>
  </div>

  {#if activeTab === 'source'}
    <div
      class="toolbar"
      role="toolbar"
      aria-label={$_('answerField.toolbar.label')}
    >
      {#each TOOLBAR_BUTTONS as button (button.labelKey)}
        <button
          type="button"
          class="btn btn-action"
          aria-label={$_(button.labelKey)}
          onclick={() => runToolbarAction(button.compute)}
        >
          {button.glyph}
        </button>
      {/each}
    </div>
    <textarea
      bind:this={textareaEl}
      class="input"
      {value}
      oninput={(e) => onChange(e.currentTarget.value)}></textarea>
  {:else}
    <!-- eslint-disable-next-line svelte/no-at-html-tags -- renderAnswerMarkdown sanitizes with a DOMPurify tag/attribute allowlist, see markdown.ts -->
    <div class="answer-text preview">{@html renderAnswerMarkdown(value)}</div>
  {/if}
</div>

<style>
  .markdown-editor {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .tabs {
    display: flex;
    gap: 6px;
  }

  .toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
  }

  .preview {
    min-height: 90px;
    padding: 6px 14px;
    background: var(--color-surface);
    border: 1px solid var(--color-divider);
    border-radius: var(--radius-sm);
  }
</style>
