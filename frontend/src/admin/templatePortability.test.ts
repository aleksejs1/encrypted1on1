import { describe, expect, it } from 'vitest';
import {
  LONG_LINK_LENGTH,
  distinctImportName,
  encodeShareFragment,
  exportFileContent,
  exportFileName,
  importProblemMessage,
  importShareFragment,
  importTemplate,
  importTemplateFile,
  setPendingImport,
  shareLink,
  takePendingImport,
  withFreshIds,
  type ImportResult,
  type PortableTemplate,
} from './templatePortability';
import { regularPrefill } from './templateEditor';
import { invalidateIdentity } from '../crypto/identity.svelte';
import {
  validateTemplateDefinition,
  type TemplateBlock,
  type TemplateDefinition,
} from '../anketa/templateDefinition';

const definition: TemplateDefinition = {
  schemaVersion: 1,
  employee: [
    { kind: 'builtin', questionId: 'mood' },
    {
      kind: 'custom',
      id: 'c_aaaaaaaaaa',
      title: 'What would you like to learn next?',
      field: { id: 'c_bbbbbbbbbb', type: 'text', label: 'Skills' },
    },
  ],
  manager: [
    {
      kind: 'custom',
      id: 'c_cccccccccc',
      title: 'How did the quarter go?',
      field: {
        id: 'c_dddddddddd',
        type: 'radio',
        options: [
          { value: 'o_aaaaaaaa', label: 'Well' },
          { value: 'o_bbbbbbbb', label: 'Badly' },
        ],
      },
    },
  ],
};

const template: PortableTemplate = {
  name: 'Quarterly check-in',
  description: 'Long-term goals.',
  definition,
};

const OLD_IDS = [
  'c_aaaaaaaaaa',
  'c_bbbbbbbbbb',
  'c_cccccccccc',
  'c_dddddddddd',
  'o_aaaaaaaa',
  'o_bbbbbbbb',
];

/** Every custom id and option value in `def`, in order. */
function customIds(def: TemplateDefinition): string[] {
  return [...def.employee, ...def.manager].flatMap((block: TemplateBlock) =>
    block.kind === 'builtin'
      ? []
      : [
          block.id,
          block.field.id,
          ...(block.field.options ?? []).map((option) => option.value),
        ],
  );
}

/** `def` with every custom id and option value blanked, for comparing shapes. */
function withoutIds(def: TemplateDefinition): unknown {
  return JSON.parse(
    JSON.stringify(def).replace(/"(c_[a-z0-9]{10}|o_[a-z0-9]{8})"/g, '"-"'),
  );
}

function imported(result: ImportResult): PortableTemplate {
  if (!result.ok) throw new Error(`refused: ${result.problem.code}`);
  return result.template;
}

function problemCode(result: ImportResult): string {
  if (result.ok) throw new Error('accepted');
  return result.problem.code;
}

describe('exportFileContent', () => {
  it('wraps the template in a versioned envelope', () => {
    const content = exportFileContent(
      template,
      new Date('2026-10-02T12:00:00Z'),
      '1.6.0',
    );
    expect(JSON.parse(content)).toEqual({
      schemaVersion: 1,
      exportedAt: '2026-10-02T12:00:00.000Z',
      appVersion: '1.6.0',
      template,
    });
    expect(content.endsWith('\n')).toBe(true);
  });

  it('imports back to the same template, with fresh ids', () => {
    const content = exportFileContent(template, new Date(), '1.6.0');
    const back = imported(importTemplateFile(content, 'Default'));
    expect(back.name).toBe(template.name);
    expect(back.description).toBe(template.description);
    expect(withoutIds(back.definition)).toEqual(withoutIds(definition));
    expect(customIds(back.definition)).not.toContain(OLD_IDS[0]);
  });
});

describe('exportFileName', () => {
  it('slugs the name', () => {
    expect(exportFileName('Quarterly Career & Growth Check-in')).toBe(
      'encrypted1on1-template-quarterly-career-growth-check-in.json',
    );
  });

  it('falls back when the name has no ASCII letters or digits', () => {
    expect(exportFileName('Ежеквартальная встреча')).toBe(
      'encrypted1on1-template-template.json',
    );
  });

  it('caps the slug without leaving a trailing dash', () => {
    const name = `${'a'.repeat(59)} b`;
    expect(exportFileName(name)).toBe(
      `encrypted1on1-template-${'a'.repeat(59)}.json`,
    );
  });
});

