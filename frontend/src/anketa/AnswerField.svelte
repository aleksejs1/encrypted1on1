<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { QuestionField, ListEntry, AnswerValue } from './questions';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { renderAnswerMarkdown } from './markdown';
  import MarkdownEditor from './MarkdownEditor.svelte';

  let {
    field,
    value = $bindable<AnswerValue>(),
    readonly = false,
    hasOpenEntryEdit = $bindable<boolean | undefined>(),
    anketaId,
  }: {
    field: QuestionField;
    value?: AnswerValue;
    readonly?: boolean;
    /**
     * Mirrors whether a list entry's inline edit is currently open and
     * uncommitted — the parent (Anketa.svelte) reads this to keep its own
     * outer per-side Save/Cancel disabled while it's true, so a click there
     * can't silently save-over or discard typed-but-not-yet-"Save"d entry
     * text (see Anketa.svelte's `anyEntryEditOpen`). No fallback default
     * (unlike a typical `$bindable`) — like `value` above, the bind target
     * is a dynamic record key (`fieldsWithOpenEntryEdit[field.id]`) that's
     * `undefined` until first set, and Svelte's `$bindable(false)` form
     * rejects binding to an expression that's currently `undefined`;
     * callers treat `undefined` the same as `false`.
     */
    hasOpenEntryEdit?: boolean;
    /**
     * The anketa this instance's `value` belongs to. Anketa.svelte's router
     * reuses its own component instance across a same-page navigation to a
     * *different* anketa id with no remount (see its own `editingMyAnswers`
     * docblock) — and since `{#each ... as field (field.id)}` keys only on
     * the static, shared `field.id` (e.g. `achievementEntries`), this
     * AnswerField instance is reused right along with it. Without knowing
     * `anketaId` changed, an inline edit left open on one anketa would
     * survive the navigation with a stale `editingEntryId`/`editingText`,
     * silently reappearing (or worse, going unnoticed by the parent's
     * `hasOpenEntryEdit` tracking, since the effect below never re-runs
     * unless something it reads actually changes) on the next anketa that
     * happens to reuse the same field id.
     */
    anketaId?: string;
  } = $props();

  let newEntryText = $state('');
  let editingEntryId = $state<string | null>(null);
  let editingText = $state('');

  /** Same "one open at a time" reasoning as CommentThread's/outcomes' anotherActionOpen. */
  const anotherEntryEditOpen = $derived(editingEntryId !== null);

  $effect(() => {
    hasOpenEntryEdit = editingEntryId !== null;
  });

  /**
   * Discard any in-progress inline edit when this field becomes readonly
   * (e.g. the outer per-side "Cancel" reverts myAnswers and flips
   * editingMyAnswers off) — otherwise the stale, supposedly-abandoned text
   * would silently reappear pre-filled the next time editing is re-enabled.
   * Reaching this readonly with an entry still open is now itself only a
   * defensive fallback: the outer Save/Cancel that would trigger it stay
   * disabled via hasOpenEntryEdit above while an entry edit is open.
   */
  $effect(() => {
    if (readonly) {
      editingEntryId = null;
      editingText = '';
    }
  });

  /** See the `anketaId` prop doc above — an open inline edit must never leak across anketas. */
  $effect(() => {
    void anketaId;
    editingEntryId = null;
    editingText = '';
  });

  function toggleCheckbox(optionValue: string, checked: boolean) {
    const current =
      Array.isArray(value) && typeof value[0] !== 'object'
        ? (value as string[])
        : [];
    value = checked
      ? [...current, optionValue]
      : current.filter((v) => v !== optionValue);
  }

  function addListEntry() {
    if (!newEntryText.trim()) return;
    const current = Array.isArray(value) ? (value as ListEntry[]) : [];
    const entry: ListEntry = {
      id: crypto.randomUUID(),
      date: new Date().toISOString(),
      text: newEntryText.trim(),
    };
    value = [...current, entry];
    newEntryText = '';
  }

  function removeListEntry(id: string) {
    const current = Array.isArray(value) ? (value as ListEntry[]) : [];
    value = current.filter((entry) => entry.id !== id);
    if (editingEntryId === id) {
      editingEntryId = null;
      editingText = '';
    }
  }

  function startEditEntry(entry: ListEntry) {
    editingEntryId = entry.id;
    editingText = entry.text;
  }

  function cancelEditEntry() {
    editingEntryId = null;
    editingText = '';
  }

  function saveEditEntry() {
    if (!editingText.trim() || editingEntryId === null) return;
    const current = Array.isArray(value) ? (value as ListEntry[]) : [];
    const index = current.findIndex((entry) => entry.id === editingEntryId);
    // The entry may have been removed (this tab or another) while being edited — nothing to save.
    if (index === -1) {
      cancelEditEntry();
      return;
    }
    const updated = [...current];
    updated[index] = { ...updated[index], text: editingText.trim() };
    value = updated;
    cancelEditEntry();
  }
