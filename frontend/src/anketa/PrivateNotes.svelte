<script lang="ts">
  import { onMount } from 'svelte';
  import { _ } from 'svelte-i18n';
  import {
    loggedInUserId,
    ensureUnlocked,
    getGeneration,
  } from '../crypto/identity.svelte';
  import {
    deriveNotesBackupKey,
    notesCapPercent,
  } from '../crypto/privateNotes';
  import { shortDisplayName } from '../userDisplay';
  import { AUTOMATIC_RETRY_COUNT, NotesSession } from './notesSession';
  import {
    initialNotesModel,
    isEditable,
    notesSubtitleKey,
    type ConflictChoice,
    type NotesModel,
  } from './notesState';

  /**
   * My private notes on this anketa (GitHub issue #132 §6.2): only I can read
   * them, not the counterpart, the server or an admin. Anketa.svelte renders
   * one per anketa id (`{#key id}`), so navigating to another anketa destroys
   * this instance and its save chain finishes detached (notesSession.ts).
   */
  let {
    anketaId,
    counterpartName,
    counterpartEmail,
    counterpartDeleted,
  }: {
    anketaId: string;
    counterpartName: string;
    counterpartEmail: string;
    counterpartDeleted: boolean;
  } = $props();

  /** Remembered per browser; a convenience only, so storage may fail. */
  const PANEL_STORAGE_KEY = 'e1o1:private-notes-panel';

  // Raw: the session hands over a new model on every change, never mutated in place.
  let model = $state.raw<NotesModel>(initialNotesModel());
  let isDemo = $state(false);
  let session: NotesSession | null = null;
  let hidden = $state(readHidden());
  let confirmingLoadServer = $state(false);

  function readHidden(): boolean {
    try {
      return localStorage.getItem(PANEL_STORAGE_KEY) === 'hidden';
    } catch {
      return false;
    }
  }

  function toggleHidden(): void {
    // Hiding unmounts the textarea before its blur would save.
    if (!hidden) session?.requestSave('blur');
    hidden = !hidden;
    try {
      localStorage.setItem(PANEL_STORAGE_KEY, hidden ? 'hidden' : 'shown');
    } catch {
      // Best effort.
    }
  }

  onMount(() => {
    let cancelled = false;
    const generation = getGeneration();
    void (async () => {
      const identity = await ensureUnlocked();
      const backupKey = await deriveNotesBackupKey(identity.privateKey);
      if (cancelled) return;
      isDemo = identity.isDemo;
      session = new NotesSession({
        anketaId,
        userId: identity.userId,
        publicKey: identity.publicKey,
        privateKey: identity.privateKey,
        backupKey,
        generation,
        getGeneration,
        loggedInUserId,
        onChange: (next) => (model = next),
      });
      await session.load();
    })().catch(() => {
      if (!cancelled) {
        model = { ...model, status: 'loadError', loadErrorNeedsReload: true };
      }
    });
    return () => {
      cancelled = true;
      session?.destroy();
    };
  });

  const counterpart = $derived(
    counterpartDeleted
      ? $_('privateNotes.otherParticipant')
      : shortDisplayName(counterpartName, counterpartEmail),
  );

  const usedPercent = $derived(notesCapPercent(model.text));

  /** The visible status line. */
  const statusText = $derived.by(() => {
    switch (model.status) {
      case 'saving':
        return $_('privateNotes.saving');
      case 'idle':
        return model.ackVersion > 0 ? $_('privateNotes.saved') : '';
      case 'retrying':
        return model.retryCount > AUTOMATIC_RETRY_COUNT
          ? $_('privateNotes.retryFailed')
          : $_('privateNotes.retrying');
      default:
        return '';
    }
  });

  const stoppedText = $derived.by(() => {
    switch (model.stoppedReason) {
      case 'tooLarge':
        return $_('privateNotes.stoppedTooLarge');
      case 'invalid':
        return $_('privateNotes.stoppedInvalid', {
          values: { message: model.invalidMessage ?? '' },
        });
      case 'sessionEnded':
        return $_('privateNotes.stoppedSessionEnded');
      case 'resetElsewhere':
        return $_('privateNotes.stoppedResetElsewhere');
      default:
        return '';
    }
  });

  /**
   * What a screen reader hears: only errors and stopped states, so a meeting
   * isn't narrated with "Saved" every second.
   */
  const announcement = $derived(
    model.status === 'stopped'
      ? stoppedText
      : model.status === 'retrying'
        ? statusText
        : '',
  );

  function onInput(event: Event): void {
    session?.edit((event.currentTarget as HTMLTextAreaElement).value);
  }

  function startNewNotes(): void {
    session?.startNewNotes().catch(() => {
      model = { ...model, status: 'loadError', loadErrorNeedsReload: true };
    });
  }

  function resolve(choice: ConflictChoice): void {
    confirmingLoadServer = false;
    session?.resolveConflict(choice);
  }
</script>

