/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  EMPLOYEE_BUILTIN_QUESTION_IDS,
  FIELD_TYPES,
  MANAGER_BUILTIN_QUESTION_IDS,
  CURRENT_ANKETA_FORM_VERSION,
  getQuestionsForSide,
  displayText,
  questionsFromDefinition,
} from './questions';
import {
  DISPLAY_NAME_REJECTED_CLASS,
  TYPES_WITH_OPTIONS,
  MAX_BLOCKS_PER_SIDE,
  MAX_FIELD_LABEL_LENGTH,
  MAX_OPTION_LABEL_LENGTH,
  MAX_OPTIONS,
  MAX_TITLE_LENGTH,
  definitionByteLength,
  trimTemplateDefinition,
  validateTemplateDefinition,
  type TemplateDefinition,
} from './templateDefinition';

// Reaches outside the frontend package, like questions.test.ts's TEMPLATE_KEYS check.
const repoFile = (path: string) =>
  readFileSync(
    fileURLToPath(new URL(`../../../${path}`, import.meta.url)),
    'utf8',
  );

interface Case {
  name: string;
  definition?: unknown;
  set?: [string, unknown][];
  unset?: string[];
  generated?: number;
  sized?: number;
  padWithWhitespace?: string;
  errors: { path: string; code: string }[];
}

/**
 * GitHub issue #141: the cases shared with the backend's
 * TemplateDefinitionValidatorTest, so both validators are held to exactly the
 * same errors. How a case builds its definition is mirrored line for line there.
 */
const fixture = JSON.parse(
  repoFile('backend/tests/Fixtures/template-definitions.json'),
) as { base: TemplateDefinition; cases: Case[] };

type Json = Record<string, unknown> | unknown[];

function setAt<T>(data: T, path: string, value: unknown): T {
  const copy = structuredClone(data) as Json;
  const keys = path.split('/');
  const last = keys.pop() as string;
  let node = copy as Record<string, unknown>;
  for (const key of keys) node = node[key] as Record<string, unknown>;
  node[last] = value;
  return copy as T;
}

function unsetAt<T>(data: T, path: string): T {
  const copy = structuredClone(data) as Json;
  const keys = path.split('/');
  const last = keys.pop() as string;
  let node = copy as Record<string, unknown>;
  for (const key of keys) node = node[key] as Record<string, unknown>;
  delete node[last];
  return copy as T;
}

function getAt(data: unknown, path: string): unknown {
  return path
    .split('/')
    .reduce(
      (node, key) => (node as Record<string, unknown>)[key],
      data as unknown,
    );
}

const pad = (n: number, width: number) => String(n).padStart(width, '0');

/** Mirrors generatedDefinition() in TemplateDefinitionValidatorTest.php. */
function generatedDefinition(blocksPerSide: number): TemplateDefinition {
  const side = (prefix: string) =>
    Array.from({ length: blocksPerSide }, (_, i) => ({
      kind: 'custom' as const,
      id: `c_${prefix}b${pad(i, 8)}`,
      title: 'a',
      field: {
        id: `c_${prefix}f${pad(i, 8)}`,
        type: 'radio' as const,
        label: 'a',
        options: Array.from({ length: MAX_OPTIONS }, (_, j) => ({
          value: `o_${pad(j, 8)}`,
          label: 'a',
        })),
      },
    }));
  return { schemaVersion: 1, employee: side('e'), manager: side('m') };
}

/** Mirrors sizedDefinition() in TemplateDefinitionValidatorTest.php. */
function sizedDefinition(bytes: number): TemplateDefinition {
  const definition = generatedDefinition(MAX_BLOCKS_PER_SIDE);
  let remaining = bytes - definitionByteLength(definition);
  expect(
    remaining,
    'sizedDefinition() starts above the requested size',
  ).toBeGreaterThanOrEqual(0);
  // Each text in traversal order, as [its holder object, its key, its limit];
  // padded in place (the objects are the definition's own).
  const texts: [Record<string, unknown>, string, number][] = [];
  for (const side of ['employee', 'manager'] as const) {
    for (const block of definition[side]) {
      if (block.kind !== 'custom') continue;
      texts.push([block, 'title', MAX_TITLE_LENGTH]);
      texts.push([block.field, 'label', MAX_FIELD_LABEL_LENGTH]);
      for (const option of block.field.options ?? []) {
        texts.push([option, 'label', MAX_OPTION_LABEL_LENGTH]);
      }
    }
  }
  for (const [holder, key, max] of texts) {
    const add = Math.min(max - 1, Math.floor(remaining / 2));
    holder[key] = 'a' + 'ж'.repeat(add);
    remaining -= 2 * add;
  }
  if (remaining === 1) {
    for (const [holder, key, max] of texts) {
      const text = holder[key] as string;
      if ([...text].length < max) {
        holder[key] = text + 'a';
        remaining = 0;
        break;
      }
    }
  }
  expect(
    remaining,
    'sizedDefinition() could not reach the requested size',
  ).toBe(0);
  return definition;
}

