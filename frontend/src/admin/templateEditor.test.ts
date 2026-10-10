import { describe, expect, it } from 'vitest';
import { repoFile } from '../testRepoFile';
import {
  CURRENT_ANKETA_FORM_VERSION,
  EMPLOYEE_BUILTIN_QUESTION_IDS,
  MANAGER_BUILTIN_QUESTION_IDS,
  RETIRED_BUILTIN_QUESTION_IDS,
  builtinQuestionTitleKey,
  getQuestionsForSide,
} from '../anketa/questions';
import {
  validateTemplateDefinition,
  type TemplateBlock,
} from '../anketa/templateDefinition';
import en from '../i18n/locales/en.json';
import { messageAt } from '../i18n/testUtils';
import {
  availableBuiltins,
  MAX_TEMPLATES_PER_COMPANY,
  errorMessageValues,
  errorsByPath,
  errorLocation,
  isDrawable,
  isShownErrorPath,
  withoutBlankLabels,
  moveBlock,
  newCustomBlock,
  newCustomId,
  newOptionValue,
  regularPrefill,
  withFieldType,
} from './templateEditor';

describe('ids', () => {
  it('generates custom ids and option values in the formats the validator requires', () => {
    for (let i = 0; i < 50; i++) {
      expect(newCustomId()).toMatch(/^c_[a-z0-9]{10}$/);
      expect(newOptionValue()).toMatch(/^o_[a-z0-9]{8}$/);
    }
  });

  it('generates different ids', () => {
    const ids = new Set(Array.from({ length: 100 }, () => newCustomId()));
    expect(ids.size).toBe(100);
  });
});

function offered<T extends string>(ids: readonly T[]): T[] {
  return ids.filter((id) => !RETIRED_BUILTIN_QUESTION_IDS.includes(id));
}

describe('regularPrefill', () => {
  it('is the Regular check-in: every offered built-in of each side, in order, and valid', () => {
    const definition = regularPrefill();

    expect(definition.employee).toEqual(
      offered(EMPLOYEE_BUILTIN_QUESTION_IDS).map((questionId) => ({
        kind: 'builtin',
        questionId,
      })),
    );
    expect(definition.manager).toEqual(
      offered(MANAGER_BUILTIN_QUESTION_IDS).map((questionId) => ({
        kind: 'builtin',
        questionId,
      })),
    );
    // The same questions a new Regular 1:1 has.
    for (const side of ['employee', 'manager'] as const) {
      expect(
        definition[side].map((block) =>
          block.kind === 'builtin' ? block.questionId : null,
        ),
      ).toEqual(
        getQuestionsForSide(side, CURRENT_ANKETA_FORM_VERSION, 'regular').map(
          (question) => question.id,
        ),
      );
    }
    expect(validateTemplateDefinition(definition)).toEqual([]);
  });

  it('returns a fresh copy each time', () => {
    const first = regularPrefill();
    first.employee.pop();
    expect(regularPrefill().employee).toHaveLength(
      offered(EMPLOYEE_BUILTIN_QUESTION_IDS).length,
    );
  });
});

describe('availableBuiltins', () => {
  it('offers the side’s built-ins that are not used yet, in allowlist order', () => {
    const blocks: TemplateBlock[] = [
      { kind: 'builtin', questionId: 'workload' },
      newCustomBlock(),
      { kind: 'builtin', questionId: 'mood' },
    ];

    expect(availableBuiltins('employee', blocks)).toEqual(
      EMPLOYEE_BUILTIN_QUESTION_IDS.filter(
        (id) => id !== 'mood' && id !== 'workload',
      ),
    );
    expect(availableBuiltins('manager', [])).toEqual([
      ...MANAGER_BUILTIN_QUESTION_IDS,
    ]);
  });

  // GitHub issue #206: a new template starts without them, since the shared
  // topics list does their job, but they're the admin's to add (or to put
  // back after removing one), and a definition with them is valid.
  it('still offers the "What else to discuss" questions a new template starts without', () => {
    const prefill = regularPrefill();
    expect(availableBuiltins('employee', prefill.employee)).toEqual([
      'discuss',
    ]);
    expect(availableBuiltins('manager', prefill.manager)).toEqual([
      'managerDiscuss',
    ]);
    expect(
      validateTemplateDefinition({
        schemaVersion: 1,
        employee: [{ kind: 'builtin', questionId: 'discuss' }],
        manager: [{ kind: 'builtin', questionId: 'managerDiscuss' }],
      }),
    ).toEqual([]);
  });

  it('offers nothing once every built-in is used', () => {
    const everyManagerBuiltin: TemplateBlock[] =
      MANAGER_BUILTIN_QUESTION_IDS.map((questionId) => ({
        kind: 'builtin',
        questionId,
      }));
    expect(availableBuiltins('manager', everyManagerBuiltin)).toEqual([]);
  });
});

