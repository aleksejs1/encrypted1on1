/**
 * The admin panel's org structure import card (GitHub issue #272, part of #265):
 * reads a CSV file in the browser and turns it into the rows of emails the import
 * API takes (POST /api/admin/org-structure/import, #271). No file reaches the server.
 *
 * An HR system's export may name the manager by name, not by email. The manager is
 * an employee too, so their own row carries their email: a manager cell that isn't
 * an email is looked up among the names in the same file. Never among the accounts
 * in this app: display names are optional free text, and a wrong link would be silent.
 *
 * Which column is which is the admin's choice (guessColumns() only suggests); the
 * server decides everything else, as it does for a single assignment.
 */
import type { OrgUser } from './managerColumn';

/** App\Org\OrgStructureImport::MAX_ROWS. */
export const MAX_ROWS = 1000;
/** ImportOrgStructureRequest::MAX_EMAIL_BYTES: a longer address fails the whole request. */
const MAX_EMAIL_BYTES = 320;

export type CsvProblem =
  'notUtf8' | 'unclosedQuote' | 'noColumns' | 'noRows' | 'tooManyRows';

export interface CsvTable {
  header: string[];
  /** `line` is where the row starts in the file, counted from 1. */
  rows: { line: number; cells: string[] }[];
}

/**
 * A header row, then one row per person. `,` or `;` between fields, whichever the
 * header has more of (a spreadsheet's export uses `;` where the comma is the decimal
 * mark). Fields may be quoted, with `""` for a quote; every cell is trimmed; lines
 * with nothing in them are skipped. A first line of `sep=;`, which Excel writes
 * and reads as "the separator is", is taken at its word and is not the header.
 * Two columns, so no CSV library.
 */
export function readCsv(bytes: Uint8Array): CsvTable | { problem: CsvProblem } {
  let text: string;
  try {
    // Drops a leading byte-order mark by itself.
    text = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  } catch {
    return { problem: 'notUtf8' };
  }
  const declared = /^sep=([,;])[ \t]*(\r\n|\n|\r)/i.exec(text);
  const records = declared
    ? splitRecords(text.slice(declared[0].length), declared[1], 2)
    : splitRecords(text, separatorOf(text), 1);
  if (records === null) return { problem: 'unclosedQuote' };
  const [header, ...rows] = records;
  if (header === undefined || header.cells.length < 2) {
    return { problem: 'noColumns' };
  }
  if (rows.length === 0) return { problem: 'noRows' };
  if (rows.length > MAX_ROWS) return { problem: 'tooManyRows' };
  return { header: header.cells, rows };
}

/**
 * What a spreadsheet export leaves around a cell, as the server strips it from an
 * address (OrgStructureImport::normalize()): spaces, a no-break space, a zero-width
 * space, a byte-order mark. The last two are not all covered by trim().
 */
function trimCell(cell: string): string {
  return cell.replace(/^[\s\u200B\uFEFF]+|[\s\u200B\uFEFF]+$/g, '');
}

/** Null for a quote that is never closed: the rest of the file would be one cell. */
function splitRecords(
  text: string,
  separator: string,
  firstLine: number,
): CsvTable['rows'] | null {
  const records: CsvTable['rows'] = [];
  let cells: string[] = [];
  let cell = '';
  let quoted = false;
  let line = firstLine;
  let startLine = firstLine;

  const endRecord = (): void => {
    cells.push(trimCell(cell));
    if (cells.some((c) => c !== '')) records.push({ line: startLine, cells });
    cells = [];
    cell = '';
  };

  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    const newline = char === '\n' || char === '\r';
    if (newline) {
      if (char === '\r' && text[i + 1] === '\n') i++;
      line++;
    }
    if (quoted) {
      if (char !== '"') cell += newline ? '\n' : char;
      else if (text[i + 1] === '"') {
        cell += '"';
        i++;
      } else quoted = false;
    } else if (char === '"' && cell.trim() === '') {
      cell = '';
      quoted = true;
    } else if (char === separator) {
      cells.push(trimCell(cell));
      cell = '';
    } else if (newline) {
      endRecord();
      startLine = line;
    } else cell += char;
  }
  if (quoted) return null;
  endRecord();
  return records;
}

function separatorOf(text: string): ',' | ';' {
  const firstLine = text.trimStart().split(/[\r\n]/, 1)[0] ?? '';
  const count = (char: string): number => firstLine.split(char).length - 1;
  return count(';') > count(',') ? ';' : ',';
}

/** Indexes into the header; null is "not chosen". */
export interface ColumnChoice {
  employeeEmail: number | null;
  manager: number | null;
  /** Only read when the manager column names people: the person's own name. */
  name: number | null;
  /** The other half, for a file with first and last names apart. */
  secondName: number | null;
}

/**
 * A suggestion from common header names, each column used once. Anything it can't
 * tell stays null, and the admin picks.
 */
