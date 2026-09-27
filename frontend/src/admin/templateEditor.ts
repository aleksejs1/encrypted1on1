/**
 * Pure helpers for the admin template editor (GitHub issue #143, part C3 of the
 * custom templates design in #133 §6): building and changing a template's
 * definition, one block at a time. The editor validates its draft with
 * validateTemplateDefinition() and never saves one that fails, so nothing here
 * has to produce only valid definitions — just ones the editor can show.
 */
import {
  EMPLOYEE_BUILTIN_QUESTION_IDS,
  MANAGER_BUILTIN_QUESTION_IDS,
  type EmployeeBuiltinQuestionId,
  type FieldType,
  type ManagerBuiltinQuestionId,
  type Side,
} from '../anketa/questions';
import {
  MAX_BLOCKS_PER_SIDE,
  MAX_FIELD_LABEL_LENGTH,
  MAX_OPTION_LABEL_LENGTH,
  MAX_OPTIONS,
  MAX_TITLE_LENGTH,
  MIN_OPTIONS,
  TYPES_WITH_OPTIONS,
  trimTemplateText,
  type TemplateBlock,
  type TemplateDefinition,
  type TemplateDefinitionError,
} from '../anketa/templateDefinition';

export type BuiltinQuestionId =
  EmployeeBuiltinQuestionId | ManagerBuiltinQuestionId;
export type CustomBlock = Extract<TemplateBlock, { kind: 'custom' }>;

/**
 * Templates per company, archived ones included: `CustomTemplate::MAX_PER_COMPANY`
 * (templateEditor.test.ts cross-checks the PHP source).
 */
export const MAX_TEMPLATES_PER_COMPANY = 50;

const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

/** `length` random characters from [a-z0-9]. */
function randomSuffix(length: number): string {
  const bytes = crypto.getRandomValues(new Uint8Array(length));
  return Array.from(
    bytes,
    (byte) => ID_ALPHABET[byte % ID_ALPHABET.length],
  ).join('');
}

/** A new custom block or field id: `c_` and ten characters, as the validator requires. */
export function newCustomId(): string {
  return `c_${randomSuffix(10)}`;
}

/** A new option value: `o_` and eight characters. */
export function newOptionValue(): string {
  return `o_${randomSuffix(8)}`;
}

/**
 * A new template starts as the Regular check-in (#133 §6): every built-in
 * question of both sides, all removable. Most admins adjust the standard form
 * rather than start from nothing.
 */
export function regularPrefill(): TemplateDefinition {
  return {
    schemaVersion: 1,
    employee: EMPLOYEE_BUILTIN_QUESTION_IDS.map((questionId) => ({
      kind: 'builtin',
      questionId,
    })),
    manager: MANAGER_BUILTIN_QUESTION_IDS.map((questionId) => ({
      kind: 'builtin',
      questionId,
    })),
  };
}

/** The built-in questions `side` may still add: its allowlist minus the ones already used. */
export function availableBuiltins(
  side: Side,
  blocks: readonly TemplateBlock[],
): BuiltinQuestionId[] {
  const used = new Set(
    blocks.flatMap((block) =>
      block.kind === 'builtin' ? [block.questionId] : [],
    ),
  );
  const allowed: readonly BuiltinQuestionId[] =
    side === 'employee'
      ? EMPLOYEE_BUILTIN_QUESTION_IDS
      : MANAGER_BUILTIN_QUESTION_IDS;
  return allowed.filter((id) => !used.has(id));
}

/** A new, empty free-text question. */
export function newCustomBlock(): CustomBlock {
  return {
    kind: 'custom',
    id: newCustomId(),
    title: '',
    field: { id: newCustomId(), type: 'text' },
  };
}

/**
 * `blocks` with the block at `index` moved one place up (-1) or down (+1); the
 * same list when it can't move that way.
 */
export function moveBlock<T>(
  blocks: readonly T[],
  index: number,
  delta: -1 | 1,
): T[] {
  const target = index + delta;
  if (target < 0 || target >= blocks.length) return [...blocks];
  const moved = [...blocks];
  [moved[index], moved[target]] = [moved[target], moved[index]];
  return moved;
}

/**
 * `field` switched to `type`: a choice type keeps its options, or gets two
 * empty ones to start from; any other type drops them, since the validator
 * forbids options there.
 */
export function withFieldType(
  field: CustomBlock['field'],
  type: FieldType,
): CustomBlock['field'] {
  const { options, ...rest } = field;
  if (!TYPES_WITH_OPTIONS.includes(type)) return { ...rest, type };
  return {
    ...rest,
    type,
    options: options ?? [
      { value: newOptionValue(), label: '' },
      { value: newOptionValue(), label: '' },
    ],
  };
}

/**
 * The validator's errors grouped by path, for the editor to show next to the
 * input each one is about. An error on a whole block or field (a missing key,
 * an options count) sits under that block's or field's own path.
 */
