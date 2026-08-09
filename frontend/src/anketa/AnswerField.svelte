<script lang="ts">
  import { _ } from 'svelte-i18n';
  import type { QuestionField, ListEntry, AnswerValue } from './questions';

  let {
    field,
    value = $bindable<AnswerValue>(),
    readonly = false,
  }: { field: QuestionField; value?: AnswerValue; readonly?: boolean } = $props();

  let newEntryText = $state('');

  function toggleCheckbox(optionValue: string, checked: boolean) {
    const current = Array.isArray(value) && typeof value[0] !== 'object' ? (value as string[]) : [];
    value = checked ? [...current, optionValue] : current.filter((v) => v !== optionValue);
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
        <label>
          <input
            type="radio"
            name={field.id}
            value={option.value}
            checked={value === option.value}
            disabled={readonly}
            onchange={() => (value = option.value)}
          />
          {$_(option.labelKey)}
        </label>
      {/each}
    </div>
  {:else if field.type === 'checkboxes'}
    <div class="options">
      {#each field.options ?? [] as option (option.value)}
        <label>
          <input
            type="checkbox"
            checked={Array.isArray(value) && (value as string[]).includes(option.value)}
            disabled={readonly}
            onchange={(e) => toggleCheckbox(option.value, e.currentTarget.checked)}
          />
          {$_(option.labelKey)}
        </label>
      {/each}
    </div>
  {:else if field.type === 'text'}
    <textarea
      value={typeof value === 'string' ? value : ''}
      disabled={readonly}
      oninput={(e) => (value = e.currentTarget.value)}
    ></textarea>
  {:else if field.type === 'list'}
    <ul class="entries">
      {#each (value as ListEntry[]) ?? [] as entry (entry.id)}
        <li>
          <span class="entry-date">{new Date(entry.date).toLocaleDateString()}</span>
          <span>{entry.text}</span>
          {#if !readonly}
            <button type="button" onclick={() => removeListEntry(entry.id)}>{$_('common.remove')}</button>
          {/if}
        </li>
      {/each}
    </ul>
    {#if !readonly}
      <div class="add-entry">
        <input
          type="text"
          bind:value={newEntryText}
          placeholder={$_('answerField.addEntryPlaceholder')}
          onkeydown={(e) => e.key === 'Enter' && (e.preventDefault(), addListEntry())}
        />
        <button type="button" onclick={addListEntry}>{$_('common.add')}</button>
      </div>
    {/if}
  {/if}
</div>

<style>
  .field {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin-bottom: 1rem;
  }

  .label {
    font-weight: 600;
  }

  .options {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  textarea {
    min-height: 4rem;
    font: inherit;
  }

  .entries {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  .entries li {
    display: flex;
    gap: 0.5rem;
    align-items: baseline;
  }

  .entry-date {
    color: #6b6b6b;
    font-size: 0.8rem;
    white-space: nowrap;
  }

  .add-entry {
    display: flex;
    gap: 0.5rem;
  }
</style>