export function guessColumns(header: string[]): ColumnChoice {
  const names = header.map((h) => h.toLowerCase().replace(/[_-]+/g, ' '));
  const taken = new Set<number>();
  const pick = (test: (name: string) => boolean): number | null => {
    const index = names.findIndex((name, i) => !taken.has(i) && test(name));
    if (index === -1) return null;
    taken.add(index);
    return index;
  };
  const aboutManager = (name: string): boolean =>
    /manager|supervisor|reports to/.test(name);

  const own =
    (pattern: RegExp) =>
    (name: string): boolean =>
      pattern.test(name) && !aboutManager(name);

  const employeeEmail = pick(own(/e ?mail/));
  // An email before a name, if the file has both for the manager.
  const manager =
    pick((name) => aboutManager(name) && /e ?mail/.test(name)) ??
    pick(aboutManager);
  const first = pick(own(/first name|given name/));
  const last = pick(own(/last name|surname|family name/));
  const full = pick(own(/^(full |employee )?name$|^employee$/));
  return first !== null && last !== null
    ? { employeeEmail, manager, name: first, secondName: last }
    : { employeeEmail, manager, name: full ?? first ?? last, secondName: null };
}

/** An address has an "@"; anything else in the manager column is a name. */
const isAddress = (cell: string): boolean => cell.includes('@');
const tooLong = (cell: string): boolean =>
  new TextEncoder().encode(cell).length > MAX_EMAIL_BYTES;

/** Whether the name columns are needed at all. */
export function managerColumnHasNames(
  table: CsvTable,
  manager: number | null,
): boolean {
  if (manager === null) return false;
  return table.rows.some(({ cells }) => {
    const cell = cells[manager] ?? '';
    return cell !== '' && !isAddress(cell);
  });
}

/** Why a row of the file is not sent. The server has its own reasons for the rest. */
type LocalProblem =
  | 'bad_employee_email'
  | 'manager_cell_missing'
  | 'too_many_cells'
  // The server's own word for it, and its text.
  | 'conflicting_rows'
  | 'address_too_long'
  | 'manager_name_not_found'
  | 'manager_name_ambiguous';

export interface ImportRow {
  line: number;
  employeeEmail: string;
  /** Null clears the manager. */
  managerEmail: string | null;
  /** The manager as the file has it: an address, a name, or nothing. */
  managerCell: string;
}

export interface BuiltImport {
  /** What is sent, in file order: the server's `row` is an index into this. */
  rows: ImportRow[];
  /** `employeeEmail` and `managerCell` as the file has them. */
  skipped: {
    line: number;
    reason: LocalProblem;
    employeeEmail: string;
    managerCell: string;
  }[];
  /**
   * Each manager name that was found, as first written, with the email it stands
   * for. Shown in the preview: a manager who isn't in the file but has a namesake
   * who is would otherwise be replaced by the namesake unseen.
   */
  byName: { name: string; email: string }[];
}

/**
 * Names are compared ignoring case, commas and extra spaces, and with two name
 * columns in either order ("Jordan Lee", "Lee, Jordan"). Nothing fuzzier: a manager
 * written any other way stays unresolved and is shown, not guessed.
 */
