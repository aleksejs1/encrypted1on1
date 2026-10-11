import { describe, expect, it } from 'vitest';
import { repoFile } from '../testRepoFile';
import type { OrgUser } from './managerColumn';
import {
  asImportAnswer,
  buildImport,
  clearedManagers,
  guessColumns,
  managerColumnHasNames,
  readCsv,
  MAX_ROWS,
  SERVER_REASONS,
  type ColumnChoice,
  type CsvTable,
} from './orgImport';

const bytes = (text: string): Uint8Array => new TextEncoder().encode(text);

function table(text: string): CsvTable {
  const result = readCsv(bytes(text));
  if ('problem' in result) throw new Error(result.problem);
  return result;
}

const cells = (text: string): string[][] =>
  table(text).rows.map((row) => row.cells);

describe('readCsv', () => {
  it('reads a header and rows separated by commas', () => {
    expect(table('employee_email,manager_email\na@x.io,b@x.io\n')).toEqual({
      header: ['employee_email', 'manager_email'],
      rows: [{ line: 2, cells: ['a@x.io', 'b@x.io'] }],
    });
  });

  it('takes semicolons when the header has more of them', () => {
    const read = table('Email;Manager\na@x.io;Lee, Jordan\n');
    expect(read.header).toEqual(['Email', 'Manager']);
    expect(read.rows[0].cells).toEqual(['a@x.io', 'Lee, Jordan']);
  });

  it('reads quoted fields: separators, doubled quotes and line breaks inside', () => {
    expect(
      cells(
        'email,manager\n"a@x.io","Lee, ""JD"" Jordan"\nb@x.io,"two\nlines"\n',
      ),
    ).toEqual([
      ['a@x.io', 'Lee, "JD" Jordan'],
      ['b@x.io', 'two\nlines'],
    ]);
  });

  it('drops a byte-order mark and trims every cell', () => {
    const read = table('﻿email , manager\n  a@x.io ,\t"  b@x.io "\n');
    expect(read.header).toEqual(['email', 'manager']);
    expect(read.rows[0].cells).toEqual(['a@x.io', 'b@x.io']);
  });

  it('trims a zero-width space and a no-break space, as the server does', () => {
    expect(
      cells('email,manager\n\u200Ba@x.io\u200B,\u00A0b@x.io\uFEFF\n'),
    ).toEqual([['a@x.io', 'b@x.io']]);
  });

  it('skips lines with nothing in them and keeps the file line of each row', () => {
    const read = table(
      'email,manager\r\n\r\na@x.io,b@x.io\r\n,\r\n \r\nc@x.io,\r\n',
    );
    expect(read.rows).toEqual([
      { line: 3, cells: ['a@x.io', 'b@x.io'] },
      { line: 6, cells: ['c@x.io', ''] },
    ]);
  });

  it('counts the lines of a quoted line break', () => {
    const read = table('email,manager\na@x.io,"two\nlines"\nb@x.io,\n');
    expect(read.rows.map((row) => row.line)).toEqual([2, 4]);
  });

  it('reads a last row with no line break after it', () => {
    expect(cells('email,manager\na@x.io,b@x.io')).toEqual([
      ['a@x.io', 'b@x.io'],
    ]);
  });

  it('takes an Excel "sep=" line at its word, and not as the header', () => {
    expect(table('sep=;\r\nName;Email;Manager\r\nAnna, B;a@x.io;\r\n')).toEqual(
      {
        header: ['Name', 'Email', 'Manager'],
        rows: [{ line: 3, cells: ['Anna, B', 'a@x.io', ''] }],
      },
    );
  });

  it('knows a "sep=" line with spaces after it or an old line ending', () => {
    expect(table('sep=;  \nemail;manager\na@x.io;\n').header).toEqual([
      'email',
      'manager',
    ]);
    expect(table('sep=,\remail,manager\ra@x.io,\r').rows).toEqual([
      { line: 3, cells: ['a@x.io', ''] },
    ]);
  });

  it('refuses bytes that are not UTF-8', () => {
    // "é" in Latin-1.
    const latin1 = new Uint8Array([...bytes('email,manager\nren'), 0xe9]);
    expect(readCsv(latin1)).toEqual({ problem: 'notUtf8' });
  });

  it('refuses a quote that is never closed', () => {
    expect(readCsv(bytes('email,manager\n"a@x.io,b@x.io\nc@x.io,\n'))).toEqual({
      problem: 'unclosedQuote',
    });
  });

  it('refuses a file with fewer than two columns, or none at all', () => {
    expect(readCsv(bytes('email\na@x.io\n'))).toEqual({ problem: 'noColumns' });
    expect(readCsv(bytes(''))).toEqual({ problem: 'noColumns' });
    expect(readCsv(bytes('\n\n'))).toEqual({ problem: 'noColumns' });
  });

  it('refuses a header with nothing under it', () => {
    expect(readCsv(bytes('email,manager\n\n'))).toEqual({ problem: 'noRows' });
  });

  it('takes the most rows the server does and refuses one more', () => {
    const rows = (count: number): string =>
      'email,manager\n' + 'a@x.io,b@x.io\n'.repeat(count);
    expect(table(rows(MAX_ROWS)).rows).toHaveLength(MAX_ROWS);
    expect(readCsv(bytes(rows(MAX_ROWS + 1)))).toEqual({
      problem: 'tooManyRows',
    });
  });

  it('has the same row limit as the server', () => {
    const php = repoFile('backend/src/Org/OrgStructureImport.php');
    expect(php).toContain(`MAX_ROWS = ${MAX_ROWS};`);
  });
});

