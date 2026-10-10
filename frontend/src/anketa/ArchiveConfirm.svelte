<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { ArchiveConfirmation } from './archiveConfirmation';
  import { beginAction, ignoreHeldEnter, refocus } from './keepFocus';

  let {
    anketaId,
    textId,
    confirmation,
    counterpartName,
    triggerLabel,
    triggerClass,
    confirmLabel,
    note = undefined,
    swapNote = undefined,
    closing,
    busy,
    busyLabel,
    blocked,
    describedBy,
    onConfirm,
  }: {
    anketaId: string;
    /** A page-unique id for the confirmation text, which Cancel is described by. */
    textId: string;
    confirmation: ArchiveConfirmation;
    counterpartName: string;
    triggerLabel: string;
    triggerClass: string;
    /** The confirmation's own button, unless closing publishes first. */
    confirmLabel: string;
    /** A line about this way of closing, e.g. that the meeting is marked as missed. */
    note?: string;
    /**
     * Who answers as which in the next 1:1, when closing swaps the roles
     * (GitHub issue #255): said here too, since the tick may be far from
     * this button and closing can't be undone.
     */
    swapNote?: string;
    /** The meeting is being closed, from this control or the page's other one. */
    closing: boolean;
    /** That, or a plain publish of my side: nothing here can be pressed meanwhile. */
    busy: boolean;
    /** What the button says while `closing`. */
    busyLabel: string;
    /** Closing has to wait (an open answers edit); `describedBy` is the hint saying why. */
    blocked: boolean;
    describedBy: string | undefined;
    /** Publishes first if needed, then archives. Handles its own errors. */
    onConfirm: () => Promise<void>;
  } = $props();

  // The anketa the confirmation was opened on, not a boolean: the page is
  // reused when navigating to another anketa, which must not open on a
  // confirmation left over from the previous one.
  let confirmingFor = $state<string | null>(null);
  const confirming = $derived(confirmingFor === anketaId);
  let root = $state<HTMLElement>();

  const UNPUBLISHED_KEYS = {
    me: 'anketa.closeConfirmMineUnpublished',
    counterpart: 'anketa.closeConfirmCounterpartUnpublished',
    both: 'anketa.closeConfirmNeitherPublished',
  } as const;

  // The first press sends nothing (GitHub issue #229). Focus lands on Cancel,
  // so a second Enter doesn't close the meeting.
  function open(): void {
    confirmingFor = anketaId;
    void refocus(root, '[data-action="cancel-close"]');
  }

  /**
   * The second click of a double click on the button that opened the
   * confirmation, landing on whichever of its buttons is now under the
   * pointer: it must neither close the meeting nor dismiss the question.
   * This also drops any other click made right after one in the same spot,
   * which then has to be repeated: the price of never closing a meeting on
   * a click that wasn't aimed at this button.
   */
  function isDoubleClick(click: MouseEvent): boolean {
    return click.detail > 1;
  }

  function cancel(click: MouseEvent): void {
    if (isDoubleClick(click)) return;
    confirmingFor = null;
    void refocus(root, '[data-action="close"]');
  }

  async function confirm(click: MouseEvent): Promise<void> {
    if (isDoubleClick(click)) return;
    const startedOn = beginAction();
    try {
      await onConfirm();
    } finally {
      // Closed: this control is gone. Not closed: the error is shown, and
      // closing starts over from the first press.
      confirmingFor = null;
      void refocus(root, '[data-action="close"]', { startedOn });
    }
  }
</script>

<div
  class="archive-confirm"
  bind:this={root}
  onkeydowncapture={ignoreHeldEnter}
>
  {#if confirming}
    <div id={textId} class="confirm-text">
      <p>{$_('anketa.closeConfirm')}</p>
      {#if note}<p>{note}</p>{/if}
      {#if swapNote}<p>{swapNote}</p>{/if}
      {#if confirmation.publishFirst}
        <p>
          {$_('anketa.closeConfirmPublishFirst', {
            values: { name: counterpartName },
          })}
        </p>
      {/if}
      {#if confirmation.unpublished !== 'nobody'}
        <p>
          {$_(UNPUBLISHED_KEYS[confirmation.unpublished], {
            values: { name: counterpartName },
          })}
        </p>
      {/if}
    </div>
    <div class="confirm-actions">
      <button
        type="button"
        class="btn btn-secondary"
        data-action="cancel-close"
        aria-describedby={textId}
        onclick={cancel}
        disabled={busy}
      >
        {$_('anketa.cancel')}
      </button>
      <button
        type="button"
        class="btn btn-primary"
        data-action="confirm-close"
        onclick={confirm}
        disabled={busy || blocked}
        aria-describedby={blocked ? describedBy : undefined}
      >
        {closing
          ? busyLabel
          : confirmation.publishFirst
            ? $_('anketa.publishAndClose')
            : confirmLabel}
      </button>
    </div>
  {:else}
    <button
      type="button"
      class={triggerClass}
      data-action="close"
      onclick={open}
      disabled={busy || blocked}
      aria-describedby={blocked ? describedBy : undefined}
    >
      {closing ? busyLabel : triggerLabel}
    </button>
  {/if}
</div>

<style>
  .archive-confirm {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }

  .confirm-text {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 14px;
  }

  .confirm-text p {
    margin: 0;
  }

  .confirm-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
</style>