describe('newCustomBlock', () => {
  it('is an empty free-text question with its own ids', () => {
    const block = newCustomBlock();

    expect(block.kind).toBe('custom');
    expect(block.title).toBe('');
    expect(block.field.type).toBe('text');
    expect(block.field.options).toBeUndefined();
    expect(block.id).not.toBe(block.field.id);
  });
});

describe('moveBlock', () => {
  const blocks = ['a', 'b', 'c'];

  it('moves a block up or down by one', () => {
    expect(moveBlock(blocks, 1, -1)).toEqual(['b', 'a', 'c']);
    expect(moveBlock(blocks, 1, 1)).toEqual(['a', 'c', 'b']);
  });

  it('leaves the list as it is at either end', () => {
    expect(moveBlock(blocks, 0, -1)).toEqual(blocks);
    expect(moveBlock(blocks, 2, 1)).toEqual(blocks);
  });

  it('never changes the list it is given', () => {
    moveBlock(blocks, 0, 1);
    expect(blocks).toEqual(['a', 'b', 'c']);
  });
});

describe('withFieldType', () => {
  const field = { id: 'c_aaaaaaaaaa', type: 'text' as const, label: 'Hint' };

  it('gives a choice type two empty options to start from', () => {
    const radio = withFieldType(field, 'radio');

    expect(radio.type).toBe('radio');
    expect(radio.label).toBe('Hint');
    expect(radio.options).toHaveLength(2);
    expect(radio.options?.map((o) => o.label)).toEqual(['', '']);
    expect(radio.options?.[0].value).not.toBe(radio.options?.[1].value);
  });

  it('keeps existing options when switching between choice types', () => {
    const radio = withFieldType(field, 'radio');
    expect(withFieldType(radio, 'checkboxes').options).toBe(radio.options);
  });

  it('drops options for a non-choice type', () => {
    const radio = withFieldType(field, 'radio');
    const list = withFieldType(radio, 'list');

    expect(list.type).toBe('list');
    expect(list).not.toHaveProperty('options');
  });
});

describe('errorsByPath', () => {
  it('groups codes by path, keeping their order', () => {
    const grouped = errorsByPath([
      { path: 'employee/0/title', code: 'text_length' },
      { path: '', code: 'too_large' },
      { path: 'employee/0/title', code: 'text_chars' },
    ]);

    expect(grouped.get('employee/0/title')).toEqual([
      'text_length',
      'text_chars',
    ]);
    expect(grouped.get('')).toEqual(['too_large']);
    expect(grouped.get('manager')).toBeUndefined();
  });
});

describe('builtinQuestionTitleKey', () => {
  it('resolves for every built-in of both sides', () => {
    for (const questionId of EMPLOYEE_BUILTIN_QUESTION_IDS) {
      expect(
        typeof messageAt(en, builtinQuestionTitleKey('employee', questionId)),
      ).toBe('string');
    }
    for (const questionId of MANAGER_BUILTIN_QUESTION_IDS) {
      expect(
        typeof messageAt(en, builtinQuestionTitleKey('manager', questionId)),
      ).toBe('string');
    }
  });
});

describe('isShownErrorPath', () => {
  it('is true for the paths the editor shows at a field', () => {
    for (const path of [
      'employee',
      'manager',
      'employee/0/title',
      'manager/12/field/label',
      'employee/3/field/options/11/label',
    ]) {
      expect(isShownErrorPath(path), path).toBe(true);
    }
  });

  it('is false for the paths with no field of their own', () => {
    for (const path of [
      '',
      'schemaVersion',
      'employee/3/field/options',
      'employee/0',
      'employee/0/kind',
      'employee/0/questionId',
      'employee/0/id',
      'employee/0/field',
      'employee/0/field/id',
      'employee/0/field/type',
      'employee/0/field/options/1/value',
      'employee/0/field/options/1',
    ]) {
      expect(isShownErrorPath(path), path).toBe(false);
    }
  });
});

