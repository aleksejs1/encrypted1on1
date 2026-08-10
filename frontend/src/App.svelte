<script lang="ts">
  import Activate from './pages/Activate.svelte';
  import AnketaList from './pages/AnketaList.svelte';
  import CreateAnketa from './pages/CreateAnketa.svelte';
  import AnketaPage from './pages/Anketa.svelte';
  import Login from './pages/Login.svelte';
  import Report from './pages/Report.svelte';
  import AdminPanel from './admin/AdminPanel.svelte';
  import LanguageSwitcher from './i18n/LanguageSwitcher.svelte';
  import AppHeader from './design/AppHeader.svelte';
  import { _ } from 'svelte-i18n';
  import { routerState } from './router.svelte';
  import { authState, checkAuth } from './auth.svelte';

  $effect(() => {
    checkAuth();
  });

  const activationMatch = $derived(routerState.path.match(/^\/activate\/(.+)$/));
  const anketaMatch = $derived(routerState.path.match(/^\/anketas\/([^/]+)$/));

  // The redesigned header started with Login/Activate (Phase 8a), then covered
  // AnketaList/CreateAnketa/Report/AdminPanel (Phase 8b), and now Anketa.svelte
  // too (Phase 8c) — every authenticated page now uses AppHeader, closing out
  // the design-system rollout. `anketaMatch` already covers both
  // `/anketas/new` and `/anketas/:id`, so it folds into this check directly
  // rather than needing its own entry in MIGRATED_AUTHED_PATHS.
  const MIGRATED_AUTHED_PATHS = ['/', '/report', '/admin'];
  const showAppHeader = $derived(
    !!activationMatch || !authState.authenticated || !!anketaMatch || MIGRATED_AUTHED_PATHS.includes(routerState.path),
  );
</script>

<div class="app-shell">
  {#if showAppHeader}
    <AppHeader />
  {:else}
    <LanguageSwitcher />
  {/if}

  {#if activationMatch}
    <Activate token={activationMatch[1]} />
  {:else if !authState.checked}
    <p>{$_('common.loading')}</p>
  {:else if !authState.authenticated}
    <Login />
  {:else if routerState.path === '/anketas/new'}
    <CreateAnketa />
  {:else if anketaMatch}
    <AnketaPage id={anketaMatch[1]} />
  {:else if routerState.path === '/report'}
    <Report />
  {:else if routerState.path === '/admin'}
    <AdminPanel />
  {:else}
    <AnketaList />
  {/if}
</div>

<style>
  .app-shell {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
  }
</style>
