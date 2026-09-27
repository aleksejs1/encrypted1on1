/**
 * A custom anketa's questions (GitHub issue #144, #133 §7.3), from the company
 * template version it was created on.
 */
import { questionsFromDefinition, type Question, type Side } from './questions';
import {
  validateTemplateDefinition,
  type TemplateDefinition,
} from './templateDefinition';

export type QuestionSet = Record<Side, Question[]>;

/**
 * Both sides' questions from a version's definition as the server sent it, or
 * null if it doesn't validate. The server validated it when it was saved; this
 * checks again, cheaply, before anything renders it — a definition from a newer
 * app version, for one, may use a built-in question this bundle doesn't know.
 */
export function questionSetFromDefinition(
  definition: unknown,
  formVersion: number,
): QuestionSet | null {
  if (validateTemplateDefinition(definition).length > 0) return null;
  const valid = definition as TemplateDefinition;
  try {
    return {
      employee: questionsFromDefinition(valid, 'employee', formVersion),
      manager: questionsFromDefinition(valid, 'manager', formVersion),
    };
  } catch {
    return null;
  }
}