<aside class="card private-notes" aria-labelledby="private-notes-heading">
  <div class="heading-row notes-heading-row">
    <svg
      class="notes-icon"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path
        d="M2 12s3.5-7 10-7c2 0 3.7.6 5.1 1.5M22 12s-3.5 7-10 7c-2 0-3.7-.6-5.1-1.5"
      ></path>
      <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
      <path d="M3 3l18 18"></path>
    </svg>
    <h2 id="private-notes-heading">{$_('privateNotes.heading')}</h2>
    <span class="tag tag-outline only-you">{$_('privateNotes.onlyYou')}</span>
    <button
      type="button"
      class="btn btn-ghost hide-toggle"
      aria-expanded={!hidden}
      aria-controls={hidden ? undefined : 'private-notes-body'}
      onclick={toggleHidden}
    >
      {hidden ? $_('privateNotes.show') : $_('privateNotes.hide')}
    </button>
  </div>

  <p id="private-notes-subtitle" class="subtitle">
    {$_(notesSubtitleKey(isDemo), { values: { counterpart } })}
  </p>

  <!-- Always mounted, so the live region exists before anything is announced. -->
  <div class="sr-only" role="status">{announcement}</div>

  {#if !hidden}
    <!-- Nothing below is rendered while hidden: the notes text isn't in the
         page at all, which matters while sharing a screen. -->
    <div id="private-notes-body" class="notes-body">
      {#if model.status === 'loading'}
        <p class="text-muted notes-message">{$_('privateNotes.loading')}</p>
      {:else if model.status === 'loadError'}
        <div class="notes-message">
          <p class="banner-error">
            {model.loadErrorNeedsReload
              ? $_('privateNotes.loadErrorReload')
              : $_('privateNotes.loadError')}
          </p>
          {#if model.loadErrorNeedsReload}
            <button
              type="button"
              class="btn btn-secondary"
              onclick={() => location.reload()}
            >
              {$_('privateNotes.reload')}
            </button>
          {:else}
            <button
              type="button"
              class="btn btn-secondary"
              onclick={() => session?.retryLoad()}
            >
              {$_('privateNotes.retry')}
            </button>
          {/if}
        </div>
      {:else if model.status === 'unreadable'}
        <div class="notes-message">
          <p class="banner-error">{$_('privateNotes.unreadable')}</p>
          <button
            type="button"
            class="btn btn-secondary"
            onclick={startNewNotes}
          >
            {$_('privateNotes.startNew')}
          </button>
        </div>
      {/if}

      {#if model.status === 'conflict'}
        <div class="banner-error conflict" role="alert">
          <p>
            {model.conflictServerText === null
              ? $_('privateNotes.conflictUnreadable')
              : $_('privateNotes.conflict')}
          </p>
          {#if confirmingLoadServer}
            <p>{$_('privateNotes.loadServerConfirm')}</p>
            <div class="conflict-actions">
              <button
                type="button"
                class="btn btn-secondary"
                onclick={() => resolve('loadServer')}
              >
                {$_('privateNotes.loadServerConfirmButton')}
              </button>
              <button
                type="button"
                class="btn btn-ghost"
                onclick={() => (confirmingLoadServer = false)}
              >
                {$_('privateNotes.cancel')}
              </button>
            </div>
          {:else}
            <div class="conflict-actions">
              {#if model.conflictServerText !== null}
                <button
                  type="button"
                  class="btn btn-primary"
                  onclick={() => resolve('keepBoth')}
                >
                  {$_('privateNotes.keepBoth')}
                </button>
              {/if}
              <button
                type="button"
                class="btn btn-secondary"
                onclick={() => resolve('keepLocal')}
              >
                {$_('privateNotes.keepLocal')}
              </button>
              {#if model.conflictServerText !== null}
                <button
                  type="button"
                  class="btn btn-ghost"
                  onclick={() => (confirmingLoadServer = true)}
                >
                  {$_('privateNotes.loadServer')}
                </button>
              {/if}
            </div>
          {/if}
        </div>
      {/if}

      {#if isEditable(model)}
        <textarea
          class="input notes-textarea"
          aria-labelledby="private-notes-heading"
          aria-describedby="private-notes-subtitle"
          placeholder={$_('privateNotes.placeholder')}
          value={model.text}
          oninput={onInput}
          onblur={() => session?.requestSave('blur')}></textarea>
      {/if}

      {#if model.status === 'stopped'}
        <p class="banner-error">{stoppedText}</p>
      {/if}

      <div class="notes-status">
        <span class="text-muted">{statusText}</span>
        {#if model.status === 'retrying' && model.retryCount > AUTOMATIC_RETRY_COUNT}
          <button
            type="button"
            class="btn btn-ghost"
            onclick={() => session?.requestSave('manual')}
          >
            {$_('privateNotes.retry')}
          </button>
        {/if}
        {#if usedPercent >= 90}
          <span class="text-muted counter">
            {$_('privateNotes.counter', {
              values: { percent: Math.min(usedPercent, 999) },
            })}
          </span>
        {/if}
      </div>

      <!-- The design's "Export keeps a copy." link joins this line with #139,
           when the data export starts including private notes. -->
      <p class="text-muted notes-footer">{$_('privateNotes.footerLoss')}</p>
    </div>
  {/if}
</aside>

<style>
  /* Not the shared cards' surface: a neutral tint (not the accent of error
     banners and new comments, not the success green) and a dashed edge, so
     the panel doesn't read as one more shared section. Checked in both
     themes by design/contrast.test.ts. */
  .private-notes {
    background: var(--color-notes-surface);
    border: 1px dashed color-mix(in srgb, var(--color-text) 30%, transparent);
  }

  .notes-heading-row h2 {
    font-size: 17px;
  }

  .notes-icon {
    width: 18px;
    height: 18px;
    flex: none;
    color: color-mix(in srgb, var(--color-text) 70%, transparent);
  }

  .hide-toggle {
    margin-left: auto;
    font-size: 12px;
    padding: 4px 8px;
  }

  .subtitle {
    margin: 0;
    font-size: 12px;
    color: var(--color-text-muted);
  }

  .notes-body {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .notes-message {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    margin: 0;
  }

  .notes-message .banner-error,
  .conflict p {
    margin: 0;
  }

  .conflict {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .conflict-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .notes-textarea {
    min-height: 140px;
    resize: vertical;
    font-size: 14px;
  }

  .notes-status {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    min-height: 20px;
    font-size: 12px;
  }

  .counter {
    margin-left: auto;
  }

  .notes-footer {
    margin: 0;
    font-size: 11px;
  }
</style>