function definitionFor(testCase: Case): unknown {
  if ('definition' in testCase) return testCase.definition;
  if (testCase.generated !== undefined) {
    return generatedDefinition(testCase.generated);
  }
  if (testCase.sized !== undefined) {
    const definition = sizedDefinition(testCase.sized);
    const path = testCase.padWithWhitespace;
    if (path === undefined) return definition;
    return setAt(
      definition,
      path,
      ` \t${getAt(definition, path) as string}\n  `,
    );
  }
  let definition: unknown = fixture.base;
  for (const [path, value] of testCase.set ?? []) {
    definition = setAt(definition, path, value);
  }
  for (const path of testCase.unset ?? []) {
    definition = unsetAt(definition, path);
  }
  return definition;
}

describe('validateTemplateDefinition', () => {
  it.each(fixture.cases.map((testCase) => [testCase.name, testCase] as const))(
    '%s',
    (_name, testCase) => {
      expect(validateTemplateDefinition(definitionFor(testCase))).toEqual(
        testCase.errors,
      );
    },
  );

  it('builds sized definitions of exactly that many bytes', () => {
    for (const bytes of [65536, 65537, 40000, 40001]) {
      expect(
        definitionByteLength(trimTemplateDefinition(sizedDefinition(bytes))),
      ).toBe(bytes);
    }
  });

  // JSON can't carry a lone surrogate to PHP (json_decode rejects it), so this
  // is the one case not in the shared fixture; the backend's counterpart is
  // invalid UTF-8.
  it('rejects a lone surrogate', () => {
    for (const title of ['a\uD800b', 'a\uDC00b', '\uD83D', '\uDC00a']) {
      expect(
        validateTemplateDefinition(
          setAt(fixture.base, 'employee/1/title', title),
        ),
      ).toEqual([{ path: 'employee/1/title', code: 'text_chars' }]);
    }
  });

  // The validator checks what JSON.stringify() will send: a key set to
  // undefined or to a function is dropped, and an array's hole becomes null.
  // The C3 editor may build such objects.
  it('validates the definition as JSON will send it', () => {
    let definition = setAt(fixture.base, 'employee/1/field/label', undefined);
    definition = setAt(definition, 'manager/1/field/options', undefined);
    definition = setAt(definition, 'manager/1/render', () => 'x');

    expect(validateTemplateDefinition(definition)).toEqual([]);

    const withHoles = structuredClone(fixture.base);
    delete withHoles.employee[0];
    const options = (withHoles.employee[1] as { field: { options: unknown[] } })
      .field.options;
    options.length = 3;
    expect(validateTemplateDefinition(withHoles)).toEqual([
      { path: 'employee/0', code: 'type' },
      { path: 'employee/1/field/options/2', code: 'type' },
    ]);
  });

  it('rejects what JSON cannot encode', () => {
    expect(validateTemplateDefinition(undefined)).toEqual([
      { path: '', code: 'type' },
    ]);
    expect(validateTemplateDefinition({ schemaVersion: 1n })).toEqual([
      { path: '', code: 'type' },
    ]);
  });

  it('accepts a paired surrogate (an emoji)', () => {
    expect(
      validateTemplateDefinition(
        setAt(fixture.base, 'employee/1/title', 'ok 😀'),
      ),
    ).toEqual([]);
  });
});

