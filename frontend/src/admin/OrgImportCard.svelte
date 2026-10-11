<script lang="ts">
  import { beginAction, refocus } from '../anketa/keepFocus';
  import { tick, untrack } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiPost, ApiError } from '../api/client';
  import { nameWithEmail } from '../userDisplay';
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
    type BuiltImport,
    type ColumnChoice,
    type CsvProblem,
    type CsvTable,
    type ImportAnswer,
  } from './orgImport';

  /**
   * Reporting lines from a CSV file (GitHub issue #272, part of #265). The file is
   * read here; the server gets rows of emails, first as a dry run, shown as a
   * preview, then for real. Inline like the archive form: the app has no modal.
   */
  let {
    users,
    disabled,
    inFlight = $bindable(false),
    onStart,
    onApplied,
  }: {
    users: OrgUser[];
    /** The user table is in the middle of something of its own. */
    disabled: boolean;
    /** A request of this card's is on its way: the user table waits. */
    inFlight?: boolean;
    /** A request of this card's, a preview included, is about to be sent. */
    onStart: () => void;
    /** Managers were written: the user table reloads. */
    onApplied: () => Promise<void>;
  } = $props();

  // More than any real file of 1000 short rows; refused before it is read.
  const MAX_FILE_BYTES = 2_000_000;
  const COLUMN_KEYS = [
    'employeeEmail',
    'manager',
    'name',
    'secondName',
  ] as const;

  let fileInput = $state<HTMLInputElement>();
  let open = $state(false);
  let resultHeading = $state<HTMLElement>();
  let card = $state<HTMLElement>();
  let fileProblem = $state<CsvProblem | 'tooLarge' | 'unreadable' | null>(null);
  let file = $state<{
    name: string;
    table: CsvTable;
    choice: ColumnChoice;
  } | null>(null);
  // What the server said about what was sent, and what the file's blanks would clear.
  // Null `answer`: no row of the file could be sent.
  let result = $state<{
    built: BuiltImport;
    answer: ImportAnswer | null;
    cleared: OrgUser[];
  } | null>(null);
  let requestError = $state<string | null>(null);
  // The real import, not a dry run, is the request in flight.
  let applying = $state(false);

  const namesNeeded = $derived(
    file !== null && managerColumnHasNames(file.table, file.choice.manager),
  );
  const columnsChosen = $derived(
    file !== null &&
      file.choice.employeeEmail !== null &&
      file.choice.manager !== null &&
      file.choice.employeeEmail !== file.choice.manager &&
      // Names can't be looked up without the column they are in.
      (!namesNeeded || file.choice.name !== null),
  );
  const locked = $derived(disabled || inFlight);

  // A preview is of the user table as it was. Once the table is another one (a row
  // edited, blocked or deleted, the list loaded late) it may promise something
  // else than Apply would do, so it goes; the file stays.
  $effect(() => {
    void users;
    untrack(() => {
      if (!result || result.answer?.applied) return;
      result = null;
      // It was about that preview.
      requestError = null;
    });
  });

  // Which pick is the latest: a big file read slowly must not replace a later one.
  let pickCount = 0;

  /** The card opens itself for an answer: it may have been collapsed meanwhile. */
  async function showResult(): Promise<void> {
    open = true;
    await tick();
    resultHeading?.focus();
  }

  async function pickFile(input: HTMLInputElement): Promise<void> {
    const picked = input.files?.[0];
    // Cleared, so picking the same file again (after fixing it) is a change.
    input.value = '';
    if (picked === undefined) return;
    const pick = ++pickCount;
    file = null;
    result = null;
    requestError = null;
    fileProblem = null;
    if (picked.size > MAX_FILE_BYTES) {
      fileProblem = 'tooLarge';
      return;
    }
    let bytes: Uint8Array;
    try {
      bytes = new Uint8Array(await picked.arrayBuffer());
    } catch {
      if (pick === pickCount) {
        fileProblem = 'unreadable';
        open = true;
      }
      return;
    }
    if (pick !== pickCount) return;
    // Collapsed while the file was read: what came of it is shown.
    open = true;
    const table = readCsv(bytes);
    if ('problem' in table) {
      fileProblem = table.problem;
      return;
    }
    file = { name: picked.name, table, choice: guessColumns(table.header) };
  }

  function setColumn(key: (typeof COLUMN_KEYS)[number], value: string): void {
    if (!file) return;
    file.choice[key] = value === '' ? null : Number(value);
    // The preview was of other columns.
    result = null;
    requestError = null;
  }

  /**
   * The dry run and the real import are the same request but for the flag, and
   * both answers are shown the same way: the real one may differ from the preview
   * if something changed in between. A blocked import is a 400 with the same body.
   */
  async function send(dryRun: boolean): Promise<void> {
    if (!file || inFlight) return;
    const built = buildImport(file.table, file.choice);
    requestError = null;
    if (built.rows.length === 0) {
      result = { built, answer: null, cleared: [] };
      await showResult();
      return;
    }
    onStart();
    const startedOn = beginAction();
    inFlight = true;
    applying = !dryRun;
    try {
      const answer = await request(built, dryRun);
      if (answer === null) throw new Error('Not an import answer.');
      const refused = new Set(answer.problems.map((problem) => problem.row));
      result = {
        built,
        answer,
        // Only for a preview that can be applied: a blocked file clears nobody.
        cleared:
          answer.applied || answer.blocked
            ? []
            : clearedManagers(
                built.rows.filter((_row, index) => !refused.has(index)),
                users,
              ),
      };
      if (answer.applied) {
        file = null;
        await onApplied();
      }
      await showResult();
    } catch (error) {
      // The card may have been collapsed meanwhile: it opens for the answer.
      open = true;
      const refused =
        error instanceof ApiError && error.status >= 400 && error.status < 500;
      if (dryRun || refused) {
        requestError =
          error instanceof ApiError
            ? error.message
            : $_('admin.orgImport.errorRequest');
        // The pressed button is disabled while its request runs. Not if the admin
        // has gone on to something else meanwhile (keepFocus.ts).
        void refocus(card, '[data-request-error]', { startedOn });
      } else {
        // Sent, and no answer to go by: it may have been written. The preview
        // can't be trusted any more, and the table shows what is stored now.
        result = null;
        requestError = $_('admin.orgImport.errorApply');
        await onApplied();
        // The Apply button went with the preview, so focus always moves.
        void refocus(card, '[data-request-error]');
      }
    } finally {
      inFlight = false;
      applying = false;
    }
  }

  async function request(
    built: BuiltImport,
    dryRun: boolean,
  ): Promise<ImportAnswer | null> {
    try {
      return asImportAnswer(
        await apiPost<unknown>('/api/admin/org-structure/import', {
          dryRun,
          assignments: built.rows.map(({ employeeEmail, managerEmail }) => ({
            employeeEmail,
            managerEmail,
          })),
        }),
      );
    } catch (error) {
      const blocked =
        error instanceof ApiError && error.status === 400
          ? asImportAnswer(error.body)
          : null;
      if (blocked === null) throw error;
      return blocked;
    }
  }

  function reasonText(reason: string): string {
    return (SERVER_REASONS as readonly string[]).includes(reason)
      ? $_(`admin.orgImport.reason.${reason}`)
      : reason;
  }

  /** The row as the file has it: the reason may be about either cell. */
  function fileRow(row?: {
    employeeEmail: string;
    managerCell: string;
  }): string {
    return row ? `${row.employeeEmail || '—'} → ${row.managerCell || '—'}` : '';
  }

  /** The file's problems and the server's, by line of the file. */
  const problemRows = $derived.by(() => {
    if (!result) return [];
    const { built, answer } = result;
    return [
      ...built.skipped.map((row) => ({
        line: row.line,
        value: fileRow(row),
        text: $_(`admin.orgImport.reason.${row.reason}`),
      })),
      ...(answer?.problems ?? []).map((problem) => ({
        line: built.rows[problem.row]?.line ?? 0,
        value: fileRow(built.rows[problem.row]),
        text: reasonText(problem.reason),
      })),
    ].sort((a, b) => a.line - b.line);
  });
