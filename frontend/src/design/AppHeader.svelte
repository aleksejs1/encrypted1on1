<script lang="ts">
  import { _ } from 'svelte-i18n';
  import Logo from './Logo.svelte';
  import ThemeToggle from './ThemeToggle.svelte';
  import LanguageSwitcher from '../i18n/LanguageSwitcher.svelte';
  import { authState, logOut } from '../auth.svelte';
  import { routerState, navigate } from '../router.svelte';
  import { ensureUnlocked } from '../crypto/identity';

  let isAdmin = $state(false);
  let email = $state<string | null>(null);
  let loggingOut = $state(false);

  async function handleLogout(): Promise<void> {
    loggingOut = true;
    await logOut();
    loggingOut = false;
    navigate('/');
  }

  $effect(() => {
    if (!authState.authenticated) {
      email = null;
      return;
    }
    ensureUnlocked().then((identity) => {
      isAdmin = identity.isAdmin;
      email = identity.email;
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
  {#if email}<span class="user-email text-muted">{email}</span>{/if}
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
</style>
