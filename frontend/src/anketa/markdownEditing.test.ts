import { describe, expect, it } from 'vitest';
import { applyLinePrefix, insertLink, wrapSelection } from './markdownEditing';

describe('wrapSelection', () => {
  it('wraps a selected range with before/after and selects the wrapped text', () => {
    const result = wrapSelection('hello world', 0, 5, '**');
    expect(result.next).toBe('**hello** world');
    expect(result.cursorStart).toBe(2);
    expect(result.cursorEnd).toBe(7);
  });

  it('inserts an empty pair with the cursor left in between when nothing is selected', () => {
    const result = wrapSelection('hello world', 5, 5, '**');
    expect(result.next).toBe('hello**** world');
    expect(result.cursorStart).toBe(7);
    expect(result.cursorEnd).toBe(7);
  });

  it('supports distinct before/after markers (e.g. code)', () => {
    const result = wrapSelection('a value', 2, 7, '`', '`');
    expect(result.next).toBe('a `value`');
  });
});

describe('applyLinePrefix', () => {
  it('prefixes a single line with the cursor inside it', () => {
    const result = applyLinePrefix('first\nsecond\nthird', 6, 6, '- ');
    expect(result.next).toBe('first\n- second\nthird');
    expect(result.cursorStart).toBe(6);
    expect(result.cursorEnd).toBe(14);
  });

  it('does not duplicate a leading newline when the cursor sits at position 0', () => {
    // Regression guard: `lastIndexOf('\n', -1)` clamps to 0 and would otherwise report the
    // string's own leading '\n' as a "preceding" newline at the cursor's own position.
    const result = applyLinePrefix('\nSecond line', 0, 0, '> ');
    expect(result.next).toBe('> \nSecond line');
  });

  it('prefixes every line spanned by a multi-line selection', () => {
    const value = 'one\ntwo\nthree';
    const result = applyLinePrefix(value, 0, value.length, '- ');
    expect(result.next).toBe('- one\n- two\n- three');
  });

  it('prefixes the last line correctly when there is no trailing newline', () => {
    const result = applyLinePrefix('only line', 3, 3, '## ');
    expect(result.next).toBe('## only line');
  });

  it('does not touch lines outside the selection', () => {
    const value = 'keep\nchange\nkeep';
    const start = value.indexOf('change');
    const end = start + 'change'.length;
    const result = applyLinePrefix(value, start, end, '> ');
    expect(result.next).toBe('keep\n> change\nkeep');
  });
});

describe('insertLink', () => {
  it('wraps a selection as link text and selects the URL placeholder', () => {
    const result = insertLink('see this', 4, 8, 'link text', 'url');
    expect(result.next).toBe('see [this](url)');
    expect(result.cursorStart).toBe(11);
    expect(result.cursorEnd).toBe(14);
    expect(result.next.slice(result.cursorStart, result.cursorEnd)).toBe('url');
  });

  it('falls back to the link-text placeholder when nothing is selected', () => {
    const result = insertLink('', 0, 0, 'link text', 'url');
    expect(result.next).toBe('[link text](url)');
  });
});
