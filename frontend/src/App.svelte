<script lang="ts">
  import Activate from './pages/Activate.svelte';
  import Login from './pages/Login.svelte';

  // Hand-rolled path branching: with only two views, a router library is
  // premature — see the Phase 4 plan for why that's deferred.
  const activationMatch = window.location.pathname.match(/^\/activate\/(.+)$/);
</script>

{#if activationMatch}
  <Activate token={activationMatch[1]} />
{:else}
  <Login />
{/if}
