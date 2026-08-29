<script lang="ts">
  import { _ } from 'svelte-i18n';
  import Logo from './Logo.svelte';
  import ThemeToggle from './ThemeToggle.svelte';
  import LanguageSwitcher from '../i18n/LanguageSwitcher.svelte';
  import { authState, logOut } from '../auth.svelte';
  import { routerState, navigate } from '../router.svelte';
  import { ensureUnlocked, getGeneration } from '../crypto/identity.svelte';
  import { displayNameState } from '../displayName.svelte';
  import { fullDisplayName } from '../userDisplay';

  let isAdmin = $state(false);
  let email = $state<string | null>(null);
  let isDemo = $state(false);
  let loggingOut = $state(false);

  const shownName = $derived(
    email !== null ? fullDisplayName(displayNameState.value, email) : null,
  );

  async function handleLogout(): Promise<void> {
    loggingOut = true;
    await logOut();
    loggingOut = false;
    navigate('/');
  }

  $effect(() => {
    // Also doubles as this call's staleness snapshot below: getGeneration()
    // is what forces this effect to re-run on a same-tab reauth as a
    // *different* identity (e.g. an Activate/ResetPassword link opened
    // while already logged in) — authenticated/unlockStatus alone can hold
    // the exact same values before and after, and Svelte skips re-running
    // an $effect whose tracked values didn't change. See getGeneration()'s
    // own docblock.
    const startedAt = getGeneration();

    if (!authState.authenticated || authState.unlockStatus !== 'unlocked') {
      email = null;
      isDemo = false;
      isAdmin = false;
      return;
    }
    ensureUnlocked()
      .then((identity) => {
        // A second reauth (a different Activate/ResetPassword link opened
        // moments after the first, in the same tab) can start a second,
        // fresher ensureUnlocked() call before this one's own /api/me round
        // trip resolves. If this call is the stale one, it must not
        // overwrite what the newer call already committed.
        if (getGeneration() !== startedAt) return;
        isAdmin = identity.isAdmin;
        email = identity.email;
        isDemo = identity.isDemo;
      })
      .catch(() => {
        if (getGeneration() !== startedAt) return;
        email = null;
        isDemo = false;
        isAdmin = false;
      });
  });

  const isHome = $derived(routerState.path === '/');
</script>

<header class="app-header">
  <div class="brand">
    <Logo size={26} />
    <span class="wordmark">encrypted1on1</span>
  </div>

  <nav class="app-nav">
    {#if authState.authenticated && isHome}
      {#if isAdmin}<a href="/admin">{$_('anketaList.admin')}</a>{/if}
      <a href="/report">{$_('anketaList.report')}</a>
      <a href="/anketas/new">{$_('anketaList.newAnketa')}</a>
    {:else if authState.authenticated}
      <a href="/">{$_('common.backToAnketas')}</a>
    {/if}
  </nav>

  <LanguageSwitcher />
  <ThemeToggle />
  {#if shownName}<span class="user-email text-muted">{shownName}</span>{/if}
  {#if authState.authenticated}
    <a href="/account" class="account-link text-muted"
      >{$_('common.accountSettings')}</a
    >
  {/if}
  {#if authState.authenticated}
    <button
      type="button"
      class="btn btn-ghost logout-btn"
      onclick={handleLogout}
      disabled={loggingOut}
    >
      {$_('common.logout')}
    </button>
  {/if}
</header>

{#if isDemo}
  <p class="demo-banner">{$_('common.demoBanner')}</p>
{/if}

<style>
  .app-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    flex-wrap: wrap;
  }

  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .wordmark {
    font-family: var(--font-heading);
    font-weight: var(--font-heading-weight);
    font-size: 17px;
  }

  .app-nav {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-right: auto;
    flex-wrap: wrap;
  }

  .app-nav a {
    color: inherit;
    text-decoration: none;
    font-size: 14px;
  }

  .app-nav a:hover {
    color: var(--color-accent-ink);
  }

  .user-email {
    font-size: 13px;
  }

  .account-link {
    font-size: 13px;
    text-decoration: none;
  }

  .account-link:hover {
    color: var(--color-accent-ink);
  }

  .logout-btn {
    padding: 4px 10px;
    font-size: 13px;
  }

  .demo-banner {
    margin: 0;
    padding: 8px 20px;
    text-align: center;
    font-size: 13px;
    background: var(--color-accent);
    color: var(--color-on-accent);
  }
</style>