describe('errorMessageValues', () => {
  it('gives the counts messages state', () => {
    expect(errorMessageValues('block_count')).toEqual({ min: 1, max: 30 });
    expect(errorMessageValues('options_count')).toEqual({ min: 2, max: 12 });
    expect(errorMessageValues('too_large')).toEqual({});
    expect(errorMessageValues('text_length', 'employee/0/title')).toEqual({
      max: 200,
    });
    expect(errorMessageValues('text_length', 'manager/2/field/label')).toEqual({
      max: 300,
    });
    expect(
      errorMessageValues('text_length', 'employee/0/field/options/1/label'),
    ).toEqual({ max: 100 });
  });
});

describe('MAX_TEMPLATES_PER_COMPANY', () => {
  it("is the backend's CustomTemplate::MAX_PER_COMPANY", () => {
    const php = repoFile('backend/src/Entity/CustomTemplate.php');
    const match = /const\s+MAX_PER_COMPANY\s*=\s*(\d+)/.exec(php);
    expect(match, 'MAX_PER_COMPANY not found').not.toBeNull();
    expect(Number(match?.[1])).toBe(MAX_TEMPLATES_PER_COMPANY);
  });
});

describe('isDrawable', () => {
  it('draws a definition whose only problems are texts, counts or size', () => {
    expect(isDrawable(regularPrefill(), [])).toBe(true);
    expect(
      isDrawable(regularPrefill(), [
        { path: 'employee/1/title', code: 'text_length' },
        { path: 'employee', code: 'block_count' },
        { path: '', code: 'too_large' },
      ]),
    ).toBe(true);
  });

  it('refuses a shape it cannot key or name', () => {
    for (const code of [
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
      'options_count',
      'options_forbidden',
    ] as const) {
      expect(
        isDrawable(regularPrefill(), [{ path: 'employee/0', code }]),
        code,
      ).toBe(false);
    }
  });

  it('refuses a side the validator did not look inside: too many blocks', () => {
    const tooManyBlocks = regularPrefill();
    tooManyBlocks.employee = Array.from({ length: 31 }, () => newCustomBlock());
    expect(isDrawable(tooManyBlocks, [])).toBe(false);
  });
});

describe('withoutBlankLabels', () => {
  it('leaves out blank hints only, and keeps key order', () => {
    const definition = regularPrefill();
    const blank = newCustomBlock();
    blank.field.label = ' \t ';
    const kept = newCustomBlock();
    kept.field.label = '\u00a0';
    definition.employee.push(blank, kept);

    const cleaned = withoutBlankLabels(definition);

    expect(cleaned.employee.at(-2)).toEqual({
      ...blank,
      field: { id: blank.field.id, type: 'text' },
    });
    // NBSP isn't ASCII whitespace: the server keeps it, so it's a real hint.
    expect(cleaned.employee.at(-1)).toEqual(kept);
    // The draft itself is left as typed.
    expect(blank.field.label).toBe(' \t ');
  });
});

describe('withoutBlankLabels on a damaged definition', () => {
  it('leaves shapes it cannot read for the validator to report', () => {
    const damaged = {
      schemaVersion: 2,
      employee: { not: 'a list' },
      manager: [null, { kind: 'custom' }, 'x'],
    } as unknown as ReturnType<typeof regularPrefill>;

    expect(withoutBlankLabels(damaged)).toEqual(damaged);
    expect(
      withoutBlankLabels(null as unknown as ReturnType<typeof regularPrefill>),
    ).toBeNull();
  });
});

describe('errorLocation', () => {
  it('names the side and the question number', () => {
    expect(errorLocation('employee/1/field/options/0/value')).toEqual({
      side: 'employee',
      number: 2,
    });
    expect(errorLocation('manager')).toEqual({ side: 'manager', number: null });
    expect(errorLocation('')).toBeNull();
    expect(errorLocation('schemaVersion')).toBeNull();
  });
});
