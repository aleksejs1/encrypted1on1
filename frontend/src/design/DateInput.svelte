<script lang="ts">
  import { _ } from 'svelte-i18n';
  import { EXAMPLE_DATE, formatDate, parseDate } from '../dateFormat';
  import { dateFormatState } from '../datePreference.svelte';

  /**
   * Replaces a native `<input type="date">` — confirmed by direct testing
   * that Chromium ignores `lang`/locale entirely for that element and
   * always renders `MM/DD/YYYY`, so there's no CSS/attribute way to make a
   * native date input honor the user's chosen dateFormat preference. This
   * is a real text field the user types into (in whatever format is
   * currently selected), plus a button that opens a completely invisible
   * native `<input type="date">` via `showPicker()` for anyone who'd
   * rather use a calendar — that native input's own displayed text is
   * never shown, only its picker popup.
   *
   * `value` is always a plain `YYYY-MM-DD` string (or `''` for empty) —
   * the exact shape every existing caller already bound to its old native
   * `<input type="date">`, so swapping the element didn't require any
   * caller-side conversion.
   */
  let {
    value = $bindable(''),
    id,
    disabled = false,
    required = false,
  }: {
    value?: string;
    id?: string;
    disabled?: boolean;
    required?: boolean;
  } = $props();

  let textValue = $state('');
  let invalid = $state(false);
  let pickerEl: HTMLInputElement | undefined = $state();

  $effect(() => {
    // Re-syncs textValue whenever `value` changes from *outside* this
    // component's own typing (a caller resetting it, the native picker,
    // or the format preference itself changing) — never runs off of
    // textValue's own edits, since that's separate local state.
    void dateFormatState.format;
    textValue = value ? formatDate(value, dateFormatState.format) : '';
    invalid = false;
  });

  function commitText(): void {
    if (textValue.trim() === '') {
      invalid = false;
      value = '';
      return;
    }
    const parsed = parseDate(textValue, dateFormatState.format);
    if (parsed) {
      invalid = false;
      value = parsed;
    } else {
      invalid = true;
    }
  }

  function handlePickerChange(iso: string): void {
    value = iso;
  }
</script>

<span class="date-input">
  <input
    type="text"
    class="input"
    class:invalid
    inputmode="numeric"
    autocomplete="off"
    {id}
    {disabled}
    {required}
    bind:value={textValue}
    onblur={commitText}
    placeholder={formatDate(EXAMPLE_DATE, dateFormatState.format)}
  />
  <button
    type="button"
    class="date-input-picker-btn"
    aria-label={$_('common.pickDate')}
    {disabled}
    onclick={() => pickerEl?.showPicker?.()}
  >
    📅
  </button>
  <input
    bind:this={pickerEl}
    type="date"
    class="date-input-native"
    tabindex="-1"
    aria-hidden="true"
    {value}
    onchange={(e) => handlePickerChange(e.currentTarget.value)}
  />
</span>
{#if invalid}
  <p class="text-muted date-input-hint">
    {$_('common.invalidDate', {
      values: { example: formatDate(EXAMPLE_DATE, dateFormatState.format) },
    })}
  </p>
{/if}

<style>
  .date-input {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    position: relative;
  }

  .date-input .input {
    width: 11ch;
  }

  .date-input .input.invalid {
    border-color: var(--color-accent);
  }

  .date-input-picker-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    padding: 0;
    border: 1px solid var(--color-divider);
    border-radius: var(--radius-sm);
    background: transparent;
    cursor: pointer;
    font-size: 15px;
  }

  .date-input-picker-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }

  /* Never shown — only its native showPicker() popup is used, see the
     component docblock above for why the native element's own text
     rendering can't be trusted to follow the user's chosen format. */
  .date-input-native {
    position: absolute;
    width: 0;
    height: 0;
    padding: 0;
    border: 0;
    opacity: 0;
    pointer-events: none;
  }

  .date-input-hint {
    font-size: 12px;
    margin: 4px 0 0;
  }
</style>