describe('trimTemplateDefinition', () => {
  it('trims every text and nothing else, keeping key order', () => {
    let definition = setAt(
      fixture.base,
      'employee/1/field/options/0/label',
      ' Yes ж\t',
    );
    definition = setAt(definition, 'employee/1/field/label', '\n Label ж ');
    const trimmed = trimTemplateDefinition(definition);

    expect(getAt(trimmed, 'employee/1/title')).toBe('How is your week, ž?');
    expect(getAt(trimmed, 'employee/1/field/label')).toBe('Label ж');
    expect(getAt(trimmed, 'employee/1/field/options/0/label')).toBe('Yes ж');
    expect(JSON.stringify(trimmed)).toContain('"title":"How is your week, ž?"');
    expect(Object.keys(trimmed.employee[1])).toEqual(
      Object.keys(definition.employee[1]),
    );
  });

  it('trims the JSON form, as validated', () => {
    const definition = setAt(fixture.base, 'employee/1/title', {
      toJSON: () => '  Hi  ',
    });
    expect(validateTemplateDefinition(definition)).toEqual([]);
    expect(getAt(trimTemplateDefinition(definition), 'employee/1/title')).toBe(
      'Hi',
    );
  });

  it('leaves NBSP alone, unlike String.prototype.trim()', () => {
    const trimmed = trimTemplateDefinition(
      setAt(fixture.base, 'employee/1/title', ' x '),
    );
    expect(getAt(trimmed, 'employee/1/title')).toBe(' x ');
  });
});

/** A constant of TemplateDefinitionValidator.php, written as a literal list of strings. */
function phpListConstant(name: string): string[] {
  const php = repoFile('backend/src/Template/TemplateDefinitionValidator.php');
  const match = new RegExp(
    `const\\s+(?:array\\s+)?${name}\\s*=\\s*\\[([^\\]]*)\\]`,
  ).exec(php);
  expect(match, `${name} not found as a literal array`).not.toBeNull();
  return [...(match?.[1] ?? '').matchAll(/'([^']+)'/g)].map((m) => m[1]);
}

