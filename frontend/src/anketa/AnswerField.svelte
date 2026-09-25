<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { QuestionField, ListEntry, AnswerValue } from './questions';
  import { formatDisplayDate } from '../datePreference.svelte';
  import { renderAnswerMarkdown } from './markdown';
  import MarkdownEditor from './MarkdownEditor.svelte';
  import {
    fallbackFocusOptions,
    findRow,
    ignoreHeldEnter,
    refocus,
  } from './keepFocus';
  import {
    GENERIC_LABEL_KEYS,
    isAnswerEmpty,
    selectedOptions,
  } from './answerDisplay';

  let {
    field,
    value = $bindable<AnswerValue>(),
    readonly: readonlyProp = false,
    collapsed = false,
    hasOpenEntryEdit = $bindable<boolean | undefined>(),
    anketaId,
    onFocusLost,
  }: {
    field: QuestionField;
    value?: AnswerValue;
    readonly?: boolean;
    /**
     * The collapsed read-only view (GitHub issue #131): an unanswered value
     * renders as one "No answer." line instead of its type-specific empty
     * output, and a generic input caption (GENERIC_LABEL_KEYS) is dropped
     * above an answer. Separate from `readonly` on purpose — my own side is
     * readonly but not collapsed during an in-flight Save, so Save never
     * flips empty fields to "No answer." and back. Implies readonly. A
     * radio/checkbox answer also shows only its chosen option(s) as text
     * rather than the full disabled option list (GitHub issue #135).
     */
    collapsed?: boolean;
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
    /**
     * Called when a removed list entry took keyboard focus with it, so the
     * parent can focus something that still exists: AnswerBlock's question
     * heading, the same fallback CommentThread uses (GitHub issue #151).
     */
    onFocusLost?: (options: FocusOptions) => void;
  } = $props();

  // `collapsed` implies readonly, so collapsed-but-editable can't be rendered.
  const readonly = $derived(readonlyProp || collapsed);

  let newEntryText = $state('');
  let editingEntryId = $state<string | null>(null);
  let editingText = $state('');

  /** Same "one open at a time" reasoning as CommentThread's/outcomes' anotherActionOpen. */
  const anotherEntryEditOpen = $derived(editingEntryId !== null);

  $effect(() => {
    hasOpenEntryEdit = editingEntryId !== null;
    // Same self-clear on unmount as CommentThread's hasOpenAction — the
    // collapsed view unmounts fields as they become empty, and a stuck true
    // would disable the parent's Publish/Save/Cancel for good.
    return () => {
      hasOpenEntryEdit = false;
    };
  });

  const empty = $derived(isAnswerEmpty(field, value));
  const chosenOptions = $derived(selectedOptions(field, value));
  const showEmptyLine = $derived(collapsed && empty);
  const hideLabel = $derived(
    collapsed && !empty && GENERIC_LABEL_KEYS.has(field.labelKey),
  );

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
    if (readonly) closeEntryEdit();
  });

  /** See the `anketaId` prop doc above — an open inline edit must never leak across anketas. */
  $effect(() => {
    void anketaId;
    closeEntryEdit();
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

  let root = $state<HTMLDivElement>();

  /** The `<li>` for list entry `entryId`; see keepFocus.ts's findRow(). */
  function entryRow(entryId: string): HTMLElement | undefined {
    return findRow(root, 'data-entry-id', entryId);
  }

  /**
   * keepFocus.ts's refocus() for a list entry row (GitHub issue #151). With
   * `fallback`, a gone row hands focus to the parent via `onFocusLost`.
   */
  function refocusEntry(
    row: HTMLElement | undefined,
    selector: string,
    fallback?: FocusOptions,
  ): Promise<void> {
    return refocus(row, selector, {
      onRootGone: fallback && (() => onFocusLost?.(fallback)),
    });
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

  function removeListEntry(id: string, click: MouseEvent) {
    // A double-click's second click: the next entry's Remove (or, after
    // Cancel, this entry's own) renders where it lands.
    if (click.detail > 1) return;
    const row = entryRow(id);
    const current = Array.isArray(value) ? (value as ListEntry[]) : [];
    value = current.filter((entry) => entry.id !== id);
    // Defensive only: Remove is hidden on the row being edited and disabled on
    // every other row while an edit is open.
    if (editingEntryId === id) closeEntryEdit();
    // The row went with its Remove button, so onFocusLost takes over. Not
    // the add input, which would open the on-screen keyboard after a tap.
    void refocusEntry(row, '.entry-remove', fallbackFocusOptions(click));
  }

  function startEditEntry(entry: ListEntry) {
    editingEntryId = entry.id;
    editingText = entry.text;
    void refocusEntry(entryRow(entry.id), '.entry-edit-input');
  }

  function closeEntryEdit() {
    editingEntryId = null;
    editingText = '';
  }

  function cancelEditEntry() {
    const entryId = editingEntryId;
    closeEntryEdit();
    if (entryId) void refocusEntry(entryRow(entryId), '.entry-edit');
  }

  function saveEditEntry() {
    if (!editingText.trim() || editingEntryId === null) return;
    const current = Array.isArray(value) ? (value as ListEntry[]) : [];
    const entryId = editingEntryId;
    const index = current.findIndex((entry) => entry.id === entryId);
    // The entry may have been removed (this tab or another) while being edited — nothing to save.
    if (index === -1) {
      closeEntryEdit();
      return;
    }
    const updated = [...current];
    updated[index] = { ...updated[index], text: editingText.trim() };
    value = updated;
    closeEntryEdit();
    void refocusEntry(entryRow(entryId), '.entry-edit');
  }
</script>

<!-- data-field-id: a stable hook for frontend/scripts' generators, which
     run in every UI locale and so can't find a field by its label text. -->
<div class="field" data-field-id={field.id} bind:this={root}>
  {#if !hideLabel}
    <span class="label">{$_(field.labelKey)}</span>
  {/if}

  {#if showEmptyLine}
    <p class="text-muted answer-empty field-empty">
      {$_('answerField.noAnswer')}
    </p>
  {:else if collapsed && field.type === 'radio'}
    <!-- At most one option for a radio; {#each} rather than [0], so this
         never reads past an empty list. -->
    {#each chosenOptions as option (option.value)}
      <p class="answer-choice">{$_(option.labelKey)}</p>
    {/each}
  {:else if collapsed && field.type === 'checkboxes'}
    <!-- role="list": WebKit drops list semantics once list-style is none. -->
    <ul class="pills answer-choices" role="list">
      {#each chosenOptions as option (option.value)}
        <li class="tag pill pill-chosen">{$_(option.labelKey)}</li>
      {/each}
    </ul>
  {:else if field.type === 'radio'}
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
    <!-- role="list": see the choices list above. -->
    <!-- ignoreHeldEnter here, not on the whole field: a text field's
         textarea takes a held Enter for new lines. -->
    <ul class="entries" role="list" onkeydowncapture={ignoreHeldEnter}>
      {#each (value as ListEntry[]) ?? [] as entry (entry.id)}
        <li class="entry" data-entry-id={entry.id}>
          {#if editingEntryId === entry.id && !readonly}
            <!-- A real form, so Enter saves through the browser's implicit
                 submission, which never fires on an Enter that commits an
                 IME composition. -->
            <form
              class="entry-edit-form"
              onsubmit={(e) => {
                e.preventDefault();
                saveEditEntry();
              }}
            >
              <input
                type="text"
                class="input entry-edit-input"
                bind:value={editingText}
                onkeydown={(e) => {
                  if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEditEntry();
                  }
                }}
              />
              <button
                type="submit"
                class="btn btn-secondary entry-save"
                disabled={!editingText.trim()}
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
            </form>
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
                onclick={(click) => removeListEntry(entry.id, click)}
              >
                {$_('common.remove')}
              </button>
            {/if}
          {/if}
        </li>
      {/each}
    </ul>
    {#if !readonly}
      <!-- A real form for the same IME reason as the entry edit above. -->
      <form
        class="add-entry"
        onsubmit={(e) => {
          e.preventDefault();
          addListEntry();
        }}
      >
        <input
          type="text"
          class="input"
          bind:value={newEntryText}
          placeholder={$_('answerField.addEntryPlaceholder')}
        />
        <button type="submit" class="btn btn-secondary"
          >{$_('common.add')}</button
        >
      </form>
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

  .pill[aria-pressed='true'],
  .pill-chosen {
    background: var(--color-accent);
    color: var(--color-on-accent);
    border-color: var(--color-accent);
  }

  .pill:disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }

  /* After .pill, which has the same specificity. */
  .pill-chosen {
    cursor: default;
  }

  .answer-choices {
    list-style: none;
    margin: 0;
    padding: 0;
  }

  .answer-choice {
    margin: 0;
    font-size: 14px;
    overflow-wrap: break-word;
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

  /* The form only groups the edit's controls; they stay flex items of the
     entry row. */
  .entry-edit-form {
    display: contents;
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
