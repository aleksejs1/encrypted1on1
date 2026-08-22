/**
 * The employee/manager question set, described once as data — per the
 * spec's "Технические требования": not duplicated across templates,
 * validation, and translations, and explicitly not a form-builder (the
 * questions are the same for the whole app, just not copy-pasted through
 * the codebase).
 *
 * Display text lives in the i18n locale files (Phase 6h), not here — every
 * `*Key` field below is a translation key (`questions.employee.*`/
 * `questions.fields.*`/`questions.options.*` in `src/i18n/locales/*.json`),
 * resolved through svelte-i18n's `$_()` at render time (`AnswerField.svelte`,
 * `Anketa.svelte`). Field/option ids are stable data identifiers (used as
 * `Answers` keys and radio/checkbox values) — those never change with locale.
 */

type FieldType = 'radio' | 'checkboxes' | 'text' | 'list';

export interface FieldOption {
  value: string;
  labelKey: string;
}

export interface QuestionField {
  /** Stable key within a side's answer data object. */
  id: string;
  type: FieldType;
  labelKey: string;
  options?: FieldOption[];
}

export interface Question {
  id: string;
  titleKey: string;
  fields: QuestionField[];
}

export type Side = 'employee' | 'manager';

/**
 * The shape of a `list`-type field's answer — dated entries with stable
 * client-generated UUIDs, per the spec's "Стабильные идентификаторы
 * записей": the same append-format used for "Достижения"/"Саморазвитие"/
 * "О чём поговорить" in the real spec, not a single text blob, because the
 * anketa is open for editing the whole period and entries get added as
 * things happen rather than all at once before the meeting.
 */
export interface ListEntry {
  id: string;
  date: string;
  text: string;
}

/** The value shape for any single field, matching FieldType. */
export type AnswerValue = string | string[] | ListEntry[] | undefined;

/** A whole side's answers, keyed by QuestionField id. */
export type Answers = Record<string, AnswerValue>;

const employeeQuestions: Question[] = [
  {
    id: 'mood',
    titleKey: 'questions.employee.mood.title',
    fields: [
      {
        id: 'moodNow',
        type: 'radio',
        labelKey: 'questions.fields.moodNow',
        options: [
          { value: 'bad', labelKey: 'questions.options.moodNow.bad' },
          { value: 'neutral', labelKey: 'questions.options.moodNow.neutral' },
          { value: 'good', labelKey: 'questions.options.moodNow.good' },
        ],
      },
      {
        id: 'moodTrend',
        type: 'radio',
        labelKey: 'questions.fields.moodTrend',
        options: [
          { value: 'worse', labelKey: 'questions.options.trend.worse' },
          { value: 'same', labelKey: 'questions.options.trend.same' },
          { value: 'better', labelKey: 'questions.options.trend.better' },
        ],
      },
      {
        id: 'moodNotes',
        type: 'text',
        labelKey: 'questions.fields.anythingToAdd',
      },
    ],
  },
  {
    id: 'feelings',
    titleKey: 'questions.employee.feelings.title',
    fields: [
      {
        id: 'feelingsList',
        type: 'checkboxes',
        labelKey: 'questions.fields.feelingsList',
        options: [
          {
            value: 'excited',
            labelKey: 'questions.options.feelingsList.excited',
          },
          {
            value: 'anxious',
            labelKey: 'questions.options.feelingsList.anxious',
          },
          {
            value: 'confident',
            labelKey: 'questions.options.feelingsList.confident',
          },
          {
            value: 'overwhelmed',
            labelKey: 'questions.options.feelingsList.overwhelmed',
          },
          {
            value: 'motivated',
            labelKey: 'questions.options.feelingsList.motivated',
          },
          {
            value: 'frustrated',
            labelKey: 'questions.options.feelingsList.frustrated',
          },
        ],
      },
      {
        id: 'feelingsNotes',
        type: 'text',
        labelKey: 'questions.fields.anythingToAdd',
      },
    ],
  },
  {
    id: 'workload',
    titleKey: 'questions.employee.workload.title',
    fields: [
      {
        id: 'workloadNow',
        type: 'radio',
        labelKey: 'questions.fields.workloadNow',
        options: [
          {
            value: 'too_much',
            labelKey: 'questions.options.workloadNow.tooMuch',
          },
          {
            value: 'just_right',
            labelKey: 'questions.options.workloadNow.justRight',
          },
          {
            value: 'too_little',
            labelKey: 'questions.options.workloadNow.tooLittle',
          },
        ],
      },
      {
        id: 'workloadTrend',
        type: 'radio',
        labelKey: 'questions.fields.workloadTrend',
        options: [
          { value: 'more', labelKey: 'questions.options.workloadTrend.more' },
          { value: 'same', labelKey: 'questions.options.trend.same' },
          { value: 'less', labelKey: 'questions.options.workloadTrend.less' },
        ],
      },
      {
        id: 'workloadNotes',
        type: 'text',
        labelKey: 'questions.fields.anythingToAdd',
      },
    ],
  },
  {
    id: 'growth',
    titleKey: 'questions.employee.growth.title',
    fields: [
      {
        id: 'growthEntries',
        type: 'list',
        labelKey: 'questions.fields.entries',
      },
    ],
  },
  {
    id: 'friction',
    titleKey: 'questions.employee.friction.title',
    fields: [
      {
        id: 'frictionNotes',
        type: 'text',
        labelKey: 'questions.fields.details',
      },
    ],
  },
  {
    id: 'achievements',
    titleKey: 'questions.employee.achievements.title',
    fields: [
      {
        id: 'achievementEntries',
        type: 'list',
        labelKey: 'questions.fields.entries',
      },
    ],
  },
  {
    id: 'discuss',
    titleKey: 'questions.employee.discuss.title',
    fields: [
      {
        id: 'discussEntries',
        type: 'list',
        labelKey: 'questions.fields.entries',
      },
    ],
  },
];

const managerQuestions: Question[] = [
  {
    id: 'periodSummary',
    titleKey: 'questions.manager.periodSummary.title',
    fields: [
      {
        id: 'periodSummaryNotes',
        type: 'text',
        labelKey: 'questions.fields.details',
      },
    ],
  },
  {
    id: 'feedback',
    titleKey: 'questions.manager.feedback.title',
    fields: [
      {
        id: 'feedbackNotes',
        type: 'text',
        labelKey: 'questions.fields.details',
      },
    ],
  },
  {
    id: 'support',
    titleKey: 'questions.manager.support.title',
    fields: [
      {
        id: 'supportNotes',
        type: 'text',
        labelKey: 'questions.fields.details',
      },
    ],
  },
  {
    id: 'employeeAchievements',
    titleKey: 'questions.manager.employeeAchievements.title',
    fields: [
      {
        id: 'employeeAchievementEntries',
        type: 'list',
        labelKey: 'questions.fields.entries',
      },
    ],
  },
  {
    id: 'managerDiscuss',
    titleKey: 'questions.manager.managerDiscuss.title',
    fields: [
      {
        id: 'managerDiscussEntries',
        type: 'list',
        labelKey: 'questions.fields.entries',
      },
    ],
  },
];

export const QUESTIONS_BY_SIDE: Record<Side, Question[]> = {
  employee: employeeQuestions,
  manager: managerQuestions,
};
