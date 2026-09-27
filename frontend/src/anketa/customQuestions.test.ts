import { describe, expect, it } from 'vitest';
import { questionSetFromDefinition } from './customQuestions';
import { CURRENT_ANKETA_FORM_VERSION, getQuestionsForSide } from './questions';

const definition = {
  schemaVersion: 1,
  employee: [
    { kind: 'builtin', questionId: 'mood' },
    {
      kind: 'custom',
      id: 'c_aaaaaaaaaa',
      title: 'How is the project going?',
      field: { id: 'c_bbbbbbbbbb', type: 'text' },
    },
  ],
  manager: [{ kind: 'builtin', questionId: 'feedback' }],
};

describe('questionSetFromDefinition', () => {
  it("renders both sides of a valid definition, reusing the built-ins' own questions", () => {
    const set = questionSetFromDefinition(
      definition,
      CURRENT_ANKETA_FORM_VERSION,
    );

    const regularEmployee = getQuestionsForSide(
      'employee',
      CURRENT_ANKETA_FORM_VERSION,
      'regular',
    );
    expect(set?.employee.map((q) => q.id)).toEqual(['mood', 'c_aaaaaaaaaa']);
    expect(set?.employee[0]).toEqual(
      regularEmployee.find((q) => q.id === 'mood'),
    );
    expect(set?.employee[1].titleText).toBe('How is the project going?');
    expect(set?.manager.map((q) => q.id)).toEqual(['feedback']);
  });

  it('is null for a definition that does not validate', () => {
    expect(
      questionSetFromDefinition(
        { ...definition, schemaVersion: 2 },
        CURRENT_ANKETA_FORM_VERSION,
      ),
    ).toBeNull();
    expect(
      questionSetFromDefinition(null, CURRENT_ANKETA_FORM_VERSION),
    ).toBeNull();
    expect(
      questionSetFromDefinition(
        { ...definition, manager: [{ kind: 'builtin', questionId: 'nope' }] },
        CURRENT_ANKETA_FORM_VERSION,
      ),
    ).toBeNull();
  });
});
