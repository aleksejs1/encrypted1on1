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

  // The redesigned header started with Login/Activate (Phase 8a) and now also
  // covers AnketaList/CreateAnketa/Report/AdminPanel (Phase 8b) — the four
  // routes below. Anketa.svelte (anketa detail) keeps today's bare
  // LanguageSwitcher until Phase 8c migrates it too, same reasoning as 8a:
  // swapping AppHeader in globally now would double up with its own
  // still-unstyled inline layout.
  const MIGRATED_AUTHED_PATHS = ['/', '/anketas/new', '/report', '/admin'];
  const showAppHeader = $derived(
    !!activationMatch || !authState.authenticated || MIGRATED_AUTHED_PATHS.includes(routerState.path),
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