</script>

<!-- Collapsed until the admin opens it (GitHub issue #280): an occasional job,
     above the user table it changes. A button in the heading, not <details>: a
     heading inside <summary> is lost to screen readers, and the card has to open
     itself when a result arrives. The body is hidden, not unmounted, so the
     chosen file stays chosen. -->
<div class="card elev-md org-import" bind:this={card}>
  <h2>
    <button
      type="button"
      class="disclosure"
      aria-expanded={open}
      aria-controls="org-import-body"
      onclick={() => (open = !open)}
    >
      <span aria-hidden="true">{open ? '▾' : '▸'}</span>
      {$_('admin.orgImport.title')}
    </button>
  </h2>
  <div id="org-import-body" hidden={!open}>
    <p class="text-muted hint">{$_('admin.orgImport.hint')}</p>
    <p class="text-muted hint">
      {$_('admin.orgImport.example')}
      <code>employee_email,manager_email</code>
    </p>

    <div class="file-row">
      <button
        type="button"
        class="btn btn-secondary"
        disabled={locked}
        onclick={() => fileInput?.click()}
      >
        {$_('admin.orgImport.chooseFile')}
      </button>
      <input
        type="file"
        accept=".csv,text/csv"
        hidden
        bind:this={fileInput}
        aria-label={$_('admin.orgImport.chooseFile')}
        onchange={(event) => pickFile(event.currentTarget)}
      />
      {#if file}
        <span class="text-muted"
          >{$_('admin.orgImport.fileRows', {
            values: { name: file.name, count: file.table.rows.length },
          })}</span
        >
      {/if}
    </div>
    {#if fileProblem}
      <p class="banner-error" role="alert">
        {$_(`admin.orgImport.fileProblem.${fileProblem}`, {
          values: { max: MAX_ROWS },
        })}
      </p>
    {/if}

    {#if file}
      {@const table = file.table}
      {@const choice = file.choice}
      <div class="columns">
        {#each COLUMN_KEYS as key (key)}
          {#if namesNeeded || key === 'employeeEmail' || key === 'manager'}
            <div class="field">
              <label for="org-import-{key}"
                >{$_(`admin.orgImport.column.${key}`)}</label
              >
              <select
                id="org-import-{key}"
                class="input"
                value={choice[key] === null ? '' : String(choice[key])}
                onchange={(event) => setColumn(key, event.currentTarget.value)}
                disabled={locked}
              >
                <option value="">{$_('admin.orgImport.columnNone')}</option>
                {#each table.header as name, index (index)}
                  <option value={String(index)}
                    >{name ||
                      $_('admin.orgImport.columnUnnamed', {
                        values: { number: index + 1 },
                      })}</option
                  >
                {/each}
              </select>
            </div>
          {/if}
        {/each}
      </div>
      {#if namesNeeded}
        <p class="text-muted hint">{$_('admin.orgImport.namesHint')}</p>
      {/if}

      <div>
        <button
          type="button"
          class="btn btn-secondary"
          disabled={locked || !columnsChosen}
          onclick={() => send(true)}
        >
          {$_('admin.orgImport.check')}
        </button>
      </div>
    {/if}

    {#if requestError}
      <p class="banner-error" role="alert" tabindex="-1" data-request-error>
        {requestError}
      </p>
    {/if}

    {#if result}
      {@const answer = result.answer}
      <div class="result">
        <h3 tabindex="-1" bind:this={resultHeading}>
          {answer?.applied
            ? $_('admin.orgImport.appliedTitle')
            : $_('admin.orgImport.previewTitle')}
        </h3>
        {#if answer === null}
          <p>{$_('admin.orgImport.nothingToSend')}</p>
        {:else}
          {#if answer.blocked}
            <p class="banner-error" role="alert">
              {$_('admin.orgImport.blocked')}
            </p>
          {/if}
          <ul class="counts">
            <li>
              {$_(
                answer.applied
                  ? 'admin.orgImport.countChanged'
                  : 'admin.orgImport.countChanges',
                {
                  values: { count: answer.blocked ? 0 : answer.counts.changes },
                },
              )}
            </li>
            <li>
              {$_('admin.orgImport.countUnchanged', {
                values: { count: answer.counts.unchanged },
              })}
            </li>
            <li>
              {$_('admin.orgImport.countSkipped', {
                values: { count: problemRows.length },
              })}
            </li>
          </ul>
        {/if}

        {#if answer && !answer.applied && result.built.byName.length > 0}
          <p>{$_('admin.orgImport.byNameTitle')}</p>
          <ul class="cleared by-name">
            {#each result.built.byName as found (found.name)}
              <li>{found.name} → {found.email}</li>
            {/each}
          </ul>
        {/if}

        {#if result.cleared.length > 0}
          <p>{$_('admin.orgImport.clearedTitle')}</p>
          <ul class="cleared">
            {#each result.cleared as user (user.id)}
              <li>{nameWithEmail(user.displayName, user.email)}</li>
            {/each}
          </ul>
        {/if}

        {#if problemRows.length > 0}
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>{$_('admin.orgImport.lineHeader')}</th>
                  <th>{$_('admin.orgImport.valueHeader')}</th>
                  <th>{$_('admin.orgImport.reasonHeader')}</th>
                </tr>
              </thead>
              <tbody>
                {#each problemRows as row, index (index)}
                  <tr>
                    <td>{row.line}</td>
                    <td>{row.value || '—'}</td>
                    <td>{row.text}</td>
                  </tr>
                {/each}
              </tbody>
            </table>
          </div>
        {/if}

        {#if file && answer && !answer.applied}
          <button
            type="button"
            class="btn btn-primary"
            disabled={locked || answer.blocked || answer.counts.changes === 0}
            onclick={() => send(false)}
          >
            {applying ? $_('common.saving') : $_('admin.orgImport.apply')}
          </button>
        {/if}
      </div>
    {/if}
  </div>
</div>

<style>
  .org-import {
    margin-bottom: 24px;
  }

  h2 {
    margin: 0;
  }

  /* The heading's own text, as a button. */
  .disclosure {
    padding: 0;
    border: none;
    background: none;
    color: inherit;
    font: inherit;
    text-align: left;
    cursor: pointer;
  }

  /* The card's own column and gap, which stop at its direct children. Not while
     hidden: a display rule would override the attribute. */
  #org-import-body:not([hidden]) {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
  }

  .file-row {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
  }

  .columns {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: 12px;
    align-items: end;
  }

  .result {
    margin-top: 16px;
  }

  .result h3 {
    font-size: 16px;
    margin-bottom: 8px;
  }

  .counts,
  .cleared {
    margin: 0 0 12px;
    padding-left: 20px;
  }

  .table-wrap {
    overflow-x: auto;
    margin-bottom: 12px;
  }
</style>
