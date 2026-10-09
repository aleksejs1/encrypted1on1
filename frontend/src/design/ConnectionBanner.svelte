<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { connectionState } from '../connectivity/connectionState.svelte';

  interface Props {
    /**
     * On a meeting's page, where unpublished answers and private notes have a
     * local backup. Nothing else in the app does, so every other page gets a
     * text that promises nothing about what was typed.
     */
    meetingPage: boolean;
  }

  let { meetingPage }: Props = $props();

  const text = $derived(
    connectionState.status === 'offline'
      ? $_(
          meetingPage
            ? 'common.connectionLostMeeting'
            : 'common.connectionLostGeneral',
        )
      : connectionState.status === 'reconnected'
        ? $_('common.connectionRestored')
        : null,
  );

  // The banner is fixed, so the page and whatever sticks to the top of the
  // viewport (Anketa.svelte's edit bar, the private notes column) are moved
  // down by its real height: the text wraps to several lines on a phone.
  let height = $state(0);
  $effect(() => {
    document.documentElement.style.setProperty(
      '--connection-banner-offset',
      `${height}px`,
    );
  });
</script>

<!-- Always in the DOM and never display:none, empty while online: a live
     region added together with its text is often not announced. -->
<div
  class="connection-banner"
  role="status"
  aria-live="polite"
  aria-atomic="true"
  bind:offsetHeight={height}
>
  {#if text !== null}
    <p
      class="connection-banner-text"
      class:is-reconnected={connectionState.status === 'reconnected'}
    >
      {text}
    </p>
  {/if}
</div>

<style>
  /* Fixed, not under the app header: the header scrolls away, and the banner
     has to be seen by someone typing far down a meeting. */
  .connection-banner {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 10;
  }

  /* Whatever is scrolled to the top (a link to the archive form, keyboard
     focus) lands below the banner, not under it. Anketa.svelte's own rules
     for its edit bar add to this. */
  :global(html) {
    scroll-padding-top: var(--connection-banner-offset, 0px);
  }

  /* A state, not a failure: no error red. */
  .connection-banner-text {
    margin: 0;
    padding: 8px 20px;
    text-align: center;
    font-size: 13px;
    color: var(--color-text);
    background: var(--color-surface);
    border-bottom: 1px solid var(--color-divider);
  }

  .connection-banner-text.is-reconnected {
    color: var(--color-accent-2-800);
    background: var(--color-accent-2-100);
    border-bottom-color: var(--color-accent-2-300);
  }
</style>
