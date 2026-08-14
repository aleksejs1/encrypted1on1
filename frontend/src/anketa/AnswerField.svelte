<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { QuestionField, ListEntry, AnswerValue } from './questions';
  import { formatDisplayDate } from '../datePreference.svelte';

  let {
    field,
    value = $bindable<AnswerValue>(),
    readonly = false,
  }: {
    field: QuestionField;
    value?: AnswerValue;
    readonly?: boolean;
  } = $props();

  let newEntryText = $state('');

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
    <textarea
      class="input"
      value={typeof value === 'string' ? value : ''}
      disabled={readonly}
      oninput={(e) => (value = e.currentTarget.value)}></textarea>
  {:else if field.type === 'list'}
    <ul class="entries">
      {#each (value as ListEntry[]) ?? [] as entry (entry.id)}
        <li class="entry">
          <span class="entry-text">{entry.text}</span>
          <span class="text-muted entry-date"
            >{formatDisplayDate(entry.date)}</span
          >
          {#if !readonly}
            <button
              type="button"
              class="btn btn-ghost entry-remove"
              onclick={() => removeListEntry(entry.id)}
            >
              {$_('common.remove')}
            </button>
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

  textarea.input {
    width: 100%;
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

  .entry-remove {
    font-size: 11px;
    padding: 2px 4px;
  }

  .add-entry {
    display: flex;
    gap: 8px;
  }

  .add-entry .input {
    flex: 1;
  }
</style>