describe('guessColumns', () => {
  it('knows the plain two-column header', () => {
    expect(guessColumns(['employee_email', 'manager_email'])).toEqual({
      employeeEmail: 0,
      manager: 1,
      name: null,
      secondName: null,
    });
  });

  it('finds a manager named by name, with first and last names apart', () => {
    expect(
      guessColumns(['First name', 'Last name', 'E-mail', 'Direct manager']),
    ).toEqual({ employeeEmail: 2, manager: 3, name: 0, secondName: 1 });
  });

  it('finds one name column', () => {
    expect(guessColumns(['Name', 'Email', 'Reports to'])).toEqual({
      employeeEmail: 1,
      manager: 2,
      name: 0,
      secondName: null,
    });
  });

  it('never takes a column about the manager for the person, and prefers the manager email', () => {
    expect(
      guessColumns([
        'Manager first name',
        'Manager last name',
        'Manager email',
        'First name',
        'Last name',
        'Email',
      ]),
    ).toEqual({ employeeEmail: 5, manager: 2, name: 3, secondName: 4 });
  });

  it('leaves what it cannot tell not chosen', () => {
    expect(guessColumns(['Кто', 'Кому'])).toEqual({
      employeeEmail: null,
      manager: null,
      name: null,
      secondName: null,
    });
  });
});

const BY_NAME: ColumnChoice = {
  employeeEmail: 1,
  manager: 2,
  name: 0,
  secondName: null,
};

describe('managerColumnHasNames', () => {
  it('is false for emails and blanks, true for anything else', () => {
    expect(
      managerColumnHasNames(
        table('email,manager\na@x.io,b@x.io\nb@x.io,\n'),
        1,
      ),
    ).toBe(false);
    expect(
      managerColumnHasNames(table('email,manager\na@x.io,Jordan Lee\n'), 1),
    ).toBe(true);
    expect(
      managerColumnHasNames(table('email,manager\na@x.io,Jordan Lee\n'), null),
    ).toBe(false);
  });
});

