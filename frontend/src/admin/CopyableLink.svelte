<script lang="ts">
  import { _ } from 'svelte-i18n';

  /**
   * What a "copy link" button did (GitHub issue #163): "Link copied.", or,
   * where the clipboard couldn't be written, the link in a field to copy by
   * hand. Used by the template editor's share link and the preview page's
   * "Copy link for admin".
   */
  const {
    status,
    link,
  }: { status: 'none' | 'copied' | 'failed'; link: string } = $props();

  const inputId = $props.id();
</script>

<!-- Always in the accessibility tree, so its text is announced when it
     appears; the visible copy below is hidden from screen readers. -->
<div class="sr-only" role="status">
  {status === 'copied' ? $_('templatePortability.linkCopied') : ''}
</div>
{#if status === 'copied'}
  <p aria-hidden="true">{$_('templatePortability.linkCopied')}</p>
{:else if status === 'failed'}
  <label for={inputId}>{$_('templatePortability.copyByHand')}</label>
  <input
    id={inputId}
    class="input"
    readonly
    value={link}
    onfocus={(event) => event.currentTarget.select()}
  />
{/if}
