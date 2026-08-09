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

  // The redesigned header (Phase 8a) is only wired up for the pages this phase
  // actually restyles — Login and Activate, both effectively "not authenticated"
  // contexts. Authenticated pages keep today's bare LanguageSwitcher unchanged
  // until Phase 8b migrates them to AppHeader too — swapping it in globally now
  // would either drop language switching from unmigrated pages or double up
  // with their own still-unstyled inline headers.
  const showAppHeader = $derived(!!activationMatch || !authState.authenticated);
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
