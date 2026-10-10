<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { SvelteSet } from 'svelte/reactivity';
  import { _ } from 'svelte-i18n';
  import { apiGet, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import type { AnketaSummary } from '../api/types';
  import { openNotesForExport, type OwnNotesRow } from '../anketa/notesExport';
  import { ensureUnlocked } from '../crypto/identity.svelte';
  import { isEmptyNotesBlob } from '../crypto/privateNotes';
  import { formatDisplayDate } from '../datePreference.svelte';
  import {
    ALL_COLLEAGUES,
    extractColleagues,
    filterNotes,
    joinAndSortNotes,
    type OpenedNote,
  } from '../report/privateNotesList';
  import ReportTabStrip from '../report/ReportTabStrip.svelte';

  /**
   * Every private note of mine in one place, to find one without remembering
   * its meeting (GitHub issue #243). Read-only, and as last saved: the notes
   * are written on the meeting's page.
   *
   * This page could put every note on the screen at once, so a note's text is
   * in the page only after a click on that note (or on "Show all notes"),
   * never by loading the page or by searching. Searching does narrow the list
   * to the meetings whose note has the word, which is accepted. See
   * docs/decisions/2026-10-09-private-notes-in-reports.md.
   */

  // Null while loading. Raw: set once, never changed in place.
  let loaded = $state.raw<{
    notes: OpenedNote[];
    meetings: AnketaSummary[];
  } | null>(null);
  let loadError = $state<string | null>(null);
  let isDemo = $state(false);

  let colleague = $state(ALL_COLLEAGUES);
  let query = $state('');
  // The notes whose text is shown, always among the listed ones. Never
  // stored: the page opens with none.
  const shown = new SvelteSet<string>();

  // Derived, not built once on load, so a language switch renames "Deleted user".
  const entries = $derived(
    loaded
      ? joinAndSortNotes(loaded.notes, loaded.meetings, {
          formatDate: formatDisplayDate,
          deletedColleague: $_('anketaList.deletedCounterpart'),
        })
      : [],
  );
  const colleagues = $derived(extractColleagues(entries));
  const visible = $derived(filterNotes(entries, colleague, query));
  // What "Show all notes" shows: the listed notes. An unreadable note has no
  // text to show.
  const showable = $derived(visible.filter((entry) => entry.text !== null));
  const allShown = $derived(
    showable.every((entry) => shown.has(entry.anketaId)),
  );

  // A note the filters take out of the list is hidden again, so no text
  // comes back by itself when the filter or the search is changed later.
  $effect(() => {
    const listed = new Set(visible.map((entry) => entry.anketaId));
    untrack(() => {
      for (const anketaId of [...shown]) {
        if (!listed.has(anketaId)) shown.delete(anketaId);
      }
    });
  });

  const readAbort = abortOnDestroy();

  onMount(() => {
    Promise.all([
      ensureUnlocked(),
      apiGet<AnketaSummary[]>('/api/anketas', { signal: readAbort }),
      apiGet<OwnNotesRow[]>('/api/me/private-notes', { signal: readAbort }),
    ])
      .then(async ([identity, meetings, rows]) => {
        const notes: OpenedNote[] = [];
        for (const row of rows) {
          // Leaving the page cancels the fetches, not this loop.
          if (readAbort.aborted) return;
          const text = await openNotesForExport(
            row,
            identity.userId,
            identity.publicKey,
            identity.privateKey,
          );
          notes.push({
            anketaId: row.anketaId,
            // An emptied note that can't be opened holds nothing: left out
            // like any empty note, not listed as one that can't be opened.
            text: text === null && isEmptyNotesBlob(row.notesBlob) ? '' : text,
          });
        }
        if (readAbort.aborted) return;
        isDemo = identity.isDemo;
        loaded = { notes, meetings };
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        loadError =
          error instanceof ApiError
            ? error.message
            : $_('privateNotes.loadError');
      });
  });

  function toggleNote(anketaId: string): void {
    if (!shown.delete(anketaId)) shown.add(anketaId);
  }

  function toggleAll(): void {
    if (allShown) {
      shown.clear();
      return;
    }
    for (const entry of showable) shown.add(entry.anketaId);
  }
</script>

<main>
  <h1>{$_('report.title')}</h1>
  <ReportTabStrip active="notes" />

  {#if loadError}
    <p class="banner-error" role="alert">{loadError}</p>
  {:else if loaded === null}
    <p class="text-muted">{$_('privateNotes.loading')}</p>
  {:else}
    <p class="text-muted intro">
      {isDemo ? $_('privateNotes.demoSubtitle') : $_('reportNotes.intro')}
    </p>

    {#if entries.length === 0}
      <p class="text-muted">{$_('reportNotes.empty')}</p>
    {:else}
      <div class="card filters">
        <div class="field">
          <label for="notes-colleague"
            >{$_('createAnketa.counterpartLabel')}</label
          >
          <select id="notes-colleague" class="input" bind:value={colleague}>
            <option value={ALL_COLLEAGUES}>{$_('reportNotes.everyone')}</option>
            {#each colleagues as option (option.key)}
              <option value={option.key}>{option.label}</option>
            {/each}
          </select>
        </div>
        <div class="field">
          <label for="notes-search">{$_('reportNotes.searchLabel')}</label>
          <input
            id="notes-search"
            type="search"
            class="input"
            autocomplete="off"
            placeholder={$_('reportNotes.searchPlaceholder')}
            bind:value={query}
          />
        </div>
        {#if showable.length > 0}
          <button type="button" class="btn btn-secondary" onclick={toggleAll}>
            {allShown ? $_('reportNotes.hideAll') : $_('reportNotes.showAll')}
          </button>
        {/if}
      </div>

      <!-- Always mounted, so "nothing matches" is announced when it appears. -->
      <p class="text-muted no-match" role="status">
        {visible.length === 0 ? $_('reportNotes.noMatch') : ''}
      </p>

      <div class="notes">
        {#each visible as entry (entry.anketaId)}
          <article class="card note">
            <div class="note-header">
              <span class="note-meeting">
                {#if entry.meeting}
                  <strong>{entry.meeting.displayDate}</strong>
                  <span>{entry.meeting.colleagueLabel}</span>
                  {#if entry.meeting.archived}
                    <span class="tag tag-neutral"
                      >{$_('anketaList.badgeArchived')}</span
                    >
                  {/if}
                {:else}
                  <!-- No meeting to name or link to: a row this account's
                       meeting list doesn't have. -->
                  <span>—</span>
                {/if}
              </span>
              {#if entry.meeting}
                <a href="/anketas/{entry.anketaId}"
                  >{$_('reportNotes.openMeeting')}</a
                >
              {/if}
              {#if entry.text !== null}
                <button
                  type="button"
                  class="btn btn-secondary"
                  aria-expanded={shown.has(entry.anketaId)}
                  onclick={() => toggleNote(entry.anketaId)}
                >
                  {shown.has(entry.anketaId)
                    ? $_('reportNotes.hideNote')
                    : $_('reportNotes.showNote')}
                </button>
              {/if}
            </div>
            {#if entry.text === null}
              <!-- Not .text-muted: its contrast isn't checked on this surface. -->
              <p class="note-unreadable">
                {$_('privateNotes.unreadable')}
              </p>
            {:else if shown.has(entry.anketaId)}
              <p class="note-text">{entry.text}</p>
            {/if}
          </article>
        {/each}
      </div>
    {/if}
  {/if}
</main>

<style>
  main {
    max-width: 48rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  .intro {
    font-size: 13px;
    margin: 0 0 16px;
  }

  .filters {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    margin-bottom: 20px;
  }

  .filters .field {
    flex: 1;
    min-width: 180px;
  }

  .no-match {
    margin: 0;
  }

  .notes {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  /* The private-notes panel's surface (anketa/PrivateNotes.svelte), so a
     note reads as private here too. */
  .note {
    gap: 10px;
    background: var(--color-notes-surface);
    border: 1px dashed color-mix(in srgb, var(--color-text) 30%, transparent);
  }

  .note-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
    font-size: 14px;
  }

  .note-meeting {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
    margin-right: auto;
    min-width: 0;
    overflow-wrap: anywhere;
  }

  .note-header .btn {
    font-size: 12px;
    padding: 4px 8px;
  }

  .note-text,
  .note-unreadable {
    margin: 0;
    font-size: 14px;
  }

  .note-text {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
  }
</style>
