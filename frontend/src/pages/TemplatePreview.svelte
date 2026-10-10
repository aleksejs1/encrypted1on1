<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { apiGet } from '../api/client';
  import { abortOnDestroy, isAbortError } from '../api/abortOnDestroy';
  import type { MeResponse } from '../api/types';
  import { fetchAdminTemplates } from '../api/adminTemplates';
  import { authState, isSessionExpiredError } from '../auth.svelte';
  import { navigate } from '../router.svelte';
  import { PATHS } from '../routes';
  import TemplateQuestions from '../admin/TemplateQuestions.svelte';
  import CopyableLink from '../admin/CopyableLink.svelte';
  import { MAX_TEMPLATES_PER_COMPANY } from '../admin/templateEditor';
  import {
    importProblemMessage,
    copyToClipboard,
    importShareFragment,
    setPendingImport,
    type ImportResult,
  } from '../admin/templatePortability';

  /**
   * A shared template's preview (GitHub issue #163), open to anyone with the
   * link, logged in or not. The template is in the link's fragment, which
   * never reaches the server; nothing here needs this tab unlocked, since a
   * template holds nothing encrypted. What it offers depends on who's
   * looking: an admin can install it (into the new-template form, where they
   * review and save it), anyone else in a company is asked to pass it on to
   * an admin, and a visitor is pointed at the login page.
   */

  /** Null while the link is being read. */
  let result = $state<ImportResult | null>(null);
  /** Who's looking: 'unknown' until that's settled, 'failed' if it couldn't be. */
  let viewer = $state<'unknown' | 'failed' | 'guest' | 'member' | 'admin'>(
    'unknown',
  );
  let atCap = $state(false);
  /** "Copy link for admin": shown as done, or the link to copy by hand. */
  let copyState = $state<'none' | 'copied' | 'failed'>('none');
  /** Bumped by every read, so a slower earlier one can't overwrite it. */
  let readRun = 0;

  const readAbort = abortOnDestroy();

  async function readLink(): Promise<void> {
    const run = ++readRun;
    result = null;
    copyState = 'none';
    let read: ImportResult;
    try {
      read = await importShareFragment(
        window.location.hash.slice(1),
        $_('templatePortability.defaultName'),
      );
    } catch {
      read = { ok: false, problem: { code: 'link' } };
    }
    if (run === readRun) result = read;
  }

  // Another link pasted into this tab's address bar changes only the
  // fragment, which doesn't reload the page.
  onMount(() => {
    void readLink();
    const onHashChange = (): void => void readLink();
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  });

  // Asked of the server once App.svelte's checkAuth() has settled, and again
  // whenever the tab logs in or out: its own /api/me tells a visitor (401)
  // from a member or an admin, and a failure from either, which checkAuth()'s
  // outcome alone can't (it reads any failure but a 401 as logged out).
  $effect(() => {
    if (!authState.checked) return;
    void authState.authenticated;
    untrack(() => void loadViewer());
  });

  /** Bumped by every check, so a slower earlier one can't overwrite it. */
  let viewerRun = 0;

  async function loadViewer(): Promise<void> {
    const run = ++viewerRun;
    viewer = 'unknown';
    atCap = false;
    try {
      const me = await apiGet<MeResponse>('/api/me', { signal: readAbort });
      if (run !== viewerRun) return;
      if (!me.isAdmin) {
        viewer = 'member';
        return;
      }
      viewer = 'admin';
      const templates = await fetchAdminTemplates(readAbort);
      if (run === viewerRun) {
        atCap = templates.length >= MAX_TEMPLATES_PER_COMPANY;
      }
    } catch (error) {
      if (isAbortError(error) || run !== viewerRun) return;
      // Logged out. Not passed on to the rest of the tab (markSessionExpired()
      // would re-run the effect above, and this page needs no more than its
      // own answer); the next authenticated page finds out for itself.
      if (isSessionExpiredError(error)) {
        viewer = 'guest';
        return;
      }
      // An admin whose library check failed can still install: the editor
      // (and the server) refuse a template over the limit.
      if (viewer !== 'admin') viewer = 'failed';
    }
  }

  function install(): void {
    if (result === null || !result.ok) return;
    setPendingImport(result.template);
    navigate(PATHS.adminTemplateNew);
  }

  async function copyLink(): Promise<void> {
    copyState = 'none';
    try {
      await copyToClipboard(Promise.resolve(window.location.href));
      copyState = 'copied';
    } catch {
      // No clipboard access (an http:// instance, a denied permission).
      copyState = 'failed';
    }
  }
</script>

<main>
  <p class="eyebrow text-muted">{$_('templatePreview.eyebrow')}</p>
  {#if result === null}
    <p class="text-muted">{$_('common.loading')}</p>
  {:else if !result.ok}
    <h1>{$_('templatePreview.invalidTitle')}</h1>
    <p class="banner-error" role="alert">
      {importProblemMessage(result.problem, $_)}
    </p>
  {:else}
    {@const { template } = result}
    <h1>{template.name}</h1>
    {#if template.description}
      <p class="description">{template.description}</p>
    {/if}
    <p class="text-muted">{$_('templatePreview.intro')}</p>
    <!-- Everything above comes from the link, which anyone can write. -->
    <p class="text-muted">{$_('templatePreview.authorNote')}</p>

    <div class="card cta">
      {#if viewer === 'admin'}
        <p>{$_('templatePreview.adminHint')}</p>
        {#if atCap}
          <p class="text-muted">
            {$_('adminTemplates.atCap', {
              values: { max: MAX_TEMPLATES_PER_COMPANY },
            })}
          </p>
        {/if}
        <button
          type="button"
          class="btn btn-primary"
          disabled={atCap}
          onclick={install}>{$_('templatePreview.install')}</button
        >
      {:else if viewer === 'member'}
        <p>{$_('templatePreview.memberHint')}</p>
        <button type="button" class="btn btn-secondary" onclick={copyLink}
          >{$_('templatePreview.copyForAdmin')}</button
        >
        <CopyableLink status={copyState} link={window.location.href} />
      {:else if viewer === 'failed'}
        <p class="banner-error" role="alert">
          {$_('templatePreview.viewerError')}
        </p>
        <button
          type="button"
          class="btn btn-secondary"
          onclick={() => void loadViewer()}
          >{$_('templatePreview.retry')}</button
        >
      {:else if viewer === 'guest'}
        <p>{$_('templatePreview.guestHint')}</p>
        <a class="btn btn-primary" href="/">{$_('templatePreview.logIn')}</a>
      {:else}
        <p class="text-muted">{$_('common.loading')}</p>
      {/if}
    </div>

    <section aria-label={$_('templatePreview.questions')}>
      <TemplateQuestions definition={template.definition} headingLevel={2} />
    </section>
  {/if}
</main>

<style>
  main {
    max-width: 56rem;
    width: 100%;
    margin: 0 auto;
    padding: 32px 24px 60px;
  }

  .eyebrow {
    font-size: 13px;
    margin-bottom: 4px;
  }

  h1 {
    font-size: 28px;
    margin-bottom: 12px;
    overflow-wrap: anywhere;
  }

  .description {
    overflow-wrap: anywhere;
    margin-bottom: 8px;
  }

  .cta {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    margin: 16px 0 24px;
  }
</style>
