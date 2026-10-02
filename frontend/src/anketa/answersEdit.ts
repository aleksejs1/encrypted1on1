import type { AnswerValue, Answers } from './questions';
import { unloadWarning } from './unloadWarning';

/**
 * Helpers for editing my own published answers (GitHub issue #166). Such an
 * edit is saved only by an explicit Save, never in the background (see
 * docs/decisions/2026-09-07-editable-published-anketa-answers.md), so the
 * page shows whether there's anything unsaved and warns before the tab
 * closes with it.
 */

/**
 * Whitespace-only text too, as the read-only view treats it. Not
 * answerDisplay.ts's isAnswerEmpty(), which needs each field's definition.
 */
function isBlank(value: AnswerValue): boolean {
  return (
    value === undefined ||
    (typeof value === 'string' && value.trim() === '') ||
    (Array.isArray(value) && value.length === 0)
  );
}

function byKey([a]: [string, unknown], [b]: [string, unknown]): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/** A checkbox selection is a set: re-checking an option appends it, in another order. List entries keep theirs. */
function normalized(value: AnswerValue): AnswerValue {
  return Array.isArray(value) && value.every((item) => typeof item === 'string')
    ? [...value].sort()
    : value;
}

/**
 * A string equal for two answer sets exactly when they hold the same
 * answers: key order and checkbox order are ignored, and so are blank
 * values, so a field typed into and cleared again reads as unchanged. JSON is
 * exact here, since every value is a string, a string array or a list of
 * plain entries.
 */
export function answersFingerprint(answers: Answers): string {
  return JSON.stringify(
    Object.entries(answers)
      .filter(([, value]) => !isBlank(value))
      .map(([key, value]): [string, AnswerValue] => [key, normalized(value)])
      .sort(byKey),
  );
}

/**
 * Ctrl+S, or ⌘S on macOS: saves the edit instead of opening the browser's
 * "Save page" dialog. On a non-Latin layout (Russian, say) `key` is that
 * layout's letter, so the physical S key counts then, the way the browser's
 * own shortcut does. Not on a Latin one: Dvorak's physical S key types "o".
 */
export function isSaveShortcut(event: KeyboardEvent): boolean {
  return (
    (event.ctrlKey || event.metaKey) &&
    !event.altKey &&
    !event.shiftKey &&
    (event.key.toLowerCase() === 's' ||
      (event.code === 'KeyS' && !/^[a-z]$/i.test(event.key)))
  );
}

/**
 * Where a Ctrl+S / ⌘S that saves an answers edit was pressed (`target`): in
 * answer field `fieldId` of my side's question `block`, on its edit controls
 * at the card's top (header or sticky bar) or bottom, elsewhere in my side (a
 * question heading, say), or nowhere it applies (null): outside my side, or
 * in a comment thread, whose text Save wouldn't keep. In a list's "Add an
 * entry" form whose input holds text, it's `unadded-entry`: that text isn't
 * an answer until added, so saving there would drop it. Found by the data
 * attributes AnswerBlock, AnswerField, CommentThread and Anketa.svelte set
 * for it, not by styling classes.
 */
export type SaveShortcutPlace =
  | { at: 'field'; block: HTMLElement | null; fieldId: string }
  | { at: 'unadded-entry' }
  | { at: 'top' | 'bottom' }
  | { at: 'side'; block: HTMLElement | null };

export function saveShortcutPlace(
  target: EventTarget | null,
  mySide: HTMLElement | undefined,
): SaveShortcutPlace | null {
  if (!(target instanceof HTMLElement) || !mySide) return null;
  if (!mySide.contains(target) || target.closest('[data-comment-thread]')) {
    return null;
  }
  const addEntry = target
    .closest('[data-add-entry]')
    ?.querySelector<HTMLInputElement>('input');
  if (addEntry && addEntry.value.trim() !== '') return { at: 'unadded-entry' };
  const block = target.closest<HTMLElement>('[data-question-block]');
  const field = target.closest<HTMLElement>('[data-field-id]');
  if (field)
    return { at: 'field', block, fieldId: field.dataset.fieldId ?? '' };
  const controls = target.closest<HTMLElement>('[data-answers-edit]');
  if (controls) {
    return { at: controls.dataset.answersEdit === 'bottom' ? 'bottom' : 'top' };
  }
  return { at: 'side', block };
}

/** Warns before the tab closes or reloads while there are unsaved changes. */
export const setAnswersUnloadWarning = unloadWarning();
