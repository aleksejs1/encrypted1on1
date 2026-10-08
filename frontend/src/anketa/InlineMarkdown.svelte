<script lang="ts">
  import { renderInlineMarkdown } from './markdown';

  // One line of user-typed text (a list entry, a shared topic, a comment),
  // rendered as inline Markdown. The one {@html} site for all of them.
  let { text, class: className = '' }: { text: string; class?: string } =
    $props();

  const html = $derived(renderInlineMarkdown(text));
</script>

<!-- eslint-disable-next-line svelte/no-at-html-tags -- renderInlineMarkdown escapes raw HTML and sanitizes with a DOMPurify tag/attribute allowlist, see markdown.ts -->
<span class={['inline-markdown', className]}>{@html html}</span>
