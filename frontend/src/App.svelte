<script lang="ts">
  import Activate from './pages/Activate.svelte';
  import AnketaList from './pages/AnketaList.svelte';
  import CreateAnketa from './pages/CreateAnketa.svelte';
  import AnketaPage from './pages/Anketa.svelte';
  import Login from './pages/Login.svelte';
  import Report from './pages/Report.svelte';
  import AdminPanel from './admin/AdminPanel.svelte';
  import { routerState } from './router.svelte';
  import { authState, checkAuth } from './auth.svelte';

  $effect(() => {
    checkAuth();
  });

  const activationMatch = $derived(routerState.path.match(/^\/activate\/(.+)$/));
  const anketaMatch = $derived(routerState.path.match(/^\/anketas\/([^/]+)$/));
</script>

{#if activationMatch}
  <Activate token={activationMatch[1]} />
{:else if !authState.checked}
  <p>Loading…</p>
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