</script>

<div class="field">
  <span class="label">{$_(field.labelKey)}</span>

  {#if field.type === 'radio'}
    <div class="options">
      {#each field.options ?? [] as option (option.value)}
        <label class="radio">
          <input
            type="radio"
            name={field.id}
            value={option.value}
            checked={value === option.value}
            disabled={readonly}
            onchange={() => (value = option.value)}
          /><span class="dot"></span>
          {$_(option.labelKey)}
        </label>
      {/each}
    </div>
  {:else if field.type === 'checkboxes'}
    <div class="pills">
      {#each field.options ?? [] as option (option.value)}
        {@const checked =
          Array.isArray(value) && (value as string[]).includes(option.value)}
        <button
          type="button"
          class="tag pill"
          aria-pressed={checked}
          disabled={readonly}
          onclick={() => toggleCheckbox(option.value, !checked)}
        >
          {$_(option.labelKey)}
        </button>
      {/each}
    </div>
  {:else if field.type === 'text'}
    {#if readonly}
      {@const text = typeof value === 'string' ? value.trim() : ''}
      {#if text}
        <!-- eslint-disable-next-line svelte/no-at-html-tags -- renderAnswerMarkdown sanitizes with a DOMPurify tag/attribute allowlist, see markdown.ts -->
        <div class="answer-text">{@html renderAnswerMarkdown(text)}</div>
      {:else}
        <p class="answer-text text-muted">{$_('answerField.noAnswer')}</p>
      {/if}
    {:else}
      <MarkdownEditor
        value={typeof value === 'string' ? value : ''}
        onChange={(next) => (value = next)}
      />
    {/if}
  {:else if field.type === 'list'}
    <ul class="entries">
      {#each (value as ListEntry[]) ?? [] as entry (entry.id)}
        <li class="entry">
          {#if editingEntryId === entry.id && !readonly}
            <input
              type="text"
              class="input entry-edit-input"
              bind:value={editingText}
              onkeydown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  saveEditEntry();
                } else if (e.key === 'Escape') {
                  e.preventDefault();
                  cancelEditEntry();
                }
              }}
            />
            <button
              type="button"
              class="btn btn-secondary entry-save"
              disabled={!editingText.trim()}
              onclick={saveEditEntry}
            >
              {$_('common.save')}
            </button>
            <button
              type="button"
              class="btn btn-ghost entry-cancel"
              onclick={cancelEditEntry}
            >
              {$_('common.cancel')}
            </button>
          {:else}
            <span class="entry-text">{entry.text}</span>
            <span class="text-muted entry-date"
              >{formatDisplayDate(entry.date)}</span
            >
            {#if !readonly}
              <button
                type="button"
                class="btn btn-ghost entry-edit"
                disabled={anotherEntryEditOpen}
                onclick={() => startEditEntry(entry)}
              >
                {$_('common.edit')}
              </button>
              <button
                type="button"
                class="btn btn-ghost entry-remove"
                disabled={anotherEntryEditOpen}
                onclick={() => removeListEntry(entry.id)}
              >
                {$_('common.remove')}
              </button>
            {/if}
          {/if}
        </li>
      {/each}
    </ul>
    {#if !readonly}
      <div class="add-entry">
        <input
          type="text"
          class="input"
          bind:value={newEntryText}
          placeholder={$_('answerField.addEntryPlaceholder')}
          onkeydown={(e) =>
            e.key === 'Enter' && (e.preventDefault(), addListEntry())}
        />
        <button type="button" class="btn btn-secondary" onclick={addListEntry}
          >{$_('common.add')}</button
        >
      </div>
    {/if}
  {/if}
</div>

<style>
  .field {
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .label {
    font-size: 12px;
    color: color-mix(in srgb, var(--color-text) 70%, transparent);
  }

  .options {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
  }

  .pills {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .pill {
    cursor: pointer;
    border: 1px solid var(--color-divider);
    background: transparent;
    padding: 7px 14px;
  }

  .pill[aria-pressed='true'] {
    background: var(--color-accent);
    color: var(--color-on-accent);
    border-color: var(--color-accent);
  }

  .pill:disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }

  .entries {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }

  .entry {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 8px 10px;
    background: var(--color-bg);
    border-radius: var(--radius-sm);
    font-size: 13px;
    flex-wrap: wrap;
  }

  .entry-text {
    flex: 1;
  }

  .entry-date {
    font-size: 11px;
    white-space: nowrap;
  }

  .entry-edit,
  .entry-remove,
  .entry-save,
  .entry-cancel {
    font-size: 11px;
    padding: 2px 4px;
  }

  .entry-edit-input {
    flex: 1;
    min-width: 120px;
  }

  .add-entry {
    display: flex;
    gap: 8px;
  }

  .add-entry .input {
    flex: 1;
  }
</style>
