import { describe, expect, it } from 'vitest';
import { CURRENT_ANKETA_FORM_VERSION, getQuestionsForSide } from './questions';

function feelingsOptionValues(side: 'employee' | 'manager', version: number) {
  const feelings = getQuestionsForSide(side, version).find(
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
    const v1 = getQuestionsForSide('employee', 1);
    const v2 = getQuestionsForSide('employee', 2);

    expect(v1.map((q) => q.id)).toEqual(v2.map((q) => q.id));
    for (const question of v1) {
      if (question.id === 'feelings') continue;
      expect(question).toEqual(v2.find((q) => q.id === question.id));
    }
  });

  it('never varies the manager side by form version', () => {
    expect(getQuestionsForSide('manager', 1)).toEqual(
      getQuestionsForSide('manager', 2),
    );
  });

  it('defines CURRENT_ANKETA_FORM_VERSION as the latest (12-option) version', () => {
    expect(
      feelingsOptionValues('employee', CURRENT_ANKETA_FORM_VERSION),
    ).toHaveLength(12);
  });
});
