/**
 * What the collapsed read-only view of an anketa side shows — see GitHub
 * issue #131 (the design) / #134. "Collapsed" means the counterpart's side,
 * my own published side while not editing, or either side once archived;
 * edit mode always renders every field.
 */
import type {
  AnswerValue,
  Answers,
  Question,
  QuestionField,
} from './questions';

/**
 * Captions for an input ("add entries here", "anything else?"), not real
 * sub-prompts — once there's an answer under them, the question title and the
 * content already say what it is, so the collapsed view drops them.
 */
export const GENERIC_LABEL_KEYS: ReadonlySet<string> = new Set([
  'questions.fields.entries',
  'questions.fields.details',
  'questions.fields.anythingToAdd',
]);

/**
 * Whether `value` counts as unanswered for `field`. A stored radio/checkbox
 * value that isn't one of the field's options would render nothing, so it
 * counts as unanswered too. Markdown-only text (e.g. `**`) counts as answered.
 * Deliberately stricter than drafts.ts's `hasAnyAnswer()`, which only guards
 * against autosaving a blank form over an unreadable draft and so counts any
 * typed value, whitespace included, as content.
 */
export function isAnswerEmpty(
  field: QuestionField,
  value: AnswerValue | null,
): boolean {
  const isOption = (v: unknown) =>
    (field.options ?? []).some((option) => option.value === v);
  switch (field.type) {
    case 'text':
      return typeof value !== 'string' || value.trim() === '';
    case 'list':
      return !Array.isArray(value) || value.length === 0;
    case 'radio':
      return typeof value !== 'string' || !isOption(value);
    case 'checkboxes':
      return !Array.isArray(value) || !value.some(isOption);
  }
}

/**
 * The fields of `question` the collapsed view renders, in definition order:
 * every answered field, plus any unanswered one that already has comments
 * (so a comment on an answer that was later cleared stays reachable).
 */
export function readonlyVisibleFields(
  question: Question,
  answers: Answers,
  hasComments: (fieldId: string) => boolean,
): QuestionField[] {
  return question.fields.filter(
    (field) =>
      !isAnswerEmpty(field, answers[field.id]) || hasComments(field.id),
  );
}
