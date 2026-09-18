import { describe, expect, it } from 'vitest';
import { pruneStaleBusyEntries } from './commentThreadsBusy';

describe('pruneStaleBusyEntries', () => {
  it('clears an id that was in previousIds but is no longer in remainingIds', () => {
    const result = pruneStaleBusyEntries(
      { 'outcome-1': true },
      ['outcome-1'],
      new Set(),
    );

    expect(result['outcome-1']).toBe(false);
  });

  it('leaves an id untouched when it is still in remainingIds', () => {
    const record = { 'outcome-1': true };
    const result = pruneStaleBusyEntries(
      record,
      ['outcome-1'],
      new Set(['outcome-1']),
    );

    expect(result['outcome-1']).toBe(true);
  });

  it('never clears an id outside previousIds, even if it is also missing from remainingIds', () => {
    // The exact regression this function was written to fix: an
    // outcome-scoped call must not wipe out a field's own busy id just
    // because that id also happens to be absent from the outcome list.
    const record = { 'field-1': true, 'outcome-1': true };
    const result = pruneStaleBusyEntries(record, ['outcome-1'], new Set());

    expect(result['field-1']).toBe(true);
    expect(result['outcome-1']).toBe(false);
  });

  it('returns the same record reference when nothing needs clearing', () => {
    const record = { 'outcome-1': true };
    const result = pruneStaleBusyEntries(
      record,
      ['outcome-1'],
      new Set(['outcome-1']),
    );

    expect(result).toBe(record);
  });
});
