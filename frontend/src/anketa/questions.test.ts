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
  // falling back to 'regular') — the current implementation guards against it with an
  // explicit hasOwnProperty check, but this stays as a documented guard against a future
  // refactor dropping that guard.
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

  describe("the 'onboarding' template", () => {
    function questionIds(
      side: 'employee' | 'manager',
      templateKey: TemplateKey,
    ) {
      return getQuestionsForSide(
        side,
        CURRENT_ANKETA_FORM_VERSION,
        templateKey,
      ).map((q) => q.id);
    }

    it('replaces feelings/workload/growth/friction with workingAgreement/workStyle/freshEyesAudit on the employee side', () => {
      expect(questionIds('employee', 'onboarding')).toEqual([
        'mood',
        'workingAgreement',
        'workStyle',
        'freshEyesAudit',
        'achievements',
        'discuss',
      ]);
    });

    it('keeps mood/achievements/discuss identical to the regular template', () => {
      const regular = getQuestionsForSide(
        'employee',
        CURRENT_ANKETA_FORM_VERSION,
        'regular',
      );
      const onboarding = getQuestionsForSide(
        'employee',
        CURRENT_ANKETA_FORM_VERSION,
        'onboarding',
      );

      for (const id of ['mood', 'achievements', 'discuss']) {
        expect(onboarding.find((q) => q.id === id)).toEqual(
          regular.find((q) => q.id === id),
        );
      }
    });

    it('keeps periodSummary/feedback/support and adds readinessCheck on the manager side, dropping employeeAchievements/managerDiscuss', () => {
      expect(questionIds('manager', 'onboarding')).toEqual([
        'periodSummary',
        'feedback',
        'support',
        'readinessCheck',
      ]);
    });

    it('keeps periodSummary/feedback/support identical to the regular template', () => {
      const regular = getQuestionsForSide(
        'manager',
        CURRENT_ANKETA_FORM_VERSION,
        'regular',
      );
      const onboarding = getQuestionsForSide(
        'manager',
        CURRENT_ANKETA_FORM_VERSION,
        'onboarding',
      );

      for (const id of ['periodSummary', 'feedback', 'support']) {
        expect(onboarding.find((q) => q.id === id)).toEqual(
          regular.find((q) => q.id === id),
        );
      }
    });

    it('gives workStyle a feedback-channel radio plus two free-text fields', () => {
      const workStyle = getQuestionsForSide(
        'employee',
        CURRENT_ANKETA_FORM_VERSION,
        'onboarding',
      ).find((q) => q.id === 'workStyle');

      expect(workStyle?.fields.map((f) => f.id)).toEqual([
        'feedbackChannel',
        'focusTimeNeeds',
        'stressSignals',
      ]);
      expect(
        workStyle?.fields.find((f) => f.id === 'feedbackChannel')?.options,
      ).toEqual([
        { value: 'chat', labelKey: 'questions.options.feedbackChannel.chat' },
        {
          value: 'written',
          labelKey: 'questions.options.feedbackChannel.written',
        },
        {
          value: 'face_to_face',
          labelKey: 'questions.options.feedbackChannel.faceToFace',
        },
      ]);
    });

    it('gives readinessCheck a yes/partial/no radio plus a notes field', () => {
      const readinessCheck = getQuestionsForSide(
        'manager',
        CURRENT_ANKETA_FORM_VERSION,
        'onboarding',
      ).find((q) => q.id === 'readinessCheck');

      expect(readinessCheck?.fields.map((f) => f.id)).toEqual([
        'readinessLevel',
        'readinessNotes',
      ]);
      expect(
        readinessCheck?.fields.find((f) => f.id === 'readinessLevel')?.options,
      ).toEqual([
        { value: 'yes', labelKey: 'questions.options.readinessLevel.yes' },
        {
          value: 'partial',
          labelKey: 'questions.options.readinessLevel.partial',
        },
        { value: 'no', labelKey: 'questions.options.readinessLevel.no' },
      ]);
    });

    it("does not vary by formVersion (no 'feelings'-style field exists)", () => {
      expect(getQuestionsForSide('employee', 1, 'onboarding')).toEqual(
        getQuestionsForSide('employee', 2, 'onboarding'),
      );
    });
  });
});
