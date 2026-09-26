import { describe, expect, it } from 'vitest';
import {
  GENERIC_LABEL_KEYS,
  isAnswerEmpty,
  readonlyVisibleFields,
  selectedOptions,
} from './answerDisplay';
import {
  ANKETA_TEMPLATES,
  CURRENT_ANKETA_FORM_VERSION,
  getQuestionsForSide,
  type Question,
  type QuestionField,
} from './questions';
import en from '../i18n/locales/en.json';
import { messageAt } from '../i18n/testUtils';

const options = [
  { value: 'good', labelKey: 'x.good' },
  { value: 'bad', labelKey: 'x.bad' },
];
const textField: QuestionField = { id: 't', type: 'text', labelKey: 'x.t' };
const listField: QuestionField = { id: 'l', type: 'list', labelKey: 'x.l' };
const radioField: QuestionField = {
  id: 'r',
  type: 'radio',
  labelKey: 'x.r',
  options,
};
const checkboxField: QuestionField = {
  id: 'c',
  type: 'checkboxes',
  labelKey: 'x.c',
  options,
};
/** Every field of every template, side and form version. */
function allFields(): QuestionField[] {
  return ANKETA_TEMPLATES.flatMap((templateKey) =>
    (['employee', 'manager'] as const).flatMap((side) =>
      Array.from({ length: CURRENT_ANKETA_FORM_VERSION }, (_, i) =>
        getQuestionsForSide(side, i + 1, templateKey),
      ).flat(),
    ),
  ).flatMap((question) => question.fields);
}

const entry = { id: 'e1', date: '2026-09-01T00:00:00.000Z', text: 'Shipped' };

describe('isAnswerEmpty', () => {
  it('treats absent and null as empty for every type', () => {
    for (const field of [textField, listField, radioField, checkboxField]) {
      expect(isAnswerEmpty(field, undefined), field.type).toBe(true);
      expect(isAnswerEmpty(field, null), field.type).toBe(true);
    }
  });

  it('text: empty and whitespace-only are empty, anything else is not', () => {
    expect(isAnswerEmpty(textField, '')).toBe(true);
    expect(isAnswerEmpty(textField, '  \n\t ')).toBe(true);
    expect(isAnswerEmpty(textField, 'Fine')).toBe(false);
    expect(isAnswerEmpty(textField, ['Fine'])).toBe(true);
  });

  it('text: Markdown-only syntax counts as answered', () => {
    expect(isAnswerEmpty(textField, '**')).toBe(false);
  });

  it('list: empty array is empty, any entry is not', () => {
    expect(isAnswerEmpty(listField, [])).toBe(true);
    expect(isAnswerEmpty(listField, [entry])).toBe(false);
    expect(isAnswerEmpty(listField, 'Shipped')).toBe(true);
  });

  it('radio: only a known option value is answered', () => {
    expect(isAnswerEmpty(radioField, '')).toBe(true);
    expect(isAnswerEmpty(radioField, 'good')).toBe(false);
    expect(isAnswerEmpty(radioField, 'unknown')).toBe(true);
    expect(isAnswerEmpty(radioField, ['good'])).toBe(true);
  });

  it('checkboxes: answered when at least one known option value is present', () => {
    expect(isAnswerEmpty(checkboxField, [])).toBe(true);
    expect(isAnswerEmpty(checkboxField, ['unknown'])).toBe(true);
    expect(isAnswerEmpty(checkboxField, ['unknown', 'bad'])).toBe(false);
    expect(isAnswerEmpty(checkboxField, ['good'])).toBe(false);
    expect(isAnswerEmpty(checkboxField, 'good')).toBe(true);
  });

  it('a field without options treats every radio/checkbox value as unknown', () => {
    expect(isAnswerEmpty({ ...radioField, options: undefined }, 'good')).toBe(
      true,
    );
    expect(
      isAnswerEmpty({ ...checkboxField, options: undefined }, ['good']),
    ).toBe(true);
  });
});

