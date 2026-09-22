import { describe, expect, it } from 'vitest';
import {
  ANKETA_TEMPLATES,
  CURRENT_ANKETA_FORM_VERSION,
  getQuestionsForSide,
  type TemplateKey,
} from './questions';

function feelingsOptionValues(side: 'employee' | 'manager', version: number) {
  const feelings = getQuestionsForSide(side, version, 'regular').find(
    (q) => q.id === 'feelings',
  );
  const field = feelings?.fields.find((f) => f.id === 'feelingsList');

  return field?.options?.map((o) => o.value);
}

describe('getQuestionsForSide', () => {
  it('gives version 1 anketas the original six feelings options', () => {
    expect(feelingsOptionValues('employee', 1)).toEqual([
      'excited',
      'anxious',
      'confident',
      'overwhelmed',
      'motivated',
      'frustrated',
    ]);
  });

  it('gives version 2 anketas the original six plus six new feelings options', () => {
    const values = feelingsOptionValues('employee', 2);

    expect(values).toHaveLength(12);
    expect(values?.slice(0, 6)).toEqual([
      'excited',
      'anxious',
      'confident',
      'overwhelmed',
      'motivated',
      'frustrated',
    ]);
    expect(values?.slice(6)).toEqual([
      'grateful',
      'proud',
      'calm',
      'stressed',
      'bored',
      'lonely',
    ]);
  });

  it('treats every option value as unique within a version', () => {
    const values = feelingsOptionValues('employee', 2)!;
    expect(new Set(values).size).toBe(values.length);
  });

  it('leaves every other employee question unaffected by version', () => {
    const v1 = getQuestionsForSide('employee', 1, 'regular');
    const v2 = getQuestionsForSide('employee', 2, 'regular');

    expect(v1.map((q) => q.id)).toEqual(v2.map((q) => q.id));
    for (const question of v1) {
      if (question.id === 'feelings') continue;
      expect(question).toEqual(v2.find((q) => q.id === question.id));
    }
  });

  it('never varies the manager side by form version', () => {
    expect(getQuestionsForSide('manager', 1, 'regular')).toEqual(
      getQuestionsForSide('manager', 2, 'regular'),
    );
  });

  it('defines CURRENT_ANKETA_FORM_VERSION as the latest (12-option) version', () => {
    expect(
      feelingsOptionValues('employee', CURRENT_ANKETA_FORM_VERSION),
    ).toHaveLength(12);
  });

  it('returns non-empty question sets for both sides of every registered template', () => {
    for (const templateKey of ANKETA_TEMPLATES) {
      expect(
        getQuestionsForSide(
          'employee',
          CURRENT_ANKETA_FORM_VERSION,
          templateKey,
        ),
      ).not.toHaveLength(0);
      expect(
        getQuestionsForSide(
          'manager',
          CURRENT_ANKETA_FORM_VERSION,
          templateKey,
        ),
      ).not.toHaveLength(0);
    }
  });

  it('degrades an unrecognized templateKey to regular rather than throwing', () => {
    const unknown = 'made-up-template' as TemplateKey;

    expect(
      getQuestionsForSide('employee', CURRENT_ANKETA_FORM_VERSION, unknown),
    ).toEqual(
      getQuestionsForSide('employee', CURRENT_ANKETA_FORM_VERSION, 'regular'),
    );
    expect(
      getQuestionsForSide('manager', CURRENT_ANKETA_FORM_VERSION, unknown),
    ).toEqual(
      getQuestionsForSide('manager', CURRENT_ANKETA_FORM_VERSION, 'regular'),
    );
  });

  // A regression test for a bug an earlier, keyed-lookup-table version of this function
  // actually had (an Object.prototype member like "constructor" resolving instead of
  // falling back to 'regular') — the current switch-based implementation can't have that
  // specific bug (no dynamic property lookup happens at all), but this stays as a
  // documented guard against a future refactor reintroducing a lookup table without the
  // same care.
  it('degrades a prototype-shaped templateKey to regular rather than resolving an inherited Object.prototype member', () => {
    for (const prototypeKey of ['constructor', 'toString', 'hasOwnProperty']) {
      const templateKey = prototypeKey as TemplateKey;

      expect(() =>
        getQuestionsForSide(
          'employee',
          CURRENT_ANKETA_FORM_VERSION,
          templateKey,
        ),
      ).not.toThrow();
      expect(
        getQuestionsForSide(
          'employee',
          CURRENT_ANKETA_FORM_VERSION,
          templateKey,
        ),
      ).toEqual(
        getQuestionsForSide('employee', CURRENT_ANKETA_FORM_VERSION, 'regular'),
      );
    }
  });
});
