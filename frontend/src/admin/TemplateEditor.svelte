<script lang="ts" module>
  /**
   * The id of a template this editor just created. Saving a new template
   * navigates to its own page, which is a new instance of this component; it
   * shows "Saved." when its id is this one.
   */
  let justCreatedId: string | null = null;
</script>

<script lang="ts">
  import { tick, untrack } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiPost, apiPut, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { navigate } from '../router.svelte';
  import { PATHS, adminTemplatePath } from '../routes';
  import type { AdminTemplate } from '../api/types';
  import {
    FIELD_TYPES,
    SIDES,
    builtinQuestionTitleKey,
    type AnswerValue,
    type FieldType,
    type Side,
  } from '../anketa/questions';
  import {
    MAX_BLOCKS_PER_SIDE,
    MAX_OPTIONS,
    MAX_TEMPLATE_DESCRIPTION_LENGTH,
    MAX_TEMPLATE_NAME_LENGTH,
    MIN_OPTIONS,
    templateTextProblem,
    trimTemplateDefinition,
    trimTemplateText,
    validateTemplateDefinition,
    type TemplateBlock,
    type TemplateDefinition,
    type TemplateDefinitionError,
  } from '../anketa/templateDefinition';
  import TemplateQuestions from './TemplateQuestions.svelte';
  import CopyableLink from './CopyableLink.svelte';
  import {
    LONG_LINK_LENGTH,
    copyToClipboard,
    distinctImportName,
    encodeShareFragment,
    exportFileContent,
    exportFileName,
    shareLink,
    takePendingImport,
    type PortableTemplate,
  } from './templatePortability';
  import { downloadJsonFile } from '../downloadFile';
  import {
    beginAction,
    fallbackFocusOptions,
    ignoreHeldEnter,
    refocus,
  } from '../anketa/keepFocus';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';
  import { fetchAdminTemplates } from '../api/adminTemplates';
  import {
    availableBuiltins,
    errorMessageValues,
    errorsByPath,
    isShownErrorPath,
    moveBlock,
    newCustomBlock,
    newOptionValue,
    regularPrefill,
    withFieldType,
    withoutBlankLabels,
    isDrawable,
    errorLocation,
    MAX_TEMPLATES_PER_COMPANY,
    type BuiltinQuestionId,
  } from './templateEditor';

  /**
   * The admin template editor (GitHub issue #143, #133 §6), for a new
   * template (`id` null, pre-filled with the Regular check-in) or an existing
   * one. It validates its draft live with the same rules as the server and
   * never lets an invalid one be saved or previewed. An archived template is
   * shown read-only, with Restore as the only action. App.svelte re-creates
   * this component for each `id`, so its state always belongs to one template.
   */
  const { id }: { id: string | null } = $props();

  let status = $state<'loading' | 'ready' | 'notFound' | 'full'>('loading');
  let loadError = $state<string | null>(null);
  let createdButNotLoaded = $state(false);
  let name = $state('');
  /** Whether to show the name's error yet: not on a new, untouched form. */
  let nameTouched = $state(false);
  let description = $state('');
  let draft = $state<TemplateDefinition>(regularPrefill());
  /** The version the draft was loaded from; null for a template not saved yet. */
  let currentVersion = $state<number | null>(null);
  let archived = $state(false);
  /** A save or restore in flight: the whole form is locked meanwhile. */
  let saving = $state(false);
  let saveError = $state<string | null>(null);
  let savedNotice = $state(false);
  /** A create refused at the company's template cap: Save stays disabled. */
  let capReached = $state(false);
  /**
   * A 409 from a save. The draft stays as it is, editable but not savable,
   * until the admin reloads; `current` (from a version_conflict's body) is
   * what Reload shows, without another request when it's there.
   */
  let conflict = $state<{
    code: 'version_conflict' | 'template_archived';
    current: AdminTemplate | null;
  } | null>(null);
  let reloading = $state(false);
  let reloadError = $state<string | null>(null);
  let preview = $state(false);
  let previewAnswers = $state<Record<string, AnswerValue>>({});
  /** Each side's "Add a standard question" choice, until its Add button is pressed. */
  let builtinChoice = $state<Record<Side, BuiltinQuestionId | ''>>({
    employee: '',
    manager: '',
  });
  let root = $state<HTMLElement>();
  // A created template's page that never got to load (its unlock failed, or
  // the admin left first) mustn't leave the marker for a later visit.
  // This instance's own id: the prop is a live read of the route, which
  // may already be a different page by the time this is torn down.
  const ownId = untrack(() => id);
  $effect(() => () => {
    if (justCreatedId === ownId) justCreatedId = null;
  });

  /** Edits not saved yet: leaving the page asks first while this is set. */
  let dirty = $state(false);

  /**
   * A template imported from a file or a share link (GitHub issue #163),
   * pre-filled into a new template's form, which is otherwise the same: the
   * admin reviews it and saves it, or leaves.
   */
  const imported = ownId === null ? takePendingImport() : null;
  /** Its name was taken by another template, so it was renamed to this. */
  let importRenamed = $state<string | null>(null);
  /** The company's templates couldn't be loaded to compare names with. */
  let importNamesUnchecked = $state(false);
  if (imported !== null) {
    name = imported.name;
    description = imported.description;
    draft = imported.definition;
    nameTouched = true;
  }

  /**
   * The last Copy share link press: the template content it was for, and its
   * link (null if making it failed).
   */
  // Raw, not proxied: `source` is compared by identity with `portable`.
  let shared = $state.raw<{
    source: PortableTemplate;
    link: string | null;
    copied: boolean;
  } | null>(null);
  /** Bumped by every press, so a slower earlier one can't overwrite it. */
  let shareRun = 0;

  function downloadExport(): void {
    if (portable === null) return;
    downloadJsonFile(
      exportFileName(portable.name),
      exportFileContent(portable, new Date(), __APP_VERSION__),
    );
  }

  async function copyShareLink(): Promise<void> {
    if (portable === null) return;
    const source = portable;
    const template = $state.snapshot(portable);
    const run = ++shareRun;
    shared = null;
    const link = encodeShareFragment(template).then((fragment) =>
      shareLink(window.location.origin, fragment),
    );
    let copied = false;
    try {
      await copyToClipboard(link);
      copied = true;
    } catch {
      // No clipboard access (an http:// instance, a denied permission): the
      // link is shown to copy by hand instead.
    }
    let made: string | null = null;
    try {
      made = await link;
    } catch {
      // Shown as a failure below.
    }
    if (run === shareRun) shared = { source, link: made, copied };
  }

  // Attached only while there's something to lose: Firefox keeps no page with
  // a beforeunload listener in its back-forward cache (see
  // anketa/notesUnloadWarning.ts). The editor's own links are full page
  // loads, so they're covered too; the header's in-app Log out isn't.
  $effect(() => {
    if (!dirty) return;
    const warn = (event: BeforeUnloadEvent): void => {
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  });

  // What's validated, previewed and saved: the draft, minus blank hints.
  const snapshot = $derived(withoutBlankLabels($state.snapshot(draft)));
  const definitionErrors = $derived(validateTemplateDefinition(snapshot));
  const errors = $derived(errorsByPath(definitionErrors));
  // Only a definition without shape errors is read further (isDrawable()
  // looks at its lists), which a shape error would make unsafe.
  const drawable = $derived(isDrawable(snapshot, definitionErrors));
  const unshownErrors = $derived(
    definitionErrors.filter((error) => !isShownErrorPath(error.path)),
  );
  const nameProblem = $derived(
    templateTextProblem(name, MAX_TEMPLATE_NAME_LENGTH),
  );
  const descriptionProblem = $derived(
    templateTextProblem(description, MAX_TEMPLATE_DESCRIPTION_LENGTH, true),
  );
  const valid = $derived(
    definitionErrors.length === 0 &&
      nameProblem === null &&
      descriptionProblem === null,
  );
  /** What a save would send: the valid draft, trimmed; null while invalid. */
  const trimmedDefinition = $derived(
    valid ? trimTemplateDefinition(snapshot) : null,
  );

  /** The template on screen as an export carries it; null while invalid. */
  const portable = $derived<PortableTemplate | null>(
    trimmedDefinition === null
      ? null
      : {
          name: trimTemplateText(name),
          description: trimTemplateText(description),
          definition: trimmedDefinition,
        },
  );
  // Shown only while the form still holds what the link carries, however
  // the form changed since (an edit, a reload after a conflict). Every change
  // to the draft derives a new definition object, so comparing identities is
  // enough, without serializing the whole template on every keystroke.
  const currentShare = $derived(
    shared !== null &&
      portable !== null &&
      shared.source.definition === portable.definition &&
      shared.source.name === portable.name &&
      shared.source.description === portable.description
      ? shared
      : null,
  );

  // Cancels the fetches below on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();

  function apply(template: AdminTemplate): void {
    name = template.name;
    description = template.description;
    draft = template.definition;
    currentVersion = template.currentVersion;
    archived = template.archivedAt !== null;
    previewAnswers = {};
    // A pending "Add a standard question" pick may not be available in this
    // version (the select keeps a value it no longer offers).
    builtinChoice = { employee: '', manager: '' };
    // A read-only template has no Preview toggle to close it with.
    if (archived) preview = false;
    dirty = false;
  }

  function loadErrorMessage(error: unknown): string {
    return error instanceof ApiError
      ? error.message
      : $_('adminTemplateEditor.errorLoad');
  }

  async function load(): Promise<void> {
    if (id === null) {
      await checkRoomForNew();
      return;
    }
    // Read and cleared up front, so a failed load doesn't leave it set.
    const justCreated = justCreatedId === id;
    justCreatedId = null;
    const started = beginAction();
    try {
      const template = (await fetchAdminTemplates(readAbort)).find(
        (t) => t.id === id,
      );
      if (template === undefined) {
        status = 'notFound';
        return;
      }
      apply(template);
      status = 'ready';
      if (justCreated) {
        // The page the admin's Save press led to: focus goes back to Save,
        // unless the admin has done something else while it loaded.
        savedNotice = true;
        void refocus(root, '[data-action="save"]', { startedOn: started });
      }
    } catch (error) {
      if (isAbortError(error)) return;
      loadError = loadErrorMessage(error);
      // Its save did go through: say so, or the admin may make it again
      // (templates can't be deleted).
      createdButNotLoaded = justCreated;
    }
  }

  /**
   * A new template can't be saved once the company holds the maximum (the
   * list hides its New template link then, but this page can still be opened
   * by URL or from an older tab), so say so before any work goes into it. A
   * courtesy only: if the check fails, the form opens anyway and the server
   * refuses a save over the cap.
   */
  async function checkRoomForNew(): Promise<void> {
    try {
      const templates = await fetchAdminTemplates(readAbort);
      status = templates.length >= MAX_TEMPLATES_PER_COMPANY ? 'full' : 'ready';
      // Unsaved from the moment the form shows it: before that (or if it
      // never does), there's nothing on screen to warn about losing.
      if (status === 'ready' && imported !== null) dirty = true;
      if (imported !== null) {
        const renamed = distinctImportName(
          name,
          // Archived ones too: one restored later would share the name.
          templates.map((t) => t.name),
          $_('templatePortability.importedSuffix'),
        );
        if (renamed !== null) {
          name = renamed;
          importRenamed = renamed;
        }
      }
    } catch (error) {
      if (isAbortError(error)) return;
      status = 'ready';
      // The names couldn't be compared: say so, rather than let a
      // duplicate through unremarked.
      if (imported !== null) {
        importNamesUnchecked = true;
        dirty = true;
      }
    }
  }

  /** Replaces the draft with what the server has, after a 409. */
  async function reload(): Promise<void> {
    if (conflict === null) return;
    reloadError = null;
    savedNotice = false;
    if (conflict.current !== null) {
      apply(conflict.current);
      conflict = null;
      void focusAfterUpdate([afterReloadTarget(), 'h1']);
      return;
    }
    reloading = true;
    // Reload is disabled (and blurred) while the request runs.
    const started = beginAction();
    try {
      const template = (await fetchAdminTemplates(readAbort)).find(
        (t) => t.id === id,
      );
      if (template === undefined) {
        status = 'notFound';
        // Nothing left to save, so nothing to warn about on leaving.
        dirty = false;
      } else {
        apply(template);
      }
      conflict = null;
    } catch (error) {
      if (isAbortError(error)) return;
      reloadError = loadErrorMessage(error);
    } finally {
      reloading = false;
    }
    void refocus(root, afterReloadTarget(), { startedOn: started });
  }

  /**
   * Where focus goes once a reload settles: Reload again if it failed, the
   * Restore button for a template archived meanwhile, the page heading for
   * one that's gone, and otherwise the name, the top of the form.
   */
  function afterReloadTarget(): string {
    if (conflict !== null) return '[data-action="reload"]';
    if (status === 'notFound') return 'h1';
    if (archived) return '[data-action="restore"]';
    // Undrawable, the form (the name included) is disabled.
    return drawable ? '#template-name' : 'h1';
  }

  function errorMessage(error: TemplateDefinitionError): string {
    return $_(`adminTemplateEditor.errors.${error.code}`, {
      values: errorMessageValues(error.code, error.path),
    });
  }

  /** The message for a field's first validation error, or null. */
  function errorAt(path: string): string | null {
    const code = errors.get(path)?.[0];
    return code ? errorMessage({ path, code }) : null;
  }

  function textError(
    problem: ReturnType<typeof templateTextProblem>,
    max: number,
    optional = false,
  ): string | null {
    if (problem === null) return null;
    // An optional text is only ever too long, never too short. (`type`
    // can't happen: both inputs always hold strings.)
    const key =
      problem === 'text_chars'
        ? 'text_chars'
        : optional
          ? 'text_too_long'
          : 'text_length';
    return $_(`adminTemplateEditor.errors.${key}`, { values: { max } });
  }

  /** What a block's buttons name it by, for screen readers. */
  function blockName(side: Side, block: TemplateBlock): string {
    if (block.kind === 'builtin') {
      return $_(builtinQuestionTitleKey(side, block.questionId));
    }
    return (
      trimTemplateText(block.title) || $_('adminTemplateEditor.customQuestion')
    );
  }

  function blockKey(block: TemplateBlock): string {
    return block.kind === 'builtin' ? block.questionId : block.id;
  }

  function edited(): void {
    savedNotice = false;
    dirty = true;
  }

  /**
   * Focuses the first enabled match of `selectors` in this editor, once the
   * DOM has caught up (the same aim as `keepFocus.ts`'s refocus(), which has
   * no fallbacks). Every action here is a local change the user just made, so
   * focus always moves.
   */
  async function focusAfterUpdate(
    selectors: string[],
    options?: FocusOptions,
  ): Promise<void> {
    await tick();
    for (const selector of selectors) {
      const element = root?.querySelector<HTMLElement>(selector);
      // :disabled also covers a control disabled by its <fieldset>.
      if (element && !element.matches(':disabled')) {
        element.focus(options);
        return;
      }
    }
  }

  function move(side: Side, index: number, delta: -1 | 1): void {
    const key = blockKey(draft[side][index]);
    draft[side] = moveBlock(draft[side], index, delta);
    edited();
    // The same button again, so it can be pressed repeatedly; the other one
    // once the block reaches an end.
    const [same, other] = delta < 0 ? ['up', 'down'] : ['down', 'up'];
    void focusAfterUpdate([
      `[data-block="${key}"] [data-action="${same}"]`,
      `[data-block="${key}"] [data-action="${other}"]`,
    ]);
  }

  function remove(side: Side, index: number, click: MouseEvent): void {
    draft[side].splice(index, 1);
    edited();
    // The heading may be far up: scrolled to only for a keyboard user.
    void focusAfterUpdate(
      [`#side-heading-${side}`],
      fallbackFocusOptions(click),
    );
  }

  function addCustom(side: Side): void {
    const block = newCustomBlock();
    draft[side].push(block);
    edited();
    void focusAfterUpdate([`[data-block="${block.id}"] input`]);
  }

  function addBuiltin(side: Side): void {
    const questionId = builtinChoice[side];
    if (
      questionId === '' ||
      !availableBuiltins(side, draft[side]).includes(questionId)
    ) {
      return;
    }
    draft[side].push({ kind: 'builtin', questionId });
    builtinChoice[side] = '';
    edited();
    void focusAfterUpdate([
      `[data-block="${questionId}"] [data-action="up"]`,
      `[data-block="${questionId}"] [data-action="remove"]`,
    ]);
  }

  function changeType(side: Side, index: number, type: FieldType): void {
    const block = draft[side][index];
    if (block.kind !== 'custom') return;
    block.field = withFieldType($state.snapshot(block.field), type);
    // The preview's answer had the old type's shape.
    delete previewAnswers[block.field.id];
    edited();
  }

  function setLabel(side: Side, index: number, label: string): void {
    const block = draft[side][index];
    if (block.kind !== 'custom') return;
    // An empty label means no label; a blank one is left out of what's
    // validated and saved (withoutBlankLabels), but kept in the input.
    if (label === '') delete block.field.label;
    else block.field.label = label;
    edited();
  }

  function addOption(side: Side, index: number): void {
    const block = draft[side][index];
    if (block.kind !== 'custom' || !block.field.options) return;
    block.field.options.push({ value: newOptionValue(), label: '' });
    edited();
    void focusAfterUpdate([
      `[data-block="${block.id}"] .option-row:last-of-type input`,
    ]);
  }

  function removeOption(side: Side, index: number, option: number): void {
    const block = draft[side][index];
    if (block.kind !== 'custom' || !block.field.options) return;
    block.field.options.splice(option, 1);
    edited();
    void focusAfterUpdate([
      `[data-block="${block.id}"] [data-action="add-option"]`,
      `[data-block="${block.id}"] .option-row input`,
    ]);
  }

  async function save(): Promise<void> {
    if (trimmedDefinition === null || saving || conflict !== null) return;
    saving = true;
    saveError = null;
    savedNotice = false;
    // The Save button is disabled (and blurred) while the request runs.
    const started = beginAction();
    const body = {
      name,
      description,
      definition: trimmedDefinition,
    };
    try {
      if (id === null) {
        const created = await apiPost<{ id: string }>(
          '/api/admin/templates',
          body,
        );
        // This editor was torn down meanwhile (the admin left, or logged
        // out): don't pull them back to the new template's page.
        if (readAbort.aborted) return;
        dirty = false;
        justCreatedId = created.id;
        // Replacing the "new" entry: Back must not reopen a blank form,
        // whose save would make an undeletable duplicate.
        navigate(adminTemplatePath(created.id), { replace: true });
        return;
      }
      const updated = await apiPut<{ version: number }>(
        `/api/admin/templates/${id}`,
        { ...body, expectedVersion: currentVersion },
      );
      currentVersion = updated.version;
      savedNotice = true;
      dirty = false;
    } catch (error) {
      const conflictBody =
        error instanceof ApiError && error.status === 409
          ? (error.body as { code?: string; current?: AdminTemplate } | null)
          : null;
      if (
        error instanceof ApiError &&
        (error.body as { code?: string } | null)?.code === 'template_limit'
      ) {
        // Another admin made the last template meanwhile: saving can't work,
        // but the form stays, so nothing typed is lost.
        capReached = true;
        saveError = error.message;
      } else if (
        conflictBody?.code === 'version_conflict' ||
        conflictBody?.code === 'template_archived'
      ) {
        conflict = {
          code: conflictBody.code,
          current: conflictBody.current ?? null,
        };
      } else {
        saveError =
          error instanceof ApiError
            ? error.message
            : $_('adminTemplateEditor.errorSave');
      }
    } finally {
      saving = false;
    }
    // After a conflict, Save stays disabled: Reload is the way on.
    void refocus(
      root,
      conflict !== null ? '[data-action="reload"]' : '[data-action="save"]',
      { startedOn: started },
    );
  }

  async function restore(): Promise<void> {
    if (id === null) return;
    saving = true;
    saveError = null;
    // Restore is disabled (and blurred) while the request runs.
    const started = beginAction();
    try {
      await apiPut(`/api/admin/templates/${id}/archived`, { archived: false });
      archived = false;
    } catch (error) {
      saveError =
        error instanceof ApiError
          ? error.message
          : $_('adminTemplateEditor.errorSave');
    } finally {
      saving = false;
    }
    // Restored, the Restore button is gone with the archived notice.
    void refocus(
      root,
      archived ? '[data-action="restore"]' : drawable ? '#template-name' : 'h1',
      { startedOn: started },
    );
  }
</script>

<main bind:this={root} onkeydowncapture={ignoreHeldEnter}>
  <h1 tabindex="-1">
    {id === null
      ? $_('adminTemplateEditor.titleNew')
      : status === 'ready' && !archived
        ? $_('adminTemplateEditor.titleEdit')
        : $_('adminTemplateEditor.titleView')}
  </h1>

  <AdminGate onReady={load} errorLoadKey="adminTemplateEditor.errorLoad">
    <AdminTabStrip active="templates" />
    <p>
      <a href={PATHS.adminTemplates}>{$_('adminTemplateEditor.back')}</a>
    </p>

    {#if loadError}
      {#if createdButNotLoaded}
        <p class="banner-success" role="status">
          {$_('adminTemplateEditor.createdButNotLoaded')}
        </p>
      {/if}
      <p class="banner-error" role="alert">{loadError}</p>
    {:else if status === 'loading'}
      <p class="text-muted">{$_('common.loading')}</p>
    {:else if status === 'notFound'}
      <p class="text-muted">{$_('adminTemplateEditor.notFound')}</p>
    {:else if status === 'full'}
      {#if imported !== null}
        <p class="banner-error" role="alert">
          {$_('templatePortability.importAtCap')}
        </p>
      {/if}
      <p class="text-muted">
        {$_('adminTemplates.atCap', {
          values: { max: MAX_TEMPLATES_PER_COMPANY },
        })}
      </p>
    {:else if status === 'ready'}
      {@const nameError =
        nameTouched || id !== null
          ? textError(nameProblem, MAX_TEMPLATE_NAME_LENGTH)
          : null}
      {@const descriptionError = textError(
        descriptionProblem,
        MAX_TEMPLATE_DESCRIPTION_LENGTH,
        true,
      )}
      <p class="banner-privacy" role="note">
        {$_('adminTemplateEditor.privacyNote')}
      </p>
      {#if imported !== null}
        <p class="banner-success" role="status">
          {$_('templatePortability.importedNotice')}
          {#if importRenamed !== null}
            {$_('templatePortability.importRenamed', {
              values: { name: importRenamed },
            })}
          {/if}
          {#if importNamesUnchecked}
            {$_('templatePortability.importNamesUnchecked')}
          {/if}
        </p>
      {/if}

      {#if archived}
        <div class="card archived-card">
          <p>{$_('adminTemplateEditor.archivedReadOnly')}</p>
          <button
            type="button"
            class="btn btn-primary"
            data-action="restore"
            disabled={saving}
            onclick={restore}>{$_('adminTemplates.restore')}</button
          >
        </div>
      {/if}

      <!-- Not drawable: nothing can be saved, so nothing is editable. -->
      <fieldset
        class="editor"
        disabled={archived || saving || reloading || !drawable}
      >
        <div class="field">
          <label for="template-name"
            >{$_('adminTemplateEditor.nameLabel')}</label
          >
          <input
            id="template-name"
            class="input"
            bind:value={name}
            oninput={() => {
              nameTouched = true;
              edited();
            }}
            aria-invalid={nameError !== null}
            aria-describedby="template-name-hint template-name-error"
          />
          <p id="template-name-hint" class="text-muted hint">
            {$_('adminTemplateEditor.nameHint')}
          </p>
          <p id="template-name-error" class="field-error">
            {nameError ?? ''}
          </p>
        </div>

        <div class="field">
          <label for="template-description"
            >{$_('adminTemplateEditor.descriptionLabel')}</label
          >
          <input
            id="template-description"
            class="input"
            bind:value={description}
            oninput={edited}
            aria-invalid={descriptionError !== null}
            aria-describedby="template-description-error"
          />
          <p id="template-description-error" class="field-error">
            {descriptionError ?? ''}
          </p>
        </div>

        {#if !drawable}
          <p class="banner-error" role="alert">
            {$_('adminTemplateEditor.undrawable')}
          </p>
        {:else}
          {#each SIDES as side (side)}
            {@const sideError = errorAt(side)}
            {@const builtinsLeft = availableBuiltins(side, draft[side])}
            {@const full = draft[side].length >= MAX_BLOCKS_PER_SIDE}
            <section class="side" aria-labelledby="side-heading-{side}">
              <h2 id="side-heading-{side}" tabindex="-1">
                {$_(`adminTemplateEditor.side.${side}`)}
              </h2>
              {#if sideError}
                <p class="field-error">{sideError}</p>
              {/if}
              <ol class="blocks">
                {#each draft[side] as block, index (blockKey(block))}
                  {@const path = `${side}/${index}`}
                  {@const question = blockName(side, block)}
                  <li class="card block" data-block={blockKey(block)}>
                    <div class="block-header">
                      <p class="block-title">
                        {#if block.kind === 'builtin'}
                          {question}
                          <span class="tag tag-neutral"
                            >{$_('adminTemplateEditor.standardTag')}</span
                          >
                        {:else}
                          {$_('adminTemplateEditor.customQuestion')}
                        {/if}
                      </p>
                      <div class="block-actions">
                        <button
                          type="button"
                          class="btn btn-ghost"
                          data-action="up"
                          disabled={index === 0}
                          aria-label={$_('adminTemplateEditor.moveUpLabel', {
                            values: { question },
                          })}
                          onclick={() => move(side, index, -1)}>↑</button
                        >
                        <button
                          type="button"
                          class="btn btn-ghost"
                          data-action="down"
                          disabled={index === draft[side].length - 1}
                          aria-label={$_('adminTemplateEditor.moveDownLabel', {
                            values: { question },
                          })}
                          onclick={() => move(side, index, 1)}>↓</button
                        >
                        <button
                          type="button"
                          class="btn btn-ghost"
                          data-action="remove"
                          aria-label={$_('adminTemplateEditor.removeLabel', {
                            values: { question },
                          })}
                          onclick={(event) => remove(side, index, event)}
                          >{$_('adminTemplateEditor.remove')}</button
                        >
                      </div>
                    </div>

                    {#if block.kind === 'custom'}
                      {@const titleError = errorAt(`${path}/title`)}
                      {@const labelError = errorAt(`${path}/field/label`)}
                      <div class="field">
                        <label for="{path}-title"
                          >{$_('adminTemplateEditor.questionTitle')}</label
                        >
                        <input
                          id="{path}-title"
                          class="input"
                          bind:value={block.title}
                          oninput={edited}
                          aria-invalid={titleError !== null}
                          aria-describedby="{path}-title-error"
                        />
                        <p id="{path}-title-error" class="field-error">
                          {titleError ?? ''}
                        </p>
                      </div>
                      <div class="field">
                        <label for="{path}-type"
                          >{$_('adminTemplateEditor.answerType')}</label
                        >
                        <select
                          id="{path}-type"
                          class="input"
                          value={block.field.type}
                          onchange={(event) =>
                            changeType(
                              side,
                              index,
                              event.currentTarget.value as FieldType,
                            )}
                        >
                          {#each FIELD_TYPES as type (type)}
                            <option value={type}
                              >{$_(`adminTemplateEditor.type.${type}`)}</option
                            >
                          {/each}
                        </select>
                      </div>
                      <div class="field">
                        <label for="{path}-label"
                          >{$_('adminTemplateEditor.fieldLabel')}</label
                        >
                        <input
                          id="{path}-label"
                          class="input"
                          value={block.field.label ?? ''}
                          oninput={(event) =>
                            setLabel(side, index, event.currentTarget.value)}
                          aria-invalid={labelError !== null}
                          aria-describedby="{path}-label-error"
                        />
                        <p id="{path}-label-error" class="field-error">
                          {labelError ?? ''}
                        </p>
                      </div>
                      {#if block.field.options}
                        <fieldset class="options">
                          <legend>{$_('adminTemplateEditor.options')}</legend>
                          {#each block.field.options as option, optionIndex (option.value)}
                            {@const optionError = errorAt(
                              `${path}/field/options/${optionIndex}/label`,
                            )}
                            {@const number = optionIndex + 1}
                            <div class="option-row">
                              <input
                                class="input"
                                aria-label={$_(
                                  'adminTemplateEditor.optionLabel',
                                  {
                                    values: { number },
                                  },
                                )}
                                bind:value={option.label}
                                oninput={edited}
                                aria-invalid={optionError !== null}
                                aria-describedby="{path}-option-{number}-error"
                              />
                              <button
                                type="button"
                                class="btn btn-ghost"
                                disabled={block.field.options.length <=
                                  MIN_OPTIONS}
                                aria-label={$_(
                                  'adminTemplateEditor.removeChoiceLabel',
                                  { values: { number } },
                                )}
                                onclick={() =>
                                  removeOption(side, index, optionIndex)}
                                >{$_('adminTemplateEditor.remove')}</button
                              >
                            </div>
                            <p
                              id="{path}-option-{number}-error"
                              class="field-error"
                            >
                              {optionError ?? ''}
                            </p>
                          {/each}
                          <button
                            type="button"
                            class="btn btn-secondary"
                            data-action="add-option"
                            disabled={block.field.options.length >= MAX_OPTIONS}
                            onclick={() => addOption(side, index)}
                            >{$_('adminTemplateEditor.addOption')}</button
                          >
                        </fieldset>
                      {/if}
                    {/if}
                  </li>
                {/each}
              </ol>

              <div class="add-row">
                <button
                  type="button"
                  class="btn btn-secondary"
                  disabled={full}
                  onclick={() => addCustom(side)}
                  >{$_('adminTemplateEditor.addCustom')}</button
                >
                {#if builtinsLeft.length > 0}
                  <!-- A select plus its own button, not add-on-change: arrowing
                     through a closed select fires change in some browsers. -->
                  <select
                    class="input add-builtin"
                    aria-label={$_('adminTemplateEditor.addStandard')}
                    disabled={full}
                    bind:value={builtinChoice[side]}
                  >
                    <option value=""
                      >{$_('adminTemplateEditor.addStandard')}</option
                    >
                    {#each builtinsLeft as questionId (questionId)}
                      <option value={questionId}
                        >{$_(builtinQuestionTitleKey(side, questionId))}</option
                      >
                    {/each}
                  </select>
                  <button
                    type="button"
                    class="btn btn-secondary"
                    disabled={full || builtinChoice[side] === ''}
                    onclick={() => addBuiltin(side)}
                    >{$_('adminTemplateEditor.addStandardButton')}</button
                  >
                {/if}
              </div>
            </section>
          {/each}
        {/if}
      </fieldset>

      <div class="notes text-muted">
        <p>{$_('adminTemplateEditor.changesNote')}</p>
        <p>{$_('adminTemplateEditor.reportNote')}</p>
        <p>{$_('adminTemplateEditor.periodicityNote')}</p>
      </div>

      {@const listedErrors = drawable ? unshownErrors : definitionErrors}
      {#if listedErrors.length > 0}
        <!-- Not a live region: it changes with every keystroke. -->
        <div class="field-error">
          <p>
            {drawable
              ? $_('adminTemplateEditor.otherErrors')
              : $_('adminTemplateEditor.allErrors')}
          </p>
          <ul>
            {#each listedErrors as error, index (index)}
              {@const location = errorLocation(error.path)}
              <li>
                {errorMessage(error)}
                {#if location}
                  ({location.number === null
                    ? $_(`adminTemplateEditor.side.${location.side}`)
                    : $_('adminTemplateEditor.location', {
                        values: {
                          side: $_(`adminTemplateEditor.side.${location.side}`),
                          number: location.number,
                        },
                      })})
                {/if}
              </li>
            {/each}
          </ul>
        </div>
      {/if}
      {#if conflict}
        <div class="banner-error conflict" role="alert">
          <p>{$_(`adminTemplateEditor.conflict.${conflict.code}`)}</p>
          <button
            type="button"
            class="btn btn-secondary"
            data-action="reload"
            disabled={reloading}
            onclick={reload}>{$_('adminTemplateEditor.reload')}</button
          >
        </div>
      {/if}
      {#if reloadError}
        <p class="banner-error" role="alert">{reloadError}</p>
      {/if}
      {#if saveError}
        <p class="banner-error" role="alert">{saveError}</p>
      {/if}
      {#if savedNotice}
        <p class="banner-success" role="status">
          {$_('adminTemplateEditor.saved')}
        </p>
      {/if}

      {#if !archived && drawable}
        <div class="save-row">
          <button
            type="button"
            class="btn btn-primary"
            data-action="save"
            disabled={!valid ||
              saving ||
              reloading ||
              conflict !== null ||
              capReached}
            onclick={save}
          >
            {saving
              ? $_('adminTemplateEditor.saving')
              : $_('adminTemplateEditor.save')}
          </button>
          <button
            type="button"
            class="btn btn-secondary"
            disabled={!valid}
            aria-pressed={preview}
            onclick={() => (preview = !preview)}
            >{$_('adminTemplateEditor.preview')}</button
          >
          {#if !valid}
            <!-- A new form's only blocker is usually its still-empty name,
                 whose error isn't shown until the name is touched. -->
            <p class="text-muted">
              {definitionErrors.length === 0 &&
              descriptionProblem === null &&
              !nameTouched &&
              id === null
                ? $_('adminTemplateEditor.needsName')
                : $_('adminTemplateEditor.fixErrors')}
            </p>
          {/if}
        </div>
      {/if}

      {#if preview && !archived && trimmedDefinition}
        <section class="card preview" aria-labelledby="preview-heading">
          <h2 id="preview-heading">{$_('adminTemplateEditor.preview')}</h2>
          <TemplateQuestions
            definition={trimmedDefinition}
            headingLevel={3}
            bind:answers={previewAnswers}
          />
        </section>
      {/if}

      {#if drawable}
        <section class="export" aria-labelledby="export-heading">
          <h2 id="export-heading">
            {$_('templatePortability.exportHeading')}
          </h2>
          <p class="text-muted">{$_('templatePortability.exportIntro')}</p>
          <p class="text-muted">{$_('templatePortability.shareWarning')}</p>
          <div class="save-row">
            <button
              type="button"
              class="btn btn-secondary"
              disabled={!valid}
              onclick={downloadExport}
              >{$_('templatePortability.download')}</button
            >
            <button
              type="button"
              class="btn btn-secondary"
              disabled={!valid}
              onclick={copyShareLink}
              >{$_('templatePortability.copyLink')}</button
            >
          </div>
          <CopyableLink
            status={currentShare?.link == null
              ? 'none'
              : currentShare.copied
                ? 'copied'
                : 'failed'}
            link={currentShare?.link ?? ''}
          />
          {#if currentShare?.link && currentShare.link.length > LONG_LINK_LENGTH}
            <p class="text-muted">{$_('templatePortability.longLink')}</p>
          {/if}
          {#if currentShare && currentShare.link === null}
            <p class="banner-error" role="alert">
              {$_('templatePortability.errors.shareFailed')}
            </p>
          {/if}
        </section>
      {/if}
    {/if}
  </AdminGate>
</main>

<style>
  main {
    max-width: 56rem;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 20px;
  }

  .banner-privacy {
    font-size: 13px;
    border: 1px solid var(--color-divider);
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    margin-bottom: 16px;
  }

  .editor {
    border: 0;
    padding: 0;
    margin: 0;
    min-width: 0;
  }

  .archived-card {
    margin-bottom: 16px;
  }

  .hint {
    font-size: 13px;
  }

  .field-error {
    font-size: 13px;
    color: var(--color-accent-ink);
  }

  .field-error:empty {
    display: none;
  }

  .side {
    margin: 24px 0;
  }

  .blocks {
    list-style: none;
    padding: 0;
    margin: 0 0 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .block-header {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: space-between;
    align-items: center;
  }

  .block-title {
    font-weight: var(--font-heading-weight);
    overflow-wrap: anywhere;
  }

  .block-actions,
  .option-row,
  .add-row,
  .save-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
  }

  .options {
    border: 0;
    padding: 0;
    margin: 8px 0 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  /* Same look as a .field's label, which this legend stands in for. */
  .options legend {
    font-size: 12px;
    margin-bottom: 5px;
    color: color-mix(in srgb, var(--color-text) 70%, transparent);
  }

  .options > .btn {
    align-self: flex-start;
  }

  .option-row .input {
    flex: 1 1 12rem;
  }

  .add-builtin {
    max-width: 20rem;
  }

  .notes {
    font-size: 13px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 16px 0;
  }

  .conflict {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
  }

  .save-row {
    margin: 16px 0;
  }

  .preview,
  .export {
    margin-top: 16px;
  }

  .export {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }
</style>
