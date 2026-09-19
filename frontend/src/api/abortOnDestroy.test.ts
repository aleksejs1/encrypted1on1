import { describe, expect, it } from 'vitest';
import { isAbortError } from './abortOnDestroy';

describe('isAbortError', () => {
  it('recognizes a real fetch abort rejection', () => {
    expect(isAbortError(new DOMException('Aborted', 'AbortError'))).toBe(true);
  });

  it('rejects a DOMException of a different kind', () => {
    expect(
      isAbortError(new DOMException('Not allowed', 'NotAllowedError')),
    ).toBe(false);
  });

  it('rejects a plain Error', () => {
    expect(isAbortError(new Error('network down'))).toBe(false);
  });

  it('rejects non-Error values', () => {
    expect(isAbortError('AbortError')).toBe(false);
    expect(isAbortError(null)).toBe(false);
    expect(isAbortError(undefined)).toBe(false);
  });
});
