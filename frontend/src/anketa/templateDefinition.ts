/**
 * A company template's definition (GitHub issue #141, part C1 of the custom
 * templates design in #133 §5.1): an ordered list of question blocks per side.
 * A block is either a built-in question reused as-is, or a custom question with
 * one field and literal text.
 *
 * `validateTemplateDefinition()` mirrors the backend's
 * `App\Template\TemplateDefinitionValidator` rule for rule, error for error:
 * both return the same `{path, code}` list for the same input, which the
 * shared cases in `backend/tests/Fixtures/template-definitions.json` check
 * from both test suites. A change to one must be made to the other.
 */
import {
  EMPLOYEE_BUILTIN_QUESTION_IDS,
  FIELD_TYPES,
  MANAGER_BUILTIN_QUESTION_IDS,
  type EmployeeBuiltinQuestionId,
  type FieldType,
  type ManagerBuiltinQuestionId,
  type Side,
} from './questions';

type TemplateBlock =
  | {
      kind: 'builtin';
      questionId: EmployeeBuiltinQuestionId | ManagerBuiltinQuestionId;
    }
  | {
      kind: 'custom';
      id: string;
      title: string;
      field: {
        id: string;
        type: FieldType;
        label?: string;
        options?: { value: string; label: string }[];
      };
    };

export interface TemplateDefinition {
  schemaVersion: 1;
  employee: TemplateBlock[];
  manager: TemplateBlock[];
}

export interface TemplateDefinitionError {
  /** Slash-separated location, e.g. `employee/2/field/options/1/label`; empty for the whole definition. */
  path: string;
  code: TemplateDefinitionErrorCode;
}

type TemplateDefinitionErrorCode =
  | 'type'
  | 'unknown_key'
  | 'missing_key'
  | 'schema_version'
  | 'block_count'
  | 'kind'
  | 'builtin_not_allowed'
  | 'builtin_repeated'
  | 'id_format'
  | 'id_duplicate'
  | 'field_type'
  | 'text_length'
  | 'text_chars'
  | 'options_count'
  | 'options_forbidden'
  | 'option_value_format'
  | 'option_value_duplicate'
  | 'too_large';

/** The canonical JSON encoding's size cap, in UTF-8 bytes. */
const MAX_DEFINITION_BYTES = 65536;
export const MAX_BLOCKS_PER_SIDE = 30;
export const MAX_TITLE_LENGTH = 200;
export const MAX_FIELD_LABEL_LENGTH = 300;
export const MAX_OPTION_LABEL_LENGTH = 100;
const MIN_OPTIONS = 2;
export const MAX_OPTIONS = 12;

const ID_PATTERN = /^c_[a-z0-9]{10}$/;
const OPTION_VALUE_PATTERN = /^o_[a-z0-9]{8}$/;
export const TYPES_WITH_OPTIONS: readonly FieldType[] = ['radio', 'checkboxes'];
const BUILTIN_IDS: Record<Side, readonly string[]> = {
  employee: EMPLOYEE_BUILTIN_QUESTION_IDS,
  manager: MANAGER_BUILTIN_QUESTION_IDS,
};

/**
 * Only ASCII whitespace, the same explicit set as the backend's `trim()` —
 * not `String.prototype.trim()`, which also strips NBSP and other Unicode
 * spaces that PHP's `trim()` doesn't.
 */
const ASCII_WHITESPACE = ' \t\n\r\f\v';

/**
 * Characters no template text may contain. The first class is the backend's
 * `DisplayNameField::STRIP_PATTERN` exactly (C0/C1 controls, zero-width and
 * bidi-control characters; `templateDefinition.test.ts` compares the two),
 * then the Unicode line and paragraph separators (every field is single-line),
 * then lone surrogates (which JS strings allow but PHP's `json_decode()`
 * rejects; written without lookbehind, for older Safari). Rejected rather than
 * stripped, so both sides stay identical.
 */
export const DISPLAY_NAME_REJECTED_CLASS =
  '[\\u0000-\\u001F\\u007F-\\u009F\\u200B-\\u200F\\u202A-\\u202E\\u2066-\\u2069\\uFEFF]';
const REJECTED_CHARS = new RegExp(
  `${DISPLAY_NAME_REJECTED_CLASS}|[\\u2028\\u2029]|[\\uD800-\\uDBFF](?![\\uDC00-\\uDFFF])|(?:^|[^\\uD800-\\uDBFF])[\\uDC00-\\uDFFF]`,
);

