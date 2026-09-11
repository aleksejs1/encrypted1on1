/**
 * Pure text-selection arithmetic for `MarkdownEditor.svelte`'s formatting toolbar — no DOM/Svelte
 * dependency, so the line-boundary and cursor-offset math (exactly the kind of off-by-one-prone
 * code this project's own multi-tab-state-machine postmortem warns about, see CLAUDE.md) is
 * directly unit-testable rather than relying on manual toolbar clicking to catch a regression.
 */

export interface EditResult {
  next: string;
  cursorStart: number;
  cursorEnd: number;
}

/**
 * Wraps the current selection with `before`/`after` (e.g. `**bold**`), or inserts an empty pair
 * with the cursor left in between when nothing is selected.
 */
export function wrapSelection(
  value: string,
  start: number,
  end: number,
  before: string,
  after: string = before,
): EditResult {
  const selected = value.slice(start, end);
  const next =
    value.slice(0, start) + before + selected + after + value.slice(end);
  const cursorStart = start + before.length;
  return { next, cursorStart, cursorEnd: cursorStart + selected.length };
}

/** Prefixes every line touched by the current selection (e.g. `> `, `- `, `1. `, `## `). */
export function applyLinePrefix(
  value: string,
  start: number,
  end: number,
  prefix: string,
): EditResult {
  // `start === 0` is a special case, not just an optimization: `lastIndexOf('\n', -1)` clamps
  // its negative fromIndex to 0, which re-includes index 0 itself in the search — so if `value`
  // happens to start with '\n', it wrongly reports a "preceding" newline at the cursor's own
  // position instead of correctly reporting none.
  const lineStart = start === 0 ? 0 : value.lastIndexOf('\n', start - 1) + 1;
  const nextNewline = value.indexOf('\n', end);
  const lineEnd = nextNewline === -1 ? value.length : nextNewline;
  const block = value.slice(lineStart, lineEnd);
  const prefixed = block
    .split('\n')
    .map((line) => prefix + line)
    .join('\n');
  const next = value.slice(0, lineStart) + prefixed + value.slice(lineEnd);
  return {
    next,
    cursorStart: lineStart,
    cursorEnd: lineStart + prefixed.length,
  };
}

/**
 * Inserts `[selected text](urlPlaceholder)` and selects the placeholder URL so typing
 * immediately replaces it — no `window.prompt()` dialog, which would be an odd, blocking
 * interruption for a text field.
 */
export function insertLink(
  value: string,
  start: number,
  end: number,
  linkPlaceholder: string,
  urlPlaceholder: string,
): EditResult {
  const linkText = value.slice(start, end) || linkPlaceholder;
  const inserted = `[${linkText}](${urlPlaceholder})`;
  const next = value.slice(0, start) + inserted + value.slice(end);
  const cursorStart = start + linkText.length + 3;
  return { next, cursorStart, cursorEnd: cursorStart + urlPlaceholder.length };
}