export function errorsByPath(
  errors: readonly TemplateDefinitionError[],
): Map<string, TemplateDefinitionError['code'][]> {
  const byPath = new Map<string, TemplateDefinitionError['code'][]>();
  for (const { path, code } of errors) {
    byPath.set(path, [...(byPath.get(path) ?? []), code]);
  }
  return byPath;
}

/**
 * The validator paths the editor shows next to a field: a side, and a custom
 * block's title, hint and choice labels. (An error on a choice list as a
 * whole makes the definition undrawable: see isDrawable().) Any other path (the whole
 * definition's size, a malformed id, a repeated built-in, a newer
 * schemaVersion) has no field of its own, so the editor lists it separately.
 */
export function isShownErrorPath(path: string): boolean {
  return /^(employee|manager|(employee|manager)\/\d+\/(title|field\/label|field\/options\/\d+\/label))$/.test(
    path,
  );
}

/**
 * The numbers an error message states, for the codes whose message has any: a
 * count's range, or the limit of the text at `path` (a title, a hint or a
 * choice each have their own).
 */
export function errorMessageValues(
  code: TemplateDefinitionError['code'],
  path = '',
): Record<string, number> {
  if (code === 'block_count') return { min: 1, max: MAX_BLOCKS_PER_SIDE };
  if (code === 'options_count') return { min: MIN_OPTIONS, max: MAX_OPTIONS };
  if (code !== 'text_length') return {};
  if (path.endsWith('/title')) return { max: MAX_TITLE_LENGTH };
  if (path.endsWith('/field/label')) return { max: MAX_FIELD_LABEL_LENGTH };
  return { max: MAX_OPTION_LABEL_LENGTH };
}

/**
 * `definition` with every custom field's blank hint left out, as the editor
 * validates, previews and saves it: the hint is optional, and a blank one
 * (what the server would trim to nothing) means none. The input itself keeps
 * what was typed. Runs before validation, so it only touches a block whose
 * shape it can read and leaves anything else for the validator to report.
 */
export function withoutBlankLabels(
  definition: TemplateDefinition,
): TemplateDefinition {
  const clean = (block: TemplateBlock): TemplateBlock => {
    if (
      typeof block !== 'object' ||
      block === null ||
      block.kind !== 'custom' ||
      typeof block.field !== 'object' ||
      block.field === null ||
      typeof block.field.label !== 'string' ||
      trimTemplateText(block.field.label) !== ''
    ) {
      return block;
    }
    const { label: _blank, ...field } = block.field;
    return { ...block, field };
  };
  const side = (blocks: unknown): unknown =>
    Array.isArray(blocks) ? blocks.map(clean) : blocks;
  if (typeof definition !== 'object' || definition === null) return definition;
  return {
    ...definition,
    employee: side(definition.employee) as TemplateBlock[],
    manager: side(definition.manager) as TemplateBlock[],
  };
}

/**
 * Codes that mean the definition's shape isn't one the editor can draw: its
 * block and choice lists are keyed by id or value, and built-in names are
 * looked up, so a wrong type, an unknown built-in or a repeated id would
 * break the page. Such a definition (from a newer app version, or damaged)
 * is only listed, not edited. The editor itself never produces one.
 */
const UNDRAWABLE_CODES: ReadonlySet<TemplateDefinitionError['code']> = new Set([
  'type',
  'unknown_key',
  'missing_key',
  'schema_version',
  'kind',
  'builtin_not_allowed',
  'builtin_repeated',
  'id_format',
  'id_duplicate',
  'field_type',
  'option_value_format',
  'option_value_duplicate',
  // The validator skips a choice list's contents after these, and the editor
  // can't produce either (it keeps 2–12 choices, only on choice types).
  'options_count',
  'options_forbidden',
]);

/**
 * Whether the editor can draw `definition`, given its validation errors. A
 * side with too many blocks is refused too: the validator doesn't look
 * inside it, so its contents are unchecked. (Too few is fine: the editor
 * itself can empty a side, and there's nothing inside to check.)
 */
export function isDrawable(
  definition: TemplateDefinition,
  errors: readonly TemplateDefinitionError[],
): boolean {
  if (errors.some((error) => UNDRAWABLE_CODES.has(error.code))) return false;
  return (
    definition.employee.length <= MAX_BLOCKS_PER_SIDE &&
    definition.manager.length <= MAX_BLOCKS_PER_SIDE
  );
}

/**
 * Where a validator error is, for the list of problems: its side, and the
 * question's number on that side when it's about one question. Null for the
 * whole definition.
 */
export function errorLocation(
  path: string,
): { side: Side; number: number | null } | null {
  const match = /^(employee|manager)(?:\/(\d+))?/.exec(path);
  if (!match) return null;
  return {
    side: match[1] as Side,
    number: match[2] === undefined ? null : Number(match[2]) + 1,
  };
}