describe('buildImport', () => {
  it('sends emails as they are, a blank manager as null', () => {
    const built = buildImport(
      table(
        'employee_email,manager_email\nAnna@x.io,boris@x.io\nboris@x.io,\n',
      ),
      { employeeEmail: 0, manager: 1, name: null, secondName: null },
    );
    expect(built).toEqual({
      rows: [
        {
          line: 2,
          employeeEmail: 'Anna@x.io',
          managerEmail: 'boris@x.io',
          managerCell: 'boris@x.io',
        },
        {
          line: 3,
          employeeEmail: 'boris@x.io',
          managerEmail: null,
          managerCell: '',
        },
      ],
      skipped: [],
      byName: [],
    });
  });

  it('sends nothing until both columns are chosen', () => {
    const read = table('a,b\nanna@x.io,boris@x.io\n');
    expect(buildImport(read, { ...BY_NAME, employeeEmail: null }).rows).toEqual(
      [],
    );
    expect(buildImport(read, { ...BY_NAME, manager: null }).rows).toEqual([]);
  });

  it('finds a manager named by name in the same file, ignoring case and spaces', () => {
    const built = buildImport(
      table(
        'name,email,manager\nAnna Berg,anna@x.io,  jordan   LEE\nJordan Lee,Jordan@x.io,\n',
      ),
      BY_NAME,
    );
    expect(built.skipped).toEqual([]);
    // Who the name was taken for, once per name, as first written.
    expect(built.byName).toEqual([
      { name: 'jordan   LEE', email: 'jordan@x.io' },
    ]);
    expect(built.rows).toEqual([
      {
        line: 2,
        employeeEmail: 'anna@x.io',
        managerEmail: 'jordan@x.io',
        managerCell: 'jordan   LEE',
      },
      {
        line: 3,
        employeeEmail: 'Jordan@x.io',
        managerEmail: null,
        managerCell: '',
      },
    ]);
  });

  it('takes two name columns in either order, with or without a comma', () => {
    const read = table(
      'first,last,email,manager\n' +
        'Jordan,Lee,jordan@x.io,\n' +
        'Anna,Berg,anna@x.io,Jordan Lee\n' +
        'Boris,Cole,boris@x.io,"Lee, Jordan"\n' +
        'Clara,Diaz,clara@x.io,Lee Jordan\n',
    );
    const built = buildImport(read, {
      employeeEmail: 2,
      manager: 3,
      name: 0,
      secondName: 1,
    });
    expect(built.skipped).toEqual([]);
    expect(built.byName).toEqual([
      { name: 'Jordan Lee', email: 'jordan@x.io' },
      { name: 'Lee, Jordan', email: 'jordan@x.io' },
    ]);
    expect(built.rows.slice(1).map((row) => row.managerEmail)).toEqual([
      'jordan@x.io',
      'jordan@x.io',
      'jordan@x.io',
    ]);
  });

  it('leaves out a row whose manager name is not in the file, and sends the rest', () => {
    const built = buildImport(
      table(
        'name,email,manager\nAnna Berg,anna@x.io,Jordan Lee\nBoris Cole,boris@x.io,Anna Berg\n',
      ),
      BY_NAME,
    );
    expect(built.skipped).toEqual([
      {
        line: 2,
        reason: 'manager_name_not_found',
        employeeEmail: 'anna@x.io',
        managerCell: 'Jordan Lee',
      },
    ]);
    expect(built.rows).toEqual([
      {
        line: 3,
        employeeEmail: 'boris@x.io',
        managerEmail: 'anna@x.io',
        managerCell: 'Anna Berg',
      },
    ]);
  });

  it('never guesses at a name written another way', () => {
    const built = buildImport(
      table(
        'name,email,manager\nJordan Lee,jordan@x.io,\nAnna Berg,anna@x.io,J. Lee\n',
      ),
      BY_NAME,
    );
    expect(built.skipped.map((row) => row.reason)).toEqual([
      'manager_name_not_found',
    ]);
  });

  it('leaves out a row whose manager name two people share', () => {
    const built = buildImport(
      table(
        'name,email,manager\n' +
          'Jordan Lee,jordan1@x.io,\n' +
          'jordan lee,jordan2@x.io,\n' +
          'Anna Berg,anna@x.io,Jordan Lee\n',
      ),
      BY_NAME,
    );
    expect(built.skipped).toEqual([
      {
        line: 4,
        reason: 'manager_name_ambiguous',
        employeeEmail: 'anna@x.io',
        managerCell: 'Jordan Lee',
      },
    ]);
    expect(built.rows).toHaveLength(2);
  });

  it('takes one person listed twice as one person', () => {
    const built = buildImport(
      table(
        'name,email,manager\n' +
          'Jordan Lee,jordan@x.io,\n' +
          'Jordan Lee,JORDAN@x.io,\n' +
          'Anna Berg,anna@x.io,Jordan Lee\n',
      ),
      BY_NAME,
    );
    expect(built.skipped).toEqual([]);
    expect(built.rows[2].managerEmail).toBe('jordan@x.io');
  });

  it('finds no name without a name column', () => {
    const built = buildImport(
      table(
        'name,email,manager\nJordan Lee,jordan@x.io,\nAnna,anna@x.io,Jordan Lee\n',
      ),
      { ...BY_NAME, name: null },
    );
    expect(built.skipped.map((row) => row.reason)).toEqual([
      'manager_name_not_found',
    ]);
  });

  it('leaves out a row with no usable employee email, and never finds a manager through it', () => {
    const built = buildImport(
      table(
        'name,email,manager\n' +
          'Jordan Lee,,\n' +
          'Dana Poe,not an address,\n' +
          'Anna Berg,anna@x.io,Jordan Lee\n',
      ),
      BY_NAME,
    );
    expect(built.rows).toEqual([]);
    expect(built.skipped.map((row) => [row.line, row.reason])).toEqual([
      [2, 'bad_employee_email'],
      [3, 'bad_employee_email'],
      [4, 'manager_name_not_found'],
    ]);
  });

  it('leaves out a row with an address longer than the server takes, as that and not as a name', () => {
    const long = `${'e'.repeat(320)}@x.io`;
    const read = table(
      `name,email,manager\nEve Roe,${long},\nAnna Berg,anna@x.io,${long}\nBoris Cole,boris@x.io,Eve Roe\n`,
    );
    expect(managerColumnHasNames(read, 2)).toBe(true);
    expect(
      managerColumnHasNames({ ...read, rows: read.rows.slice(0, 2) }, 2),
    ).toBe(false);
    expect(
      buildImport(read, BY_NAME).skipped.map((row) => [row.line, row.reason]),
    ).toEqual([
      [2, 'address_too_long'],
      [3, 'address_too_long'],
      [4, 'manager_name_not_found'],
    ]);
  });

  it('never reads a row that ends before the manager column as "no manager"', () => {
    const built = buildImport(
      table('name,email,manager\nAnna,anna@x.io\nBoris,boris@x.io,\n'),
      BY_NAME,
    );
    expect(built.skipped).toEqual([
      {
        line: 2,
        reason: 'manager_cell_missing',
        employeeEmail: 'anna@x.io',
        managerCell: '',
      },
    ]);
    expect(built.rows.map((row) => row.employeeEmail)).toEqual(['boris@x.io']);
  });
});