describe('readonlyVisibleFields', () => {
  const question: Question = {
    id: 'q',
    titleKey: 'x.q',
    fields: [radioField, checkboxField, textField, listField],
  };
  const noComments = () => false;

  it('returns nothing when every field is empty', () => {
    expect(readonlyVisibleFields(question, {}, noComments)).toEqual([]);
  });

  it('returns only the answered fields, in definition order', () => {
    const visible = readonlyVisibleFields(
      question,
      { l: [entry], r: 'good', c: [], t: ' ' },
      noComments,
    );
    expect(visible.map((f) => f.id)).toEqual(['r', 'l']);
  });

  it('keeps an empty field that has comments', () => {
    const visible = readonlyVisibleFields(
      question,
      { l: [entry] },
      (fieldId) => fieldId === 't',
    );
    expect(visible.map((f) => f.id)).toEqual(['t', 'l']);
  });
});

describe('GENERIC_LABEL_KEYS', () => {
  it('holds exactly the three input-caption labels, each present in en.json', () => {
    expect([...GENERIC_LABEL_KEYS].sort()).toEqual([
      'questions.fields.anythingToAdd',
      'questions.fields.details',
      'questions.fields.entries',
    ]);
    for (const key of GENERIC_LABEL_KEYS) {
      expect(typeof messageAt(en, key), key).toBe('string');
    }
  });

  it('are all still used as a field label by the current question set', () => {
    const labelKeys = new Set(
      ANKETA_TEMPLATES.flatMap((templateKey) =>
        (['employee', 'manager'] as const).flatMap((side) =>
          getQuestionsForSide(side, CURRENT_ANKETA_FORM_VERSION, templateKey),
        ),
      ).flatMap((question) =>
        question.fields.flatMap((field) => field.labelKey ?? []),
      ),
    );
    for (const key of GENERIC_LABEL_KEYS) {
      expect(labelKeys.has(key), key).toBe(true);
    }
  });

  // The collapsed view shows a choice answer under its field label, so that
  // label must be a real sub-prompt, never a generic caption it would hide.
  it('are never used by a radio or checkbox field', () => {
    const choiceFieldsWithGenericLabel = allFields()
      .filter(
        (field) =>
          (field.type === 'radio' || field.type === 'checkboxes') &&
          field.labelKey !== undefined &&
          GENERIC_LABEL_KEYS.has(field.labelKey),
      )
      .map((field) => field.id);
    expect(choiceFieldsWithGenericLabel).toEqual([]);
  });
});

describe('selectedOptions', () => {
  const values = (field: QuestionField, value: unknown) =>
    selectedOptions(field, value as never).map((option) => option.value);

  it('radio: the chosen option, or nothing for an absent or unknown value', () => {
    expect(values(radioField, 'bad')).toEqual(['bad']);
    expect(values(radioField, undefined)).toEqual([]);
    expect(values(radioField, null)).toEqual([]);
    expect(values(radioField, 'unknown')).toEqual([]);
    expect(values(radioField, ['bad'])).toEqual([]);
  });

  it('checkboxes: the chosen options in option order, skipping unknown values', () => {
    expect(values(checkboxField, ['bad', 'unknown', 'good'])).toEqual([
      'good',
      'bad',
    ]);
    expect(values(checkboxField, ['unknown'])).toEqual([]);
    expect(values(checkboxField, [])).toEqual([]);
    expect(values(checkboxField, 'good')).toEqual([]);
    expect(values(checkboxField, [entry])).toEqual([]);
  });

  it('returns nothing for text and list fields', () => {
    expect(values({ ...textField, options }, 'good')).toEqual([]);
    expect(values({ ...listField, options }, ['good'])).toEqual([]);
  });

  it('returns the option objects themselves, labels included', () => {
    expect(selectedOptions(radioField, 'good')).toEqual([options[0]]);
  });
});
