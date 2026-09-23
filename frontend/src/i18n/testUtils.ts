/**
 * The value at a dotted i18n key path (`'questions.fields.details'`) in a parsed locale
 * file, or `undefined` if any segment is missing — the same resolution svelte-i18n does
 * at runtime. Test-only (hence this file's name): used by tests that check keys referenced
 * from code/data actually exist — the app itself always goes through svelte-i18n's `$_()`.
 */
export function messageAt(messages: unknown, key: string): unknown {
  return key
    .split('.')
    .reduce<unknown>(
      (obj, part) =>
        typeof obj === 'object' && obj !== null
          ? (obj as Record<string, unknown>)[part]
          : undefined,
      messages,
    );
}
