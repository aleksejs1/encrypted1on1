<script lang="ts">
  import { _ } from 'svelte-i18n';
  import Logo from './Logo.svelte';
  import ThemeToggle from './ThemeToggle.svelte';
  import LanguageSwitcher from '../i18n/LanguageSwitcher.svelte';
  import { authState, logOut } from '../auth.svelte';
  import { routerState, navigate } from '../router.svelte';
  import { ensureUnlocked } from '../crypto/identity';
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
    if (!authState.authenticated) {
      email = null;
      isDemo = false;
      return;
    }
    ensureUnlocked().then((identity) => {
      isAdmin = identity.isAdmin;
      email = identity.email;
      isDemo = identity.isDemo;
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