describe('importTemplate', () => {
  it('accepts a full export envelope', () => {
    const result = importTemplate(
      { schemaVersion: 1, exportedAt: 'x', appVersion: 'y', template },
      'Default',
    );
    expect(imported(result).name).toBe('Quarterly check-in');
  });

  it('accepts a bare {name, description, definition}', () => {
    expect(imported(importTemplate(template, 'Default')).name).toBe(
      'Quarterly check-in',
    );
  });

  it('accepts a bare definition, named by default', () => {
    const result = imported(importTemplate(definition, 'Imported template'));
    expect(result.name).toBe('Imported template');
    expect(result.description).toBe('');
    expect(withoutIds(result.definition)).toEqual(withoutIds(definition));
  });

  it('defaults a missing name and description on a bare template', () => {
    const result = imported(importTemplate({ definition }, 'Default'));
    expect(result.name).toBe('Default');
    expect(result.description).toBe('');
  });

  it('trims the name, the description and the definition texts', () => {
    const result = imported(
      importTemplate(
        {
          name: '  Spaced  ',
          description: ' Desc ',
          definition: {
            ...definition,
            manager: [{ kind: 'builtin', questionId: 'support' }],
            employee: [
              {
                kind: 'custom',
                id: 'c_aaaaaaaaaa',
                title: '  Title  ',
                field: { id: 'c_bbbbbbbbbb', type: 'text' },
              },
            ],
          },
        },
        'Default',
      ),
    );
    expect(result.name).toBe('Spaced');
    expect(result.description).toBe('Desc');
    const [block] = result.definition.employee;
    expect(block.kind === 'custom' && block.title).toBe('Title');
  });

  it('says so for an envelope from a newer export format', () => {
    expect(
      problemCode(importTemplate({ schemaVersion: 2, template }, 'Default')),
    ).toBe('newer');
  });

  it('refuses an envelope without a valid version', () => {
    expect(problemCode(importTemplate({ template }, 'Default'))).toBe('shape');
    expect(
      problemCode(importTemplate({ schemaVersion: '1', template }, 'Default')),
    ).toBe('shape');
  });

  it('refuses anything else that is not a template', () => {
    for (const value of [
      null,
      42,
      'text',
      [],
      [template],
      { hello: 'world' },
      { schemaVersion: 1, template: 'nope' },
      { schemaVersion: 1, template: [] },
    ]) {
      expect(problemCode(importTemplate(value, 'Default'))).toBe('shape');
    }
  });

  it('refuses a name the server would refuse', () => {
    for (const name of ['', '   ', 'x'.repeat(121), 'Evil‮name', 42]) {
      expect(problemCode(importTemplate({ ...template, name }, 'D'))).toBe(
        'name',
      );
    }
  });

  it('accepts a name of exactly the maximum length', () => {
    const name = 'x'.repeat(120);
    expect(imported(importTemplate({ ...template, name }, 'D')).name).toBe(
      name,
    );
  });

  it('refuses a description the server would refuse', () => {
    for (const description of ['x'.repeat(301), 'a\u0007b', null]) {
      expect(
        problemCode(importTemplate({ ...template, description }, 'D')),
      ).toBe('description');
    }
  });

  it("refuses a definition the server would refuse, with the validator's errors", () => {
    const result = importTemplate(
      {
        ...template,
        definition: {
          ...definition,
          employee: [{ kind: 'builtin', questionId: 'not-a-question' }],
        },
      },
      'D',
    );
    expect(result).toEqual({
      ok: false,
      problem: {
        code: 'definition',
        errors: [
          { path: 'employee/0/questionId', code: 'builtin_not_allowed' },
        ],
      },
    });
  });

  it('accepts ids and option values it replaces anyway, however they look', () => {
    const readable = {
      schemaVersion: 1,
      employee: [
        {
          kind: 'custom',
          id: 'q1',
          title: 'Ready?',
          field: {
            id: 'q1',
            type: 'radio',
            options: [
              { value: 'yes', label: 'Yes' },
              { value: 'yes', label: 'No' },
            ],
          },
        },
      ],
      manager: [{ kind: 'builtin', questionId: 'support' }],
    };
    const result = imported(importTemplate(readable, 'D'));
    expect(validateTemplateDefinition(result.definition)).toEqual([]);
  });

  it('still refuses other problems next to a malformed id', () => {
    const result = importTemplate(
      {
        schemaVersion: 1,
        employee: [
          {
            kind: 'custom',
            id: 'q1',
            title: '',
            field: { id: 'q2', type: 'text' },
          },
        ],
        manager: [{ kind: 'builtin', questionId: 'support' }],
      },
      'D',
    );
    expect(result).toEqual({
      ok: false,
      problem: {
        code: 'definition',
        errors: [{ path: 'employee/0/title', code: 'text_length' }],
      },
    });
  });

  it('drops a blank hint, as the editor does', () => {
    const result = imported(
      importTemplate(
        {
          schemaVersion: 1,
          employee: [
            {
              kind: 'custom',
              id: 'c_aaaaaaaaaa',
              title: 'Title',
              field: { id: 'c_bbbbbbbbbb', type: 'text', label: '   ' },
            },
          ],
          manager: [{ kind: 'builtin', questionId: 'support' }],
        },
        'D',
      ),
    );
    const [block] = result.definition.employee;
    expect(block.kind === 'custom' && 'label' in block.field).toBe(false);
  });

  it('refuses a definition over the size cap', () => {
    const block = (index: number): unknown => ({
      kind: 'custom',
      id: `c_${String(index).padStart(10, '0')}`,
      title: 'x'.repeat(200),
      field: {
        id: `c_${String(index + 100).padStart(10, '0')}`,
        type: 'checkboxes',
        label: 'y'.repeat(300),
        options: Array.from({ length: 12 }, (_, option) => ({
          value: `o_${String(index * 100 + option).padStart(8, '0')}`,
          label: 'z'.repeat(100),
        })),
      },
    });
    const huge = {
      schemaVersion: 1,
      employee: Array.from({ length: 30 }, (_, index) => block(index)),
      manager: Array.from({ length: 30 }, (_, index) => block(index + 30)),
    };
    const result = importTemplate(huge, 'D');
    expect(result).toEqual({
      ok: false,
      problem: {
        code: 'definition',
        errors: [{ path: '', code: 'too_large' }],
      },
    });
  });

  it('says a definition that is not an object is not a template', () => {
    for (const bad of [undefined, null, [], [definition], 'text']) {
      expect(
        problemCode(importTemplate({ name: 'x', definition: bad }, 'D')),
      ).toBe('shape');
    }
  });

  it('says so for a definition from a later app version, with or without an envelope', async () => {
    const later = {
      ...template,
      definition: { ...definition, schemaVersion: 2 },
    };
    expect(problemCode(importTemplate(later, 'D'))).toBe('newer');
    expect(
      problemCode(importTemplate({ schemaVersion: 1, template: later }, 'D')),
    ).toBe('newer');
    const fragment = await encodeShareFragment(
      later as unknown as PortableTemplate,
      true,
    );
    expect(problemCode(await importShareFragment(fragment, 'D'))).toBe('newer');
  });

  it('says a template without a definition is not a template', () => {
    expect(
      problemCode(
        importTemplate({ schemaVersion: 1, template: { name: 'x' } }, 'D'),
      ),
    ).toBe('shape');
  });

  it('returns a definition the validator accepts', () => {
    const result = imported(importTemplate(template, 'D'));
    expect(validateTemplateDefinition(result.definition)).toEqual([]);
  });
});