function nameKey(...parts: string[]): string {
  return parts
    .join(' ')
    .normalize('NFC')
    .toLowerCase()
    .replace(/,/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Every name in the file, with the distinct emails of the rows that carry it. */
function emailsByName(
  table: CsvTable,
  choice: ColumnChoice,
): Map<string, Set<string>> {
  const byName = new Map<string, Set<string>>();
  if (choice.employeeEmail === null || choice.name === null) return byName;
  for (const { cells } of table.rows) {
    // A row buildImport() can't tell the columns of names nobody.
    if (cells.length > table.header.length) continue;
    const email = cells[choice.employeeEmail] ?? '';
    if (!isAddress(email) || tooLong(email)) continue;
    const first = cells[choice.name] ?? '';
    const second =
      choice.secondName === null ? '' : (cells[choice.secondName] ?? '');
    for (const key of new Set([
      nameKey(first, second),
      nameKey(second, first),
    ])) {
      if (key === '') continue;
      const emails = byName.get(key) ?? new Set<string>();
      emails.add(email.toLowerCase());
      byName.set(key, emails);
    }
  }
  return byName;
}

/**
 * The rows to send. A manager cell is an email, empty (no manager), or a name to
 * find in this file; a name that no row or more than one person carries leaves its
 * row out, and so does a row with no usable employee email. The other rows go.
 *
 * A row that ends before the manager column is left out too, not read as an empty
 * cell: empty means "remove the manager", and a cut-off line must not. So is a row
 * with more cells than the header (a separator inside an unquoted cell): its cells
 * are shifted, and the manager column may hold something else, or nothing.
 */
export function buildImport(
  table: CsvTable,
  choice: ColumnChoice,
): BuiltImport {
  const built: BuiltImport = { rows: [], skipped: [], byName: [] };
  if (choice.employeeEmail === null || choice.manager === null) return built;
  const byName = emailsByName(table, choice);

  for (const { line, cells } of table.rows) {
    const employeeEmail = cells[choice.employeeEmail] ?? '';
    const cell = cells[choice.manager];
    const managerCell = cell ?? '';
    const skip = (reason: LocalProblem): void => {
      built.skipped.push({ line, reason, employeeEmail, managerCell });
    };
    const send = (managerEmail: string | null): void => {
      built.rows.push({ line, employeeEmail, managerEmail, managerCell });
    };

    if (!isAddress(employeeEmail)) skip('bad_employee_email');
    else if (cell === undefined) skip('manager_cell_missing');
    else if (cells.length > table.header.length) skip('too_many_cells');
    else if (
      tooLong(employeeEmail) ||
      (isAddress(managerCell) && tooLong(managerCell))
    ) {
      skip('address_too_long');
    } else if (managerCell === '') send(null);
    else if (isAddress(managerCell)) send(managerCell);
    else {
      const key = nameKey(managerCell);
      const emails = [...(byName.get(key) ?? [])];
      if (emails.length !== 1) {
        skip(
          emails.length === 0
            ? 'manager_name_not_found'
            : 'manager_name_ambiguous',
        );
        continue;
      }
      send(emails[0]);
    }
  }
  return withoutHalfSkippedPeople(built);
}

/**
 * The server leaves out every row of a person the file gives two managers, but it
 * only sees what is sent. A person with one row left out here and another sent
 * would have the second applied, so that one is left out too. Then the list of
 * names that were found, from the rows that do go.
 */
function withoutHalfSkippedPeople(built: BuiltImport): BuiltImport {
  // Not from a row with shifted cells: its "employee" may be someone else's address.
  const skippedPeople = new Set(
    built.skipped
      .filter((row) => row.reason !== 'too_many_cells')
      .map((row) => row.employeeEmail.toLowerCase()),
  );
  const rows: ImportRow[] = [];
  const skipped = [...built.skipped];
  const byName = new Map<string, { name: string; email: string }>();
  for (const row of built.rows) {
    const { line, employeeEmail, managerEmail, managerCell } = row;
    if (skippedPeople.has(employeeEmail.toLowerCase())) {
      skipped.push({
        line,
        reason: 'conflicting_rows',
        employeeEmail,
        managerCell,
      });
      continue;
    }
    rows.push(row);
    const key = nameKey(managerCell);
    if (managerEmail !== null && !isAddress(managerCell) && !byName.has(key)) {
      byName.set(key, { name: managerCell, email: managerEmail });
    }
  }
  return { rows, skipped, byName: [...byName.values()] };
}

/**
 * The people whose manager these rows would remove: an empty manager cell means
 * "no manager", so a file with blanks for people the HR system knows nothing about
 * clears managers set by hand. The preview names them. Pass only rows the server
 * found nothing wrong with.
 */
export function clearedManagers<U extends OrgUser>(
  rows: ImportRow[],
  users: U[],
): U[] {
  const cleared = new Set(
    rows
      .filter((row) => row.managerEmail === null)
      .map((row) => row.employeeEmail.toLowerCase()),
  );
  return users.filter(
    (user) =>
      user.managerId !== null &&
      !user.deletedAt &&
      cleared.has(trimCell(user.email).toLowerCase()),
  );
}

/** App\Org\OrgImportProblem's values. */
export const SERVER_REASONS = [
  'employee_not_found',
  'manager_not_found',
  'ambiguous_email',
  'manager_unavailable',
  'own_manager',
  'conflicting_rows',
  'cycle',
] as const;

export interface ImportAnswer {
  applied: boolean;
  blocked: boolean;
  counts: { rows: number; changes: number; unchanged: number };
  problems: { row: number; reason: string }[];
}

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

/**
 * The import endpoint's answer, a 200's or a blocked 400's (the same body beside
 * its `error`), or null for anything else.
 */
export function asImportAnswer(body: unknown): ImportAnswer | null {
  if (!isRecord(body) || !isRecord(body.counts)) return null;
  const { applied, blocked, counts, problems } = body;
  const { rows, changes, unchanged } = counts;
  if (
    typeof applied !== 'boolean' ||
    typeof blocked !== 'boolean' ||
    typeof rows !== 'number' ||
    typeof changes !== 'number' ||
    typeof unchanged !== 'number' ||
    !Array.isArray(problems)
  ) {
    return null;
  }
  const list: ImportAnswer['problems'] = [];
  for (const problem of problems) {
    if (
      !isRecord(problem) ||
      typeof problem.row !== 'number' ||
      typeof problem.reason !== 'string'
    ) {
      return null;
    }
    list.push({ row: problem.row, reason: problem.reason });
  }
  return {
    applied,
    blocked,
    counts: { rows, changes, unchanged },
    problems: list,
  };
}
