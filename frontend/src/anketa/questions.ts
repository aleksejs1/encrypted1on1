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
 * The question set an anketa is created against — bumped whenever the set changes in a
 * way that affects existing answers (e.g. adding checkbox options), so an anketa's
 * fields stay stable for its whole life instead of retroactively changing shape
 * underneath already-published answers. Must match the backend's
 * `Anketa::CURRENT_FORM_VERSION` (backend/src/Entity/Anketa.php), which stamps every
 * newly created anketa with this value; `getQuestionsForSide()` below is what actually
 * varies by version. Not user- or company-facing yet — a real per-company/per-user
 * custom form would likely replace this whole module with a stored form definition,
 * but until that's a discussed product decision this is the simplest way to let the one
 * global form change over time without breaking anketas created under an older one.
 */
export const CURRENT_ANKETA_FORM_VERSION = 2;

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

/** Form version 1's "feelings" checkboxes — every anketa created before version 2 shipped. */
const feelingsOptionsV1: FieldOption[] = [
  { value: 'excited', labelKey: 'questions.options.feelingsList.excited' },
  { value: 'anxious', labelKey: 'questions.options.feelingsList.anxious' },
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
];

/** Form version 2 adds six more options — every anketa created from version 2 on. */
const feelingsOptionsV2: FieldOption[] = [
  ...feelingsOptionsV1,
  { value: 'grateful', labelKey: 'questions.options.feelingsList.grateful' },
  { value: 'proud', labelKey: 'questions.options.feelingsList.proud' },
  { value: 'calm', labelKey: 'questions.options.feelingsList.calm' },
  { value: 'stressed', labelKey: 'questions.options.feelingsList.stressed' },
  { value: 'bored', labelKey: 'questions.options.feelingsList.bored' },
  { value: 'lonely', labelKey: 'questions.options.feelingsList.lonely' },
];