describe('buildImport, a person with one row left out', () => {
  it('has the other rows left out too, as the server would for rows that disagree', () => {
    const built = buildImport(
      table(
        'name,email,manager\n' +
          'Anna Berg,anna@x.io,Sam Boss\n' +
          'Anna Berg,ANNA@x.io,\n' +
          'Jordan Lee,jordan@x.io,\n' +
          'Anna Berg,anna@x.io,Jordan Lee\n',
      ),
      BY_NAME,
    );
    expect(built.rows.map((row) => row.employeeEmail)).toEqual(['jordan@x.io']);
    expect(built.skipped.map((row) => [row.line, row.reason])).toEqual([
      [2, 'manager_name_not_found'],
      [3, 'conflicting_rows'],
      [5, 'conflicting_rows'],
    ]);
    // Not a name that was used: its only row doesn't go.
    expect(built.byName).toEqual([]);
  });
});

describe('buildImport, a row longer than the header', () => {
  it('never leaves out another person whose address is in its shifted cells', () => {
    const built = buildImport(
      table(
        'name,manager_email,employee_email\n' +
          'Lee, Jordan,boss@x.io,jordan@x.io\n' +
          'Boss,ceo@x.io,boss@x.io\n',
      ),
      { employeeEmail: 2, manager: 1, name: null, secondName: null },
    );
    expect(built.skipped.map((row) => [row.line, row.reason])).toEqual([
      [2, 'too_many_cells'],
    ]);
    expect(built.rows.map((row) => row.employeeEmail)).toEqual(['boss@x.io']);
  });

  it('names nobody: a manager is never found through its shifted cells', () => {
    const built = buildImport(
      table(
        'email,name,manager\n' +
          'jordan@x.io,Lee, Jordan,Big Boss\n' +
          'kim@x.io,Kim Park,Lee\n',
      ),
      { employeeEmail: 0, manager: 2, name: 1, secondName: null },
    );
    expect(built.rows).toEqual([]);
    expect(built.skipped.map((row) => [row.line, row.reason])).toEqual([
      [2, 'too_many_cells'],
      [3, 'manager_name_not_found'],
    ]);
  });

  it('is left out: its cells are shifted, and the manager column may be an empty one', () => {
    const built = buildImport(
      table(
        'email,name,title,manager\n' +
          'a@x.io,Lee, Jordan,,boss@x.io\n' +
          'b@x.io,"Lee, Jordan",,boss@x.io\n',
      ),
      { employeeEmail: 0, manager: 3, name: null, secondName: null },
    );
    expect(built.skipped.map((row) => [row.line, row.reason])).toEqual([
      [2, 'too_many_cells'],
    ]);
    expect(built.rows.map((row) => row.managerEmail)).toEqual(['boss@x.io']);
  });
});

