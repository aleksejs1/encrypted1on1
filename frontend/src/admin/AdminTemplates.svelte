<script lang="ts">
  import { onMount } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiPut, ApiError } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import { formatDisplayDate } from '../datePreference.svelte';
  import type { AdminTemplate } from '../api/types';
  import AdminTabStrip from './AdminTabStrip.svelte';
  import AdminGate from './AdminGate.svelte';
  import { fetchAdminTemplates } from '../api/adminTemplates';
  import { PATHS, adminTemplatePath } from '../routes';
  import { MAX_TEMPLATES_PER_COMPANY } from './templateEditor';
  import {
    beginAction,
    findRow,
    ignoreHeldEnter,
    refocus,
  } from '../anketa/keepFocus';

  /**
   * The company's template library (GitHub issue #143, #133 §6): active
   * templates first, then archived ones, as the server orders them. Archiving
   * hides a template from every picker; existing anketas keep their version.
   */

  let templates = $state<AdminTemplate[]>([]);
  let loaded = $state(false);
  let loadError = $state<string | null>(null);
  let actionError = $state<string | null>(null);
  /** An archive or restore in flight: every row's button waits for it. */
  let busy = $state(false);
  let list = $state<HTMLElement>();

  const atCap = $derived(templates.length >= MAX_TEMPLATES_PER_COMPANY);

  // Cancels loadTemplates()'s fetch on unmount — see GitHub issue #95.
  const readAbort = abortOnDestroy();

  /** The list, or null after showing why it couldn't be loaded. */
  async function fetchTemplates(): Promise<AdminTemplate[] | null> {
    try {
      return await fetchAdminTemplates(readAbort);
    } catch (error) {
      if (isAbortError(error)) return null;
      const message =
        error instanceof ApiError
          ? error.message
          : $_('adminTemplates.errorLoad');
      // The first load has no list to keep; a refresh after an action keeps
      // the one on screen and says so next to it.
      if (loaded) actionError = message;
      else loadError = message;
      return null;
    }
  }

  // Back to this page from the editor may restore it from the back-forward
  // cache, as it was before a template was created, edited or archived there:
  // load the list again then.
  onMount(() => {
    const onPageShow = (event: PageTransitionEvent): void => {
      if (!event.persisted) return;
      // Whatever failed before leaving is stale now.
      actionError = null;
      void loadTemplates();
    };
    window.addEventListener('pageshow', onPageShow);
    return () => window.removeEventListener('pageshow', onPageShow);
  });

  async function loadTemplates(): Promise<void> {
    const fetched = await fetchTemplates();
    if (fetched === null) return;
    templates = fetched;
    loaded = true;
    loadError = null;
  }

  async function setArchived(
    template: AdminTemplate,
    archived: boolean,
  ): Promise<void> {
    busy = true;
    actionError = null;
    // Every button is disabled (and the pressed one blurred) meanwhile, and
    // the row's Archive/Restore button is swapped for the other one.
    const started = beginAction();
    try {
      const result = await apiPut<{ archivedAt: string | null }>(
        `/api/admin/templates/${template.id}/archived`,
        { archived },
      );
      // Shown at once, so a failed refresh below can't leave the row
      // claiming the old state.
      template.archivedAt = result.archivedAt;
      await loadTemplates();
    } catch (error) {
      actionError =
        error instanceof ApiError
          ? error.message
          : $_('adminTemplates.errorArchive');
    } finally {
      busy = false;
    }
    // The row's Archive/Restore button (whichever it shows now), also after
    // a failure, when it's the pressed one again.
    void refocus(
      findRow(list, 'data-template-id', template.id),
      '[data-action="archive"]',
      { startedOn: started },
    );
  }
</script>

<main>
  <h1>{$_('adminTemplates.title')}</h1>

  <AdminGate onReady={loadTemplates} errorLoadKey="adminTemplates.errorLoad">
    <AdminTabStrip active="templates" />

    <p class="text-muted intro">{$_('adminTemplates.intro')}</p>

    {#if loadError}
      <p class="banner-error" role="alert">{loadError}</p>
    {:else if loaded}
      {#if actionError}
        <p class="banner-error" role="alert">{actionError}</p>
      {/if}
      <div class="actions">
        {#if atCap}
          <p class="text-muted">
            {$_('adminTemplates.atCap', {
              values: { max: MAX_TEMPLATES_PER_COMPANY },
            })}
          </p>
        {:else}
          <a class="btn btn-primary" href={PATHS.adminTemplateNew}
            >{$_('adminTemplates.new')}</a
          >
        {/if}
      </div>

      {#if templates.length === 0}
        <p class="text-muted">{$_('adminTemplates.empty')}</p>
      {:else}
        <!-- ignoreHeldEnter: focus moves from Archive to the row's Restore
             (and back), which a held Enter would press over and over (#151). -->
        <ul
          class="template-list"
          role="list"
          bind:this={list}
          onkeydowncapture={ignoreHeldEnter}
        >
          {#each templates as template (template.id)}
            <li
              class="card template-row"
              class:archived={template.archivedAt}
              data-template-id={template.id}
            >
              <div class="template-text">
                <p class="template-name">
                  {template.name}
                  {#if template.archivedAt}
                    <span class="tag tag-neutral"
                      >{$_('adminTemplates.archivedTag')}</span
                    >
                  {/if}
                </p>
                {#if template.description}
                  <p class="text-muted template-description">
                    {template.description}
                  </p>
                {/if}
                <p class="text-muted template-meta">
                  {$_('adminTemplates.edited', {
                    values: { date: formatDisplayDate(template.updatedAt) },
                  })}
                </p>
              </div>
              <div class="template-actions">
                <a
                  class="btn btn-secondary"
                  href={adminTemplatePath(template.id)}
                  aria-label={$_(
                    template.archivedAt
                      ? 'adminTemplates.viewLabel'
                      : 'adminTemplates.editLabel',
                    { values: { name: template.name } },
                  )}
                  >{template.archivedAt
                    ? $_('adminTemplates.view')
                    : $_('adminTemplates.edit')}</a
                >
                {#if template.archivedAt}
                  <button
                    type="button"
                    class="btn btn-ghost"
                    data-action="archive"
                    disabled={busy}
                    aria-label={$_('adminTemplates.restoreLabel', {
                      values: { name: template.name },
                    })}
                    onclick={() => setArchived(template, false)}
                    >{$_('adminTemplates.restore')}</button
                  >
                {:else}
                  <button
                    type="button"
                    class="btn btn-ghost"
                    data-action="archive"
                    disabled={busy}
                    aria-label={$_('adminTemplates.archiveLabel', {
                      values: { name: template.name },
                    })}
                    onclick={() => setArchived(template, true)}
                    >{$_('adminTemplates.archive')}</button
                  >
                {/if}
              </div>
            </li>
          {/each}
        </ul>
        <p class="text-muted archive-note">
          {$_('adminTemplates.archiveNote')}
        </p>
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

  .intro,
  .actions {
    margin-bottom: 16px;
  }

  .template-list {
    list-style: none;
    padding: 0;
    margin: 0 0 12px;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .template-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: space-between;
    align-items: flex-start;
  }

  .template-row.archived {
    opacity: 0.75;
  }

  .template-text {
    min-width: 0;
    flex: 1 1 20rem;
  }

  .template-name {
    font-weight: var(--font-heading-weight);
    overflow-wrap: anywhere;
  }

  .template-description {
    overflow-wrap: anywhere;
  }

  .template-meta {
    font-size: 13px;
  }

  .template-actions {
    display: flex;
    gap: 8px;
  }
</style>