describe('importTemplateFile', () => {
  it('refuses text that is not JSON', () => {
    expect(problemCode(importTemplateFile('{"name": ', 'D'))).toBe('json');
    expect(problemCode(importTemplateFile('', 'D'))).toBe('json');
  });
});

describe('withFreshIds', () => {
  it('replaces every custom id and option value with a fresh, well-formed one', () => {
    const fresh = withFreshIds(definition);
    const ids = customIds(fresh);
    expect(ids).toHaveLength(OLD_IDS.length);
    for (const id of ids) expect(OLD_IDS).not.toContain(id);
    const [blockId, fieldId, , , value1, value2] = ids;
    expect(blockId).toMatch(/^c_[a-z0-9]{10}$/);
    expect(fieldId).toMatch(/^c_[a-z0-9]{10}$/);
    expect(value1).toMatch(/^o_[a-z0-9]{8}$/);
    expect(value2).toMatch(/^o_[a-z0-9]{8}$/);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('keeps built-in blocks, texts, types and order as they are', () => {
    const fresh = withFreshIds(definition);
    expect(fresh.employee[0]).toEqual({ kind: 'builtin', questionId: 'mood' });
    expect(withoutIds(fresh)).toEqual(withoutIds(definition));
  });

  it('leaves the original untouched', () => {
    const before = JSON.stringify(definition);
    withFreshIds(definition);
    expect(JSON.stringify(definition)).toBe(before);
  });

  it('keeps ids unique across both sides even when the generator repeats itself', () => {
    const ids = ['c_1111111111', 'c_1111111111', 'c_2222222222'];
    let next = 0;
    const repeating = (): string =>
      next < ids.length ? ids[next++] : `c_${String(next++).padStart(10, '0')}`;
    const values = ['o_11111111', 'o_11111111', 'o_22222222'];
    let nextValue = 0;
    const repeatingValues = (): string => values[nextValue++ % values.length];
    const fresh = withFreshIds(definition, repeating, repeatingValues);
    const all = customIds(fresh);
    expect(new Set(all).size).toBe(all.length);
    expect(validateTemplateDefinition(fresh)).toEqual([]);
  });

  it('allows the same option values in different fields', () => {
    const twoChoices: TemplateDefinition = {
      ...definition,
      employee: [definition.manager[0]],
      manager: [
        {
          ...(definition.manager[0] as Extract<
            TemplateBlock,
            { kind: 'custom' }
          >),
          id: 'c_eeeeeeeeee',
          field: {
            ...(
              definition.manager[0] as Extract<
                TemplateBlock,
                { kind: 'custom' }
              >
            ).field,
            id: 'c_ffffffffff',
          },
        },
      ],
    };
    const values = ['o_11111111', 'o_22222222'];
    let next = 0;
    const fresh = withFreshIds(twoChoices, undefined, () => values[next++ % 2]);
    expect(validateTemplateDefinition(fresh)).toEqual([]);
  });
});

describe('share links', () => {
  it('round-trips a compressed link', async () => {
    const fragment = await encodeShareFragment(template, true);
    expect(fragment).toMatch(/^v1z:[A-Za-z0-9_-]+$/);
    const back = imported(await importShareFragment(fragment, 'D'));
    expect(back.name).toBe(template.name);
    expect(back.description).toBe(template.description);
    expect(withoutIds(back.definition)).toEqual(withoutIds(definition));
  });

  it('round-trips an uncompressed link', async () => {
    const fragment = await encodeShareFragment(template, false);
    expect(fragment).toMatch(/^v1r:[A-Za-z0-9_-]+$/);
    const back = imported(await importShareFragment(fragment, 'D'));
    expect(back.name).toBe(template.name);
  });

  it('round-trips non-ASCII text', async () => {
    const russian = { ...template, name: 'Встреча 1:1 — «рост»' };
    for (const compress of [true, false]) {
      const fragment = await encodeShareFragment(russian, compress);
      expect(imported(await importShareFragment(fragment, 'D')).name).toBe(
        russian.name,
      );
    }
  });

  it('accepts a percent-encoded prefix', async () => {
    const fragment = await encodeShareFragment(template, true);
    const encoded = fragment.replace('v1z:', 'v1z%3A');
    expect(imported(await importShareFragment(encoded, 'D')).name).toBe(
      template.name,
    );
  });

  it('keeps the Regular check-in well under the long-link length', async () => {
    const regular = {
      name: 'Regular',
      description: '',
      definition: regularPrefill(),
    };
    const link = shareLink(
      'https://1on1.example.com',
      await encodeShareFragment(regular, true),
    );
    expect(link.length).toBeLessThan(LONG_LINK_LENGTH);
    expect(
      link.startsWith('https://1on1.example.com/templates/preview#v1z:'),
    ).toBe(true);
  });

  it('refuses a missing, unknown or damaged fragment', async () => {
    const fragment = await encodeShareFragment(template, true);
    for (const bad of [
      '',
      'hello',
      'v1z:',
      'v1z:not base64!',
      'v1z:a',
      fragment.slice(0, fragment.length - 10),
      fragment.replace('v1z:', 'V1Z:'),
      'v1r:' + btoa('not json').replace(/=+$/, ''),
      // Not valid UTF-8.
      'v1r:_w',
    ]) {
      expect(problemCode(await importShareFragment(bad, 'D'))).toBe('link');
    }
  });

  it('refuses a link that inflates past the size cap', async () => {
    const huge = new TextEncoder().encode(' '.repeat(2 * 1024 * 1024));
    const stream = new Blob([huge])
      .stream()
      .pipeThrough(new CompressionStream('deflate-raw'));
    const bytes = new Uint8Array(await new Response(stream).arrayBuffer());
    const fragment =
      'v1z:' +
      btoa(String.fromCharCode(...bytes))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
    expect(problemCode(await importShareFragment(fragment, 'D'))).toBe('link');
  });

  it('never ends a link in an underscore, which autolinkers drop', async () => {
    for (const compress of [true, false]) {
      for (let index = 0; index < 300; index++) {
        const fragment = await encodeShareFragment(
          { ...template, name: `Template ${index}` },
          compress,
        );
        expect(fragment.endsWith('_')).toBe(false);
        expect(imported(await importShareFragment(fragment, 'D')).name).toBe(
          `Template ${index}`,
        );
      }
    }
  });

  it("says when this browser can't decompress a link", async () => {
    const fragment = await encodeShareFragment(template, true);
    expect(problemCode(await importShareFragment(fragment, 'D', false))).toBe(
      'browser',
    );
    // An uncompressed link needs nothing from the browser.
    const raw = await encodeShareFragment(template, false);
    expect(imported(await importShareFragment(raw, 'D', false)).name).toBe(
      template.name,
    );
  });

  it('says when a link is in a later format', async () => {
    for (const fragment of ['v2z:abc', 'v2r%3Aabc', 'v10q:abc']) {
      expect(problemCode(await importShareFragment(fragment, 'D'))).toBe(
        'newer',
      );
    }
  });

  it('refuses an overlong fragment unread', async () => {
    expect(
      problemCode(await importShareFragment(`v1r:${'A'.repeat(200_001)}`, 'D')),
    ).toBe('link');
  });

  it('passes on what a decoded template is refused for', async () => {
    const fragment = await encodeShareFragment(
      { ...template, name: 'x'.repeat(121) },
      true,
    );
    expect(problemCode(await importShareFragment(fragment, 'D'))).toBe('name');
  });
});

describe('distinctImportName', () => {
  it('leaves a name no active template has', () => {
    expect(distinctImportName('New', ['Old'], '(Imported)')).toBeNull();
  });

  it('adds the suffix to a taken name', () => {
    expect(distinctImportName('Weekly', ['Weekly'], '(Imported)')).toBe(
      'Weekly (Imported)',
    );
  });

  it('numbers the suffix while the suffixed name is taken too', () => {
    expect(
      distinctImportName(
        'Weekly',
        ['Weekly', 'Weekly (Imported)', 'Weekly (Imported) 2'],
        '(Imported)',
      ),
    ).toBe('Weekly (Imported) 3');
  });

  it('shortens a long name to keep within the limit', () => {
    const name = 'ж'.repeat(120);
    const renamed = distinctImportName(name, [name], '(Imported)');
    expect(Array.from(renamed ?? '')).toHaveLength(120);
    expect(renamed?.endsWith(' (Imported)')).toBe(true);
  });
});

describe('importProblemMessage', () => {
  const translate = (
    key: string,
    options?: { values?: Record<string, string | number> },
  ): string =>
    options?.values ? `${key} ${JSON.stringify(options.values)}` : key;

  it('names the problem', () => {
    expect(importProblemMessage({ code: 'link' }, translate)).toBe(
      'templatePortability.errors.link',
    );
  });

  it("explains a refused definition by its first error, in the editor's words", () => {
    expect(
      importProblemMessage(
        {
          code: 'definition',
          errors: [
            { path: 'employee', code: 'block_count' },
            { path: 'manager', code: 'type' },
          ],
        },
        translate,
      ),
    ).toBe(
      'templatePortability.errors.definition {"reason":"adminTemplateEditor.errors.block_count {\\"min\\":1,\\"max\\":30} (adminTemplateEditor.side.employee)"}',
    );
  });

  it('says which question a refused definition is refused for', () => {
    const message = importProblemMessage(
      {
        code: 'definition',
        errors: [{ path: 'manager/2/title', code: 'text_length' }],
      },
      translate,
    );
    expect(message).toContain('adminTemplateEditor.errors.text_length');
    expect(message).toContain(
      'adminTemplateEditor.location {\\"side\\":\\"adminTemplateEditor.side.manager\\",\\"number\\":3}',
    );
  });
});

describe('pending import', () => {
  it('is handed over once', () => {
    expect(takePendingImport()).toBeNull();
    setPendingImport(template);
    expect(takePendingImport()).toBe(template);
    expect(takePendingImport()).toBeNull();
  });

  it('is dropped once the tab has logged out since', () => {
    setPendingImport(template);
    invalidateIdentity();
    expect(takePendingImport()).toBeNull();
  });
});