describe('the built-in allowlist', () => {
  // Drift either way means a template one side accepts and the other rejects.
  it("matches the backend's TemplateDefinitionValidator", () => {
    expect(phpListConstant('EMPLOYEE_BUILTIN_QUESTION_IDS')).toEqual([
      ...EMPLOYEE_BUILTIN_QUESTION_IDS,
    ]);
    expect(phpListConstant('MANAGER_BUILTIN_QUESTION_IDS')).toEqual([
      ...MANAGER_BUILTIN_QUESTION_IDS,
    ]);
  });

  // A field type one side accepts and the other rejects breaks the same way.
  it("uses the backend's field types, and the same ones take options", () => {
    expect(phpListConstant('FIELD_TYPES').sort()).toEqual(
      [...FIELD_TYPES].sort(),
    );
    expect(phpListConstant('TYPES_WITH_OPTIONS').sort()).toEqual(
      [...TYPES_WITH_OPTIONS].sort(),
    );
  });

  // The backend never sees built-in field ids; the validator relies on these
  // two facts instead (#133 §5.1), so they're pinned here.
  it("uses field ids unique across both sides' built-ins, none in the custom id format", () => {
    const definition: TemplateDefinition = {
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
    for (
      let formVersion = 1;
      formVersion <= CURRENT_ANKETA_FORM_VERSION;
      formVersion++
    ) {
      const fieldIds = (['employee', 'manager'] as const).flatMap((side) =>
        questionsFromDefinition(definition, side, formVersion).flatMap((q) =>
          q.fields.map((f) => f.id),
        ),
      );

      expect(new Set(fieldIds).size, `form version ${formVersion}`).toBe(
        fieldIds.length,
      );
      expect(fieldIds.filter((id) => /^c_/.test(id))).toEqual([]);
    }
  });
});

describe('the rejected characters', () => {
  // The backend rejects exactly DisplayNameField::STRIP_PATTERN in template
  // text (plus the line separators, in both validators' own code).
  it("match the backend's DisplayNameField::STRIP_PATTERN", () => {
    const php = repoFile('backend/src/Http/DisplayNameField.php');
    const pattern = /STRIP_PATTERN\s*=\s*'\/(\[[^\]]*\])\/u'/.exec(php);
    expect(pattern, 'STRIP_PATTERN not found as a literal').not.toBeNull();
    const asJs = (pattern?.[1] ?? '').replace(
      /\\x\{([0-9A-Fa-f]{4})\}/g,
      (_match, hex: string) => `\\u${hex.toUpperCase()}`,
    );

    expect(asJs).toBe(DISPLAY_NAME_REJECTED_CLASS);
  });
});

describe('questionsFromDefinition', () => {
  // The very same object, except feelings: feelingsQuestion() builds its
  // version's object on each call, in the built-in templates too.
  it('resolves every built-in block to the question the regular template uses', () => {
    for (const side of ['employee', 'manager'] as const) {
      const ids =
        side === 'employee'
          ? EMPLOYEE_BUILTIN_QUESTION_IDS
          : MANAGER_BUILTIN_QUESTION_IDS;
      const definition: TemplateDefinition = {
        ...fixture.base,
        [side]: ids.map((questionId) => ({ kind: 'builtin', questionId })),
      };
      for (
        let formVersion = 1;
        formVersion <= CURRENT_ANKETA_FORM_VERSION;
        formVersion++
      ) {
        const regular = getQuestionsForSide(side, formVersion, 'regular');
        const questions = questionsFromDefinition(
          definition,
          side,
          formVersion,
        );

        expect(questions.map((q) => q.id)).toEqual([...ids]);
        for (const question of questions) {
          const builtIn = regular.find((q) => q.id === question.id);
          if (question.id === 'feelings') {
            expect(question).toStrictEqual(builtIn);
          } else {
            expect(question).toBe(builtIn);
          }
        }
      }
    }
  });

  it('gives feelings the options of the anketa’s form version', () => {
    const definition = setAt(fixture.base, 'employee/0/questionId', 'feelings');
    const optionCount = (formVersion: number) =>
      questionsFromDefinition(
        definition,
        'employee',
        formVersion,
      )[0].fields.find((field) => field.id === 'feelingsList')?.options?.length;

    expect(optionCount(1)).toBe(6);
    expect(optionCount(2)).toBe(12);
  });

  it('renders a custom block as a one-field question with literal text', () => {
    const [, custom] = questionsFromDefinition(
      fixture.base,
      'employee',
      CURRENT_ANKETA_FORM_VERSION,
    );

    expect(custom).toEqual({
      id: 'c_emp0000001',
      titleText: '  How is your week, ž?  ',
      fields: [
        {
          id: 'c_empf000001',
          type: 'radio',
          labelText: 'Pick one, ж',
          options: [
            { value: 'o_00000001', labelText: 'Yes ж' },
            { value: 'o_00000002', labelText: 'No ž' },
          ],
        },
      ],
    });
  });

  // Only a definition that passed validateTemplateDefinition() may be
  // rendered; an id this bundle doesn't know (a newer server's) must fail
  // loudly, never reach an Object.prototype member.
  it('throws on a built-in id it does not know', () => {
    for (const questionId of ['newQuestion', 'constructor', '__proto__']) {
      const definition = setAt(
        fixture.base,
        'employee/0/questionId',
        questionId,
      );
      expect(() => questionsFromDefinition(definition, 'employee', 1)).toThrow(
        /Unknown built-in question/,
      );
    }
  });

  it('marks a custom field without a label as noLabel, with no options key for text', () => {
    const [, custom] = questionsFromDefinition(
      fixture.base,
      'manager',
      CURRENT_ANKETA_FORM_VERSION,
    );

    expect(custom.fields).toEqual([
      { id: 'c_mgrf000001', type: 'text', noLabel: true },
    ]);
  });
});

describe('displayText', () => {
  const translate = (key: string) => `t(${key})`;

  it('translates keys and returns literal text as it is', () => {
    expect(
      displayText({ id: 'q', fields: [], titleKey: 'a.b' }, translate),
    ).toBe('t(a.b)');
    expect(
      displayText({ id: 'q', fields: [], titleText: '<b>x</b>' }, translate),
    ).toBe('<b>x</b>');
    expect(
      displayText({ id: 'f', type: 'text', labelKey: 'c.d' }, translate),
    ).toBe('t(c.d)');
    expect(
      displayText({ value: 'o_00000001', labelText: 'Label ж' }, translate),
    ).toBe('Label ж');
  });

  it('is empty for a field with no label', () => {
    expect(
      displayText({ id: 'f', type: 'text', noLabel: true }, translate),
    ).toBe('');
  });
});