const moodQuestion: Question = {
  id: 'mood',
  titleKey: 'questions.employee.mood.title',
  fields: [
    {
      id: 'moodNow',
      type: 'radio',
      labelKey: 'questions.fields.moodNow',
      options: [
        { value: 'bad', labelKey: 'questions.options.moodNow.bad' },
        {
          value: 'neutral',
          labelKey: 'questions.options.moodNow.neutral',
        },
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
};

function feelingsQuestion(formVersion: number): Question {
  return {
    id: 'feelings',
    titleKey: 'questions.employee.feelings.title',
    fields: [
      {
        id: 'feelingsList',
        type: 'checkboxes',
        labelKey: 'questions.fields.feelingsList',
        options: formVersion >= 2 ? feelingsOptionsV2 : feelingsOptionsV1,
      },
      {
        id: 'feelingsNotes',
        type: 'text',
        labelKey: 'questions.fields.anythingToAdd',
      },
    ],
  };
}

const workloadQuestion: Question = {
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
};

const growthQuestion: Question = {
  id: 'growth',
  titleKey: 'questions.employee.growth.title',
  fields: [
    {
      id: 'growthEntries',
      type: 'list',
      labelKey: 'questions.fields.entries',
    },
  ],
};

const frictionQuestion: Question = {
  id: 'friction',
  titleKey: 'questions.employee.friction.title',
  fields: [
    {
      id: 'frictionNotes',
      type: 'text',
      labelKey: 'questions.fields.details',
    },
  ],
};

const achievementsQuestion: Question = {
  id: 'achievements',
  titleKey: 'questions.employee.achievements.title',
  fields: [
    {
      id: 'achievementEntries',
      type: 'list',
      labelKey: 'questions.fields.entries',
    },
  ],
};

const discussQuestion: Question = {
  id: 'discuss',
  titleKey: 'questions.employee.discuss.title',
  fields: [
    {
      id: 'discussEntries',
      type: 'list',
      labelKey: 'questions.fields.entries',
    },
  ],
};

function employeeQuestions(formVersion: number): Question[] {
  return [
    moodQuestion,
    feelingsQuestion(formVersion),
    workloadQuestion,
    growthQuestion,
    frictionQuestion,
    achievementsQuestion,
    discussQuestion,
  ];
}

const periodSummaryQuestion: Question = {
  id: 'periodSummary',
  titleKey: 'questions.manager.periodSummary.title',
  fields: [
    {
      id: 'periodSummaryNotes',
      type: 'text',
      labelKey: 'questions.fields.details',
    },
  ],
};

const feedbackQuestion: Question = {
  id: 'feedback',
  titleKey: 'questions.manager.feedback.title',
  fields: [
    {
      id: 'feedbackNotes',
      type: 'text',
      labelKey: 'questions.fields.details',
    },
  ],
};

const supportQuestion: Question = {
  id: 'support',
  titleKey: 'questions.manager.support.title',
  fields: [
    {
      id: 'supportNotes',
      type: 'text',
      labelKey: 'questions.fields.details',
    },
  ],
};

const employeeAchievementsQuestion: Question = {
  id: 'employeeAchievements',
  titleKey: 'questions.manager.employeeAchievements.title',
  fields: [
    {
      id: 'employeeAchievementEntries',
      type: 'list',
      labelKey: 'questions.fields.entries',
    },
  ],
};

const managerDiscussQuestion: Question = {
  id: 'managerDiscuss',
  titleKey: 'questions.manager.managerDiscuss.title',
  fields: [
    {
      id: 'managerDiscussEntries',
      type: 'list',
      labelKey: 'questions.fields.entries',
    },
  ],
};

const managerQuestions: Question[] = [
  periodSummaryQuestion,
  feedbackQuestion,
  supportQuestion,
  employeeAchievementsQuestion,
  managerDiscussQuestion,
];

/**
 * Onboarding ("First 1:1") template — sourced from the landing playbook's three agenda
 * blocks (Working Agreement & Safety / Work Style & "Personal User Manual" / Fresh-Eyes
 * Audit & Early Unblocking), see private/anketa-meeting-templates-proposal.md §8.3 (not
 * tracked in git) and GitHub issue #104. `mood` stays universal (the safety pulse applies
 * to every meeting) and `achievements`/`discuss` are kept as-is; `feelings`/`workload`/
 * `growth`/`friction` are replaced with fields specific to a first meeting.
 */
const workingAgreementQuestion: Question = {
  id: 'workingAgreement',
  titleKey: 'questions.employee.workingAgreement.title',
  fields: [
    {
      id: 'workingAgreementNotes',
      type: 'text',
      labelKey: 'questions.fields.details',
    },
  ],
};

const workStyleQuestion: Question = {
  id: 'workStyle',
  titleKey: 'questions.employee.workStyle.title',
  fields: [
    {
      id: 'feedbackChannel',
      type: 'radio',
      labelKey: 'questions.fields.feedbackChannel',
      options: [
        { value: 'chat', labelKey: 'questions.options.feedbackChannel.chat' },
        {
          value: 'written',
          labelKey: 'questions.options.feedbackChannel.written',
        },
        {
          value: 'face_to_face',
          labelKey: 'questions.options.feedbackChannel.faceToFace',
        },
      ],
    },
    {
      id: 'focusTimeNeeds',
      type: 'text',
      labelKey: 'questions.fields.focusTimeNeeds',
    },
    {
      id: 'stressSignals',
      type: 'text',
      labelKey: 'questions.fields.stressSignals',
    },
  ],
};

const freshEyesAuditQuestion: Question = {
  id: 'freshEyesAudit',
  titleKey: 'questions.employee.freshEyesAudit.title',
  fields: [
    {
      id: 'freshEyesEntries',
      type: 'list',
      labelKey: 'questions.fields.entries',
    },
  ],
};

/** Takes the same `(formVersion)` shape as `employeeQuestions()` for a uniform registry
 * call signature, even though nothing here varies by version yet (this template has no
 * `feelings`-style field at all). */
function onboardingEmployeeQuestions(_formVersion: number): Question[] {
  return [
    moodQuestion,
    workingAgreementQuestion,
    workStyleQuestion,
    freshEyesAuditQuestion,
    achievementsQuestion,
    discussQuestion,
  ];
}

const readinessCheckQuestion: Question = {
  id: 'readinessCheck',
  titleKey: 'questions.manager.readinessCheck.title',
  fields: [
    {
      id: 'readinessLevel',
      type: 'radio',
      labelKey: 'questions.fields.readinessLevel',
      options: [
        { value: 'yes', labelKey: 'questions.options.readinessLevel.yes' },
        {
          value: 'partial',
          labelKey: 'questions.options.readinessLevel.partial',
        },
        { value: 'no', labelKey: 'questions.options.readinessLevel.no' },
      ],
    },
    {
      id: 'readinessNotes',
      type: 'text',
      labelKey: 'questions.fields.details',
    },
  ],
};

const onboardingManagerQuestions: Question[] = [
  periodSummaryQuestion,
  feedbackQuestion,
  supportQuestion,
  readinessCheckQuestion,
];

/**
 * Career Growth template (a career conversation, e.g. once a quarter — its next cycle
 * falls back to 'regular', see Anketa::NEXT_CYCLE_TEMPLATE_KEY) — sourced from the
 * landing playbook's four agenda blocks (Energy Retrospective & Professional Pride /
 * Trajectory & Role Archetypes / Stretch Projects & Manager Sponsorship / 90-Day
 * Individual Development Plan), see
 * private/anketa-meeting-templates-proposal.md §8.3 (not tracked in git) and GitHub issue
 * #105. `mood` stays universal and `discuss`/`managerDiscuss` keep a place for anything
 * else on either side's agenda; the regular check-in's period-status fields are replaced
 * with a longer-horizon career conversation.
 */
const energyRetrospectiveQuestion: Question = {
  id: 'energyRetrospective',
  titleKey: 'questions.employee.energyRetrospective.title',
  fields: [
    {
      id: 'energizingWork',
      type: 'text',
      labelKey: 'questions.fields.energizingWork',
    },
    {
      id: 'drainingWork',
      type: 'text',
      labelKey: 'questions.fields.drainingWork',
    },
  ],
};

/** Deliberately three options, not a binary "IC or manager" — the playbook explicitly
 * warns against pushing someone toward management before they've chosen it. */
const trajectoryQuestion: Question = {
  id: 'trajectory',
  titleKey: 'questions.employee.trajectory.title',
  fields: [
    {
      id: 'trajectoryDirection',
      type: 'radio',
      labelKey: 'questions.fields.trajectoryDirection',
      options: [
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
      ],
    },
    {
      id: 'capabilityGap',
      type: 'text',
      labelKey: 'questions.fields.capabilityGap',
    },
  ],
};

const developmentPlanQuestion: Question = {
  id: 'developmentPlan',
  titleKey: 'questions.employee.developmentPlan.title',
  fields: [
    {
      id: 'developmentGoal',
      type: 'text',
      labelKey: 'questions.fields.developmentGoal',
    },
    {
      id: 'developmentSteps',
      type: 'list',
      labelKey: 'questions.fields.developmentSteps',
    },
  ],
};

/** Same uniform `(formVersion)` signature as `onboardingEmployeeQuestions()` — nothing
 * here varies by version either. */
function careerGrowthEmployeeQuestions(_formVersion: number): Question[] {
  return [
    moodQuestion,
    energyRetrospectiveQuestion,
    trajectoryQuestion,
    developmentPlanQuestion,
    discussQuestion,
  ];
}

const sponsorshipOfferQuestion: Question = {
  id: 'sponsorshipOffer',
  titleKey: 'questions.manager.sponsorshipOffer.title',
  fields: [
    {
      id: 'stretchAssignment',
      type: 'text',
      labelKey: 'questions.fields.stretchAssignment',
    },
    {
      id: 'managerBacking',
      type: 'text',
      labelKey: 'questions.fields.managerBacking',
    },
  ],
};

const careerGrowthManagerQuestions: Question[] = [
  feedbackQuestion,
  sponsorshipOfferQuestion,
  managerDiscussQuestion,
];

/**
 * Support & Workload check-in template — sourced from the landing page's burnout/overwhelm
 * playbook's four agenda blocks (Validation & De-escalation / Triage & Backlog Pruning /
 * Boundaries & Quiet Protocols / Recovery Blueprint), reframed collaboratively rather than
 * clinically: both participants see this question set on their meeting, so nothing here
 * diagnoses or scores anyone. See private/anketa-meeting-templates-proposal.md §5/§8.3
 * (not tracked in git) and GitHub issue #106. Like 'career_growth', its next cycle falls
 * back to 'regular' (see Anketa::NEXT_CYCLE_TEMPLATE_KEY). `mood` stays universal, as in
 * every other template, and `workload` is kept too (it's the "workload check-in"), so
 * AnketaList.svelte's mood/workload sparklines keep getting points through a support
 * phase, the stretch they matter most for. `energyLevel` sits between them as a separate
 * signal. `discuss`/`managerDiscuss` keep a place for anything else on either side's
 * agenda. `feelings`/`growth`/`friction`/`achievements` make way for the triage and
 * boundaries questions, and on the manager side `periodSummary`/`feedback`/`support`/
 * `employeeAchievements` make way for commitments and check-in cadence; the next regular
 * cycle picks them all up again. Both achievements lists feed report.ts, so a support
 * check-in that is the pair's chain anketa leaves a gap there — accepted, see
 * docs/decisions/2026-09-23-support-checkin-template-does-not-recur.md.
 */
const energyLevelQuestion: Question = {
  id: 'energyLevel',
  titleKey: 'questions.employee.energyLevel.title',
  fields: [
    {
      id: 'energyLevelNow',
      type: 'radio',
      labelKey: 'questions.fields.energyLevelNow',
      options: [
        { value: 'low', labelKey: 'questions.options.energyLevelNow.low' },
        {
          value: 'manageable',
          labelKey: 'questions.options.energyLevelNow.manageable',
        },
        { value: 'good', labelKey: 'questions.options.energyLevelNow.good' },
      ],
    },
    {
      id: 'energyDrivers',
      type: 'text',
      labelKey: 'questions.fields.energyDrivers',
    },
  ],
};

/** A list rather than one free-text prompt: gives someone under strain a structured place
 * to name items one at a time instead of having to invent the whole list up front. */
const workloadTriageQuestion: Question = {
  id: 'workloadTriage',
  titleKey: 'questions.employee.workloadTriage.title',
  fields: [
    {
      id: 'triageEntries',
      type: 'list',
      labelKey: 'questions.fields.triageEntries',
    },
  ],
};

const boundariesQuestion: Question = {
  id: 'boundaries',
  titleKey: 'questions.employee.boundaries.title',
  fields: [
    {
      id: 'boundariesNotes',
      type: 'text',
      labelKey: 'questions.fields.boundariesNotes',
    },
  ],
};

/** Same uniform `(formVersion)` signature as `onboardingEmployeeQuestions()` — nothing
 * here varies by version either. */
function supportCheckinEmployeeQuestions(_formVersion: number): Question[] {
  return [
    moodQuestion,
    energyLevelQuestion,
    workloadQuestion,
    workloadTriageQuestion,
    boundariesQuestion,
    discussQuestion,
  ];
}

const commitmentsQuestion: Question = {
  id: 'commitments',
  titleKey: 'questions.manager.commitments.title',
  fields: [
    {
      id: 'commitmentEntries',
      type: 'list',
      labelKey: 'questions.fields.commitmentEntries',
    },
  ],
};

const checkInCadenceQuestion: Question = {
  id: 'checkInCadence',
  titleKey: 'questions.manager.checkInCadence.title',
  fields: [
    {
      id: 'checkInCadenceNotes',
      type: 'text',
      labelKey: 'questions.fields.checkInCadenceNotes',
    },
  ],
};

const supportCheckinManagerQuestions: Question[] = [
  commitmentsQuestion,
  checkInCadenceQuestion,
  managerDiscussQuestion,
];

/**
 * Which built-in meeting-type template an anketa uses — see
 * private/anketa-meeting-templates-proposal.md (not tracked in git) for the full design.
 * More are added one at a time as their own template lands. Must match the backend's
 * `Anketa::TEMPLATE_KEYS` — `questions.test.ts` cross-checks the two by reading the PHP
 * source. `TemplateKey` is derived from `ANKETA_TEMPLATES` itself (same `as const` +
 * `(typeof X)[number]` shape `frontend/src/i18n/index.ts`'s `SUPPORTED_LOCALES`/
 * `SupportedLocale` already establishes) rather than declared independently, so the type
 * and the runtime list of valid keys can't drift apart from each other, at least.
 */
export const ANKETA_TEMPLATES = [
  'regular',
  'onboarding',
  'career_growth',
  'support_checkin',
] as const;
export type TemplateKey = (typeof ANKETA_TEMPLATES)[number];

interface AnketaTemplate {
  employeeQuestions(formVersion: number): Question[];
  /** The manager side has never varied by version — see `getQuestionsForSide()`. */
  managerQuestions: Question[];
  /** `CreateAnketa.svelte`'s picker label/description i18n keys. */
  labelKey: string;
  descriptionKey: string;
}

/**
 * One entry per `TemplateKey` — `Record<TemplateKey, AnketaTemplate>` itself is the
 * exhaustiveness check (a `TemplateKey` added to `ANKETA_TEMPLATES` with no matching
 * entry here fails `npm run check`), the same guarantee an earlier single-template
 * version of this file got from a `switch` + `const exhaustiveCheck: never` trip-wire —
 * see this file's own git history for that version, and why a plain `switch` was chosen
 * over a `Record` lookup back when there was exactly one template to register. A real
 * registry earns its keep now that a second template exists.
 */
const TEMPLATES: Record<TemplateKey, AnketaTemplate> = {
  regular: {
    employeeQuestions,
    managerQuestions,
    labelKey: 'createAnketa.templateRegular',
    descriptionKey: 'createAnketa.templateRegularDescription',
  },
  onboarding: {
    employeeQuestions: onboardingEmployeeQuestions,
    managerQuestions: onboardingManagerQuestions,
    labelKey: 'createAnketa.templateOnboarding',
    descriptionKey: 'createAnketa.templateOnboardingDescription',
  },
  career_growth: {
    employeeQuestions: careerGrowthEmployeeQuestions,
    managerQuestions: careerGrowthManagerQuestions,
    labelKey: 'createAnketa.templateCareerGrowth',
    descriptionKey: 'createAnketa.templateCareerGrowthDescription',
  },
  support_checkin: {
    employeeQuestions: supportCheckinEmployeeQuestions,
    managerQuestions: supportCheckinManagerQuestions,
    labelKey: 'createAnketa.templateSupportCheckin',
    descriptionKey: 'createAnketa.templateSupportCheckinDescription',
  },
};

/**
 * The registry entry for `templateKey`. An unrecognized templateKey (stale client after a
 * server rollback, or bad data) degrades to the `'regular'` template rather than throwing.
 *
 * Looked up via `Object.prototype.hasOwnProperty.call()`, not a plain `TEMPLATES[key]`/
 * `key in TEMPLATES` check — the same prototype-pollution-shaped guard
 * `frontend/src/demo.ts`'s `demoEmailFor()` needs for the identical reason: a runtime
 * `templateKey` string that happens to match an inherited `Object.prototype` member (e.g.
 * `'constructor'`) must not resolve to that prototype value instead of falling back to
 * `'regular'`. See this file's own test for the regression this guards against.
 */
function templateFor(templateKey: TemplateKey): AnketaTemplate {
  return Object.prototype.hasOwnProperty.call(TEMPLATES, templateKey)
    ? TEMPLATES[templateKey]
    : TEMPLATES.regular;
}

/** The question set for one side of an anketa at a given form version and template. */
export function getQuestionsForSide(
  side: Side,
  formVersion: number,
  templateKey: TemplateKey,
): Question[] {
  const template = templateFor(templateKey);
  return side === 'employee'
    ? template.employeeQuestions(formVersion)
    : template.managerQuestions;
}

/** `CreateAnketa.svelte`'s picker label/description i18n keys for `templateKey`. */
export function templatePickerKeys(
  templateKey: TemplateKey,
): Pick<AnketaTemplate, 'labelKey' | 'descriptionKey'> {
  return templateFor(templateKey);
}