describe('clearedManagers', () => {
  const user = (id: string, over: Partial<OrgUser> = {}): OrgUser => ({
    id,
    email: `${id}@x.io`,
    displayName: id,
    isBlocked: false,
    deletedAt: null,
    managerId: 'head',
    ...over,
  });
  const row = (employeeEmail: string, managerEmail: string | null = null) => ({
    line: 2,
    employeeEmail,
    managerEmail,
    managerCell: managerEmail ?? '',
  });

  it('names the people a blank manager cell would leave without a manager', () => {
    const users = [
      user('anna'),
      user('boris'),
      user('clara', { managerId: null }),
      user('dana', { deletedAt: '2026-01-01T00:00:00+00:00' }),
      user('eve'),
    ];
    const cleared = clearedManagers(
      [
        row('ANNA@x.io'),
        row('boris@x.io', 'anna@x.io'),
        row('clara@x.io'),
        row('dana@x.io'),
        row('nobody@x.io'),
      ],
      users,
    );
    expect(cleared.map((u) => u.id)).toEqual(['anna']);
  });
});

describe('asImportAnswer', () => {
  const body = {
    dryRun: true,
    applied: false,
    blocked: false,
    counts: {
      rows: 3,
      changes: 1,
      unchanged: 1,
      warnings: 1,
      errors: 0,
      blocking: 0,
    },
    problems: [{ row: 2, severity: 'warning', reason: 'employee_not_found' }],
  };

  it('keeps what the card shows', () => {
    expect(asImportAnswer(body)).toEqual({
      applied: false,
      blocked: false,
      counts: { rows: 3, changes: 1, unchanged: 1 },
      problems: [{ row: 2, reason: 'employee_not_found' }],
    });
  });

  it('reads a blocked answer beside its error', () => {
    expect(
      asImportAnswer({ error: 'A loop.', ...body, blocked: true })?.blocked,
    ).toBe(true);
  });

  it('is null for any other body', () => {
    expect(asImportAnswer(null)).toBeNull();
    expect(asImportAnswer({ error: 'Invalid.' })).toBeNull();
    expect(asImportAnswer({ ...body, counts: { rows: 3 } })).toBeNull();
    expect(asImportAnswer({ ...body, problems: [{ row: '2' }] })).toBeNull();
  });

  it('knows every reason the server gives', () => {
    const php = repoFile('backend/src/Org/OrgImportProblem.php');
    const values = [...php.matchAll(/case \w+ = '(\w+)';/g)].map((m) => m[1]);
    expect(values.sort()).toEqual([...SERVER_REASONS].sort());
  });
});