/**
 * `text` with ASCII whitespace trimmed from both ends — what the server
 * stores. A scan from each end rather than a regex, which backtracks
 * quadratically on a long run of inner spaces.
 */
function trimTemplateText(text: string): string {
  let start = 0;
  let end = text.length;
  while (start < end && ASCII_WHITESPACE.includes(text[start])) start++;
  while (end > start && ASCII_WHITESPACE.includes(text[end - 1])) end--;
  return text.slice(start, end);
}

/**
 * A string's length in Unicode code points, not UTF-16 code units — PHP's
 * `mb_strlen()`. Only needs to be exact up to `max`: a code point is at most
 * two units, so anything longer than `2 * max` units is over `max` without
 * counting (and without spreading a huge paste into an array).
 */
function codePointLength(text: string, max: number): number {
  if (text.length > 2 * max) return max + 1;
  let length = 0;
  for (const _codePoint of text) length++;
  return length;
}

/** The canonical encoding's size in UTF-8 bytes — the backend's `strlen(json_encode(...))`. */
export function definitionByteLength(definition: unknown): number {
  return new TextEncoder().encode(JSON.stringify(definition)).length;
}

type JsonObject = Record<string, unknown>;

/**
 * A JSON object (the backend decodes these as `\stdClass`, keeping `{}` apart
 * from `[]` and `{"0": …}` apart from a list, as here).
 */
function isObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function has(object: JsonObject, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(object, key);
}

/**
 * Checks every rule in #133 §5.1 and returns each violation, in a fixed
 * traversal order, identical to the backend's. Empty means valid. Strings are
 * checked as the server will store them, trimmed; the byte cap applies to
 * that trimmed definition's canonical encoding and is checked only once
 * everything else passes.
 */
