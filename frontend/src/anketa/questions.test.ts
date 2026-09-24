/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  ANKETA_TEMPLATES,
  CURRENT_ANKETA_FORM_VERSION,
  getQuestionsForSide,
  templateListLabelKey,
  templatePickerKeys,
  type TemplateKey,
} from './questions';
import en from '../i18n/locales/en.json';
import { messageAt } from '../i18n/testUtils';

function feelingsOptionValues(side: 'employee' | 'manager', version: number) {
  const feelings = getQuestionsForSide(side, version, 'regular').find(
    (q) => q.id === 'feelings',
  );
  const field = feelings?.fields.find((f) => f.id === 'feelingsList');

  return field?.options?.map((o) => o.value);
}

function questionIds(side: 'employee' | 'manager', templateKey: TemplateKey) {
  return getQuestionsForSide(
    side,
    CURRENT_ANKETA_FORM_VERSION,
    templateKey,
  ).map((q) => q.id);
}

function questionFor(
  side: 'employee' | 'manager',
  templateKey: TemplateKey,
  id: string,
) {
  return getQuestionsForSide(
    side,
    CURRENT_ANKETA_FORM_VERSION,
    templateKey,
  ).find((q) => q.id === id);
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

  // Only 'regular' has a 'feelings'-style field whose options vary by version.
  it('does not vary any non-regular template by formVersion', () => {
    for (const templateKey of ANKETA_TEMPLATES.filter((k) => k !== 'regular')) {
      for (const side of ['employee', 'manager'] as const) {
        const latest = getQuestionsForSide(
          side,
          CURRENT_ANKETA_FORM_VERSION,
          templateKey,
        );
        for (let v = 1; v < CURRENT_ANKETA_FORM_VERSION; v++) {
          expect(
            getQuestionsForSide(side, v, templateKey),
            `${templateKey}/${side}/v${v}`,
          ).toEqual(latest);
        }
      }
    }
  });

  // Field ids are the keys of a side's Answers object, and comment threads
  // (comments.ts's targetId) are keyed by the bare field id with no side, so a
  // clash — within one side or across the two — would merge answers or threads.
  it('uses unique field ids across both sides of every registered template, at every form version', () => {
    for (const templateKey of ANKETA_TEMPLATES) {
      for (let v = 1; v <= CURRENT_ANKETA_FORM_VERSION; v++) {
        const ids = (['employee', 'manager'] as const).flatMap((side) =>
          getQuestionsForSide(side, v, templateKey).flatMap((q) =>
            q.fields.map((f) => f.id),
          ),
        );
        expect(new Set(ids).size, `${templateKey}/v${v}`).toBe(ids.length);
      }
    }
  });

  // src/i18n/locales.test.ts already checks every locale has the same keys as en.json,
  // so resolving against en.json alone covers all six — this catches a typo'd key in
  // this module, which would otherwise render as the raw key string at runtime.
  it('uses only i18n keys that exist in the locale files, for every registered template and its picker label', () => {
    const resolves = (key: string) => typeof messageAt(en, key) === 'string';

    for (const templateKey of ANKETA_TEMPLATES) {
      const { labelKey, descriptionKey } = templatePickerKeys(templateKey);
      expect(
        [labelKey, descriptionKey].filter((key) => !resolves(key)),
      ).toEqual([]);
      for (const [side, formVersion] of (
        ['employee', 'manager'] as const
      ).flatMap((side) =>
        Array.from(
          { length: CURRENT_ANKETA_FORM_VERSION },
          (_, i) => [side, i + 1] as const,
        ),
      )) {
        for (const question of getQuestionsForSide(
          side,
          formVersion,
          templateKey,
        )) {
          const keys = [
            question.titleKey,
            ...question.fields.flatMap((f) => [
              f.labelKey,
              ...(f.options ?? []).map((o) => o.labelKey),
            ]),
          ];
          expect(keys.filter((key) => !resolves(key))).toEqual([]);
        }
      }
    }
  });

  // The backend validates templateKey against its own hand-kept list; drift either way
  // means a template the picker offers gets rejected, or one the backend accepts
  // silently renders as 'regular'. Reads the PHP source directly rather than adding a
  // shared generated file for a short hand-kept list — unlike src/design/contrast.test.ts's
  // readFileSync, this reaches outside the frontend package, so it needs the whole repo
  // checked out (CI does), and TEMPLATE_KEYS written as a literal array of single-quoted
  // strings.
  it("matches the backend's Anketa::TEMPLATE_KEYS", () => {
    const php = readFileSync(
      fileURLToPath(
        new URL('../../../backend/src/Entity/Anketa.php', import.meta.url),
      ),
      'utf-8',
    );
    const list = /const\s+(?:array\s+)?TEMPLATE_KEYS\s*=\s*\[([^\]]*)\]/.exec(
      php,
    )?.[1];

    expect(
      list,
      'Anketa::TEMPLATE_KEYS not found as a literal array in Anketa.php — update this regex',
    ).toBeDefined();
    // Sorted: membership is what matters (the backend only uses in_array), and
    // ANKETA_TEMPLATES' own order is the picker's display order.
    expect(
      [...(list ?? '').matchAll(/'([^']+)'/g)].map((m) => m[1]).sort(),
    ).toEqual([...ANKETA_TEMPLATES].sort());
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

  describe('templateListLabelKey', () => {
    it("returns no list label for 'regular'", () => {
      expect(templateListLabelKey('regular')).toBeNull();
    });

    it("returns the picker's own label key for every non-default template", () => {
      for (const templateKey of ANKETA_TEMPLATES.filter(
        (k) => k !== 'regular',
      )) {
        expect(templateListLabelKey(templateKey), templateKey).toBe(
          templatePickerKeys(templateKey).labelKey,
        );
      }
    });

    it('returns no list label for an unrecognized or prototype-shaped templateKey, same as regular', () => {
      for (const key of ['made-up-template', 'constructor', 'toString']) {
        expect(templateListLabelKey(key as TemplateKey), key).toBeNull();
      }
    });
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
  });

  describe("the 'career_growth' template", () => {
    const question = (side: 'employee' | 'manager', id: string) =>
      questionFor(side, 'career_growth', id);

    it('replaces the period-status fields with energyRetrospective/trajectory/developmentPlan on the employee side', () => {
      expect(questionIds('employee', 'career_growth')).toEqual([
        'mood',
        'energyRetrospective',
        'trajectory',
        'developmentPlan',
        'discuss',
      ]);
    });

    it('keeps feedback/managerDiscuss and adds sponsorshipOffer on the manager side', () => {
      expect(questionIds('manager', 'career_growth')).toEqual([
        'feedback',
        'sponsorshipOffer',
        'managerDiscuss',
      ]);
    });

    it('keeps mood/discuss and feedback/managerDiscuss identical to the regular template', () => {
      for (const [side, ids] of [
        ['employee', ['mood', 'discuss']],
        ['manager', ['feedback', 'managerDiscuss']],
      ] as const) {
        const regular = getQuestionsForSide(
          side,
          CURRENT_ANKETA_FORM_VERSION,
          'regular',
        );
        for (const id of ids) {
          expect(question(side, id)).toEqual(regular.find((q) => q.id === id));
        }
      }
    });

    it('gives energyRetrospective two free-text fields', () => {
      expect(
        question('employee', 'energyRetrospective')?.fields.map((f) => [
          f.id,
          f.type,
        ]),
      ).toEqual([
        ['energizingWork', 'text'],
        ['drainingWork', 'text'],
      ]);
    });

    it('gives trajectory a three-way (not binary IC-vs-manager) radio plus a capability-gap field', () => {
      const trajectory = question('employee', 'trajectory');

      expect(trajectory?.fields.map((f) => f.id)).toEqual([
        'trajectoryDirection',
        'capabilityGap',
      ]);
      expect(
        trajectory?.fields.find((f) => f.id === 'trajectoryDirection')?.options,
      ).toEqual([
        {
          value: 'ic_depth',
          labelKey: 'questions.options.trajectoryDirection.icDepth',
        },
        {
          value: 'people_leadership',
          labelKey: 'questions.options.trajectoryDirection.peopleLeadership',
        },
        {
          value: 'undecided',
          labelKey: 'questions.options.trajectoryDirection.undecided',
        },
      ]);
    });

    it('gives developmentPlan a single-goal text field plus a list of steps', () => {
      expect(
        question('employee', 'developmentPlan')?.fields.map((f) => [
          f.id,
          f.type,
        ]),
      ).toEqual([
        ['developmentGoal', 'text'],
        ['developmentSteps', 'list'],
      ]);
    });

    it('gives sponsorshipOffer a stretch-assignment and a backing text field', () => {
      expect(
        question('manager', 'sponsorshipOffer')?.fields.map((f) => [
          f.id,
          f.type,
        ]),
      ).toEqual([
        ['stretchAssignment', 'text'],
        ['managerBacking', 'text'],
      ]);
    });
  });

  describe("the 'support_checkin' template", () => {
    const question = (side: 'employee' | 'manager', id: string) =>
      questionFor(side, 'support_checkin', id);

    it('keeps mood, then energyLevel/workload/workloadTriage/boundaries/discuss on the employee side', () => {
      expect(questionIds('employee', 'support_checkin')).toEqual([
        'mood',
        'energyLevel',
        'workload',
        'workloadTriage',
        'boundaries',
        'discuss',
      ]);
    });

    it('gives the manager side commitments/checkInCadence/managerDiscuss', () => {
      expect(questionIds('manager', 'support_checkin')).toEqual([
        'commitments',
        'checkInCadence',
        'managerDiscuss',
      ]);
    });

    it('keeps mood/workload/discuss and managerDiscuss identical to the regular template', () => {
      for (const [side, id] of [
        ['employee', 'mood'],
        ['employee', 'workload'],
        ['employee', 'discuss'],
        ['manager', 'managerDiscuss'],
      ] as const) {
        expect(question(side, id)).toEqual(questionFor(side, 'regular', id));
      }
    });

    it('gives energyLevel a plain low/manageable/good radio (not a numeric score) plus a free-text field', () => {
      const energyLevel = question('employee', 'energyLevel');

      expect(energyLevel?.fields.map((f) => [f.id, f.type])).toEqual([
        ['energyLevelNow', 'radio'],
        ['energyDrivers', 'text'],
      ]);
      expect(
        energyLevel?.fields.find((f) => f.id === 'energyLevelNow')?.options,
      ).toEqual([
        { value: 'low', labelKey: 'questions.options.energyLevelNow.low' },
        {
          value: 'manageable',
          labelKey: 'questions.options.energyLevelNow.manageable',
        },
        { value: 'good', labelKey: 'questions.options.energyLevelNow.good' },
      ]);
    });

    it('gives workloadTriage a list field rather than a single blank prompt', () => {
      expect(
        question('employee', 'workloadTriage')?.fields.map((f) => [
          f.id,
          f.type,
        ]),
      ).toEqual([['triageEntries', 'list']]);
    });

    it('gives boundaries a single free-text field', () => {
      expect(
        question('employee', 'boundaries')?.fields.map((f) => [f.id, f.type]),
      ).toEqual([['boundariesNotes', 'text']]);
    });

    it('gives commitments a list field and checkInCadence a free-text field', () => {
      expect(
        question('manager', 'commitments')?.fields.map((f) => [f.id, f.type]),
      ).toEqual([['commitmentEntries', 'list']]);
      expect(
        question('manager', 'checkInCadence')?.fields.map((f) => [
          f.id,
          f.type,
        ]),
      ).toEqual([['checkInCadenceNotes', 'text']]);
    });
  });
});
