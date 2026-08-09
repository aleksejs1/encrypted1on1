/**
 * The employee/manager question set, described once as data — per the
 * spec's "Технические требования": not duplicated across templates,
 * validation, and translations, and explicitly not a form-builder (the
 * questions are the same for the whole app, just not copy-pasted through
 * the codebase).
 */

export type FieldType = 'radio' | 'checkboxes' | 'text' | 'list';

export interface FieldOption {
  value: string;
  label: string;
}

export interface QuestionField {
  /** Stable key within a side's answer data object. */
  id: string;
  type: FieldType;
  label: string;
  options?: FieldOption[];
}

export interface Question {
  id: string;
  title: string;
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
    title: 'Mood',
    fields: [
      {
        id: 'moodNow',
        type: 'radio',
        label: 'How are you feeling?',
        options: [
          { value: 'bad', label: 'Bad' },
          { value: 'neutral', label: 'Neutral' },
          { value: 'good', label: 'Good' },
        ],
      },
      {
        id: 'moodTrend',
        type: 'radio',
        label: 'Compared to last time?',
        options: [
          { value: 'worse', label: 'Worse' },
          { value: 'same', label: 'About the same' },
          { value: 'better', label: 'Better' },
        ],
      },
      { id: 'moodNotes', type: 'text', label: 'Anything to add?' },
    ],
  },
  {
    id: 'feelings',
    title: 'Feelings',
    fields: [
      {
        id: 'feelingsList',
        type: 'checkboxes',
        label: 'Which of these apply?',
        options: [
          { value: 'excited', label: 'Excited' },
          { value: 'anxious', label: 'Anxious' },
          { value: 'confident', label: 'Confident' },
          { value: 'overwhelmed', label: 'Overwhelmed' },
          { value: 'motivated', label: 'Motivated' },
          { value: 'frustrated', label: 'Frustrated' },
        ],
      },
      { id: 'feelingsNotes', type: 'text', label: 'Anything to add?' },
    ],
  },
  {
    id: 'workload',
    title: 'Workload',
    fields: [
      {
        id: 'workloadNow',
        type: 'radio',
        label: 'How much work do you have?',
        options: [
          { value: 'too_much', label: 'Too much' },
          { value: 'just_right', label: 'Just right' },
          { value: 'too_little', label: 'Too little' },
        ],
      },
      {
        id: 'workloadTrend',
        type: 'radio',
        label: 'Compared to last time?',
        options: [
          { value: 'more', label: 'More work' },
          { value: 'same', label: 'About the same' },
          { value: 'less', label: 'Less work' },
        ],
      },
      { id: 'workloadNotes', type: 'text', label: 'Anything to add?' },
    ],
  },
  {
    id: 'growth',
    title: 'Growth. What did you learn, discover, take away?',
    fields: [{ id: 'growthEntries', type: 'list', label: 'Entries' }],
  },
  {
    id: 'friction',
    title: "What's harder in my work than it should be",
    fields: [{ id: 'frictionNotes', type: 'text', label: 'Details' }],
  },
  {
    id: 'achievements',
    title: 'Achievements',
    fields: [{ id: 'achievementEntries', type: 'list', label: 'Entries' }],
  },
  {
    id: 'discuss',
    title: 'What else to discuss',
    fields: [{ id: 'discussEntries', type: 'list', label: 'Entries' }],
  },
];

const managerQuestions: Question[] = [
  {
    id: 'periodSummary',
    title: 'How did the period go since the last meeting',
    fields: [{ id: 'periodSummaryNotes', type: 'text', label: 'Details' }],
  },
  {
    id: 'feedback',
    title: "Feedback: what's going well, what could improve",
    fields: [{ id: 'feedbackNotes', type: 'text', label: 'Details' }],
  },
  {
    id: 'support',
    title: 'How can I help / what gets in the way',
    fields: [{ id: 'supportNotes', type: 'text', label: 'Details' }],
  },
  {
    id: 'employeeAchievements',
    title: 'Achievements worth recognizing',
    fields: [{ id: 'employeeAchievementEntries', type: 'list', label: 'Entries' }],
  },
  {
    id: 'managerDiscuss',
    title: 'What else to discuss',
    fields: [{ id: 'managerDiscussEntries', type: 'list', label: 'Entries' }],
  },
];

export const QUESTIONS_BY_SIDE: Record<Side, Question[]> = {
  employee: employeeQuestions,
  manager: managerQuestions,
};