export function validateTemplateDefinition(
  input: unknown,
): TemplateDefinitionError[] {
  // Validated exactly as the server will receive it: JSON.stringify() drops
  // undefined- and function-valued keys, sends an array's holes as null and
  // applies toJSON(), so an object the editor builds can't pass here and fail
  // there. Something JSON can't encode at all isn't a definition.
  let definition: unknown;
  try {
    const json = JSON.stringify(input);
    definition = json === undefined ? undefined : JSON.parse(json);
  } catch {
    definition = undefined;
  }

  const errors: TemplateDefinitionError[] = [];
  const seenIds = new Set<string>();
  const seenBuiltins = new Set<string>();

  const fail = (path: string, code: TemplateDefinitionErrorCode): void => {
    errors.push({ path, code });
  };

  /** Type, then unknown keys, then missing keys; one error at most. */
  const checkObject = (
    value: unknown,
    path: string,
    required: readonly string[],
    optional: readonly string[] = [],
  ): value is JsonObject => {
    if (!isObject(value)) {
      fail(path, 'type');
      return false;
    }
    const allowed = [...required, ...optional];
    if (Object.keys(value).some((key) => !allowed.includes(key))) {
      fail(path, 'unknown_key');
      return false;
    }
    if (required.some((key) => !has(value, key))) {
      fail(path, 'missing_key');
      return false;
    }
    return true;
  };

  const checkText = (value: unknown, path: string, max: number): void => {
    if (typeof value !== 'string') {
      fail(path, 'type');
      return;
    }
    const trimmed = trimTemplateText(value);
    if (REJECTED_CHARS.test(trimmed)) {
      fail(path, 'text_chars');
      return;
    }
    const length = codePointLength(trimmed, max);
    if (length < 1 || length > max) fail(path, 'text_length');
  };

  const checkId = (value: unknown, path: string): void => {
    if (typeof value !== 'string' || !ID_PATTERN.test(value)) {
      fail(path, 'id_format');
    } else if (seenIds.has(value)) {
      fail(path, 'id_duplicate');
    } else {
      seenIds.add(value);
    }
  };

  const checkOptions = (value: unknown, path: string): void => {
    if (!Array.isArray(value)) {
      fail(path, 'type');
      return;
    }
    const options: unknown[] = value;
    if (options.length < MIN_OPTIONS || options.length > MAX_OPTIONS) {
      fail(path, 'options_count');
      return;
    }
    const seenValues = new Set<string>();
    options.forEach((option, index) => {
      const optionPath = `${path}/${index}`;
      if (!checkObject(option, optionPath, ['value', 'label'])) return;
      const optionValue = option.value;
      if (
        typeof optionValue !== 'string' ||
        !OPTION_VALUE_PATTERN.test(optionValue)
      ) {
        fail(`${optionPath}/value`, 'option_value_format');
      } else if (seenValues.has(optionValue)) {
        fail(`${optionPath}/value`, 'option_value_duplicate');
      } else {
        seenValues.add(optionValue);
      }
      checkText(option.label, `${optionPath}/label`, MAX_OPTION_LABEL_LENGTH);
    });
  };

  const checkField = (value: unknown, path: string): void => {
    if (!checkObject(value, path, ['id', 'type'], ['label', 'options'])) {
      return;
    }
    checkId(value.id, `${path}/id`);
    const type = value.type;
    const validType =
      typeof type === 'string' &&
      (FIELD_TYPES as readonly string[]).includes(type);
    if (!validType) fail(`${path}/type`, 'field_type');
    if (has(value, 'label')) {
      checkText(value.label, `${path}/label`, MAX_FIELD_LABEL_LENGTH);
    }
    if (!validType) return;
    if ((TYPES_WITH_OPTIONS as readonly string[]).includes(type)) {
      if (has(value, 'options')) {
        checkOptions(value.options, `${path}/options`);
      } else {
        fail(`${path}/options`, 'options_count');
      }
    } else if (has(value, 'options')) {
      fail(`${path}/options`, 'options_forbidden');
    }
  };

  const checkBlock = (value: unknown, path: string, side: Side): void => {
    if (!isObject(value)) {
      fail(path, 'type');
      return;
    }
    const kind = value.kind;
    if (kind === 'builtin') {
      if (!checkObject(value, path, ['kind', 'questionId'])) return;
      const questionId = value.questionId;
      if (
        typeof questionId !== 'string' ||
        !BUILTIN_IDS[side].includes(questionId)
      ) {
        fail(`${path}/questionId`, 'builtin_not_allowed');
      } else if (seenBuiltins.has(questionId)) {
        fail(`${path}/questionId`, 'builtin_repeated');
      } else {
        seenBuiltins.add(questionId);
      }
    } else if (kind === 'custom') {
      if (!checkObject(value, path, ['kind', 'id', 'title', 'field'])) return;
      checkId(value.id, `${path}/id`);
      checkText(value.title, `${path}/title`, MAX_TITLE_LENGTH);
      checkField(value.field, `${path}/field`);
    } else {
      fail(`${path}/kind`, 'kind');
    }
  };

  if (!checkObject(definition, '', ['schemaVersion', 'employee', 'manager'])) {
    return errors;
  }
  if (definition.schemaVersion !== 1) fail('schemaVersion', 'schema_version');
  for (const side of ['employee', 'manager'] as const) {
    const blocks = definition[side];
    if (!Array.isArray(blocks)) {
      fail(side, 'type');
      continue;
    }
    if (blocks.length < 1 || blocks.length > MAX_BLOCKS_PER_SIDE) {
      fail(side, 'block_count');
      continue;
    }
    (blocks as unknown[]).forEach((block, index) =>
      checkBlock(block, `${side}/${index}`, side),
    );
  }

  if (
    errors.length === 0 &&
    definitionByteLength(
      trimPlainDefinition(definition as unknown as TemplateDefinition),
    ) > MAX_DEFINITION_BYTES
  ) {
    fail('', 'too_large');
  }
  return errors;
}

/**
 * `definition` with every text trimmed, as the frontend sends it and the
 * server stores it. Only for a definition `validateTemplateDefinition()`
 * accepted (it doesn't check shapes). Works on the same JSON form the
 * validator checked, so an editor object with a getter or toJSON() is trimmed
 * as it validated.
 */
export function trimTemplateDefinition(
  definition: TemplateDefinition,
): TemplateDefinition {
  return trimPlainDefinition(
    JSON.parse(JSON.stringify(definition)) as TemplateDefinition,
  );
}

/** trimTemplateDefinition() for data that is already plain JSON. */
function trimPlainDefinition(
  definition: TemplateDefinition,
): TemplateDefinition {
  const trimBlock = (block: TemplateBlock): TemplateBlock => {
    if (block.kind === 'builtin') return block;
    const { field } = block;
    return {
      ...block,
      title: trimTemplateText(block.title),
      field: {
        ...field,
        ...(field.label === undefined
          ? {}
          : { label: trimTemplateText(field.label) }),
        ...(field.options === undefined
          ? {}
          : {
              options: field.options.map((option) => ({
                ...option,
                label: trimTemplateText(option.label),
              })),
            }),
      },
    };
  };
  return {
    ...definition,
    employee: definition.employee.map(trimBlock),
    manager: definition.manager.map(trimBlock),
  };
}
