<script lang="ts">
  import Activate from './pages/Activate.svelte';
  import AnketaList from './pages/AnketaList.svelte';
  import CreateAnketa from './pages/CreateAnketa.svelte';
  import AnketaPage from './pages/Anketa.svelte';
  import Login from './pages/Login.svelte';
  import ForgotPassword from './pages/ForgotPassword.svelte';
  import ResetPassword from './pages/ResetPassword.svelte';
  import Signup from './pages/Signup.svelte';
  import CreateCompany from './pages/CreateCompany.svelte';
  import Report from './pages/Report.svelte';
  import AccountSettings from './pages/AccountSettings.svelte';
  import AdminPanel from './admin/AdminPanel.svelte';
  import AdminReports from './admin/AdminReports.svelte';
  import PlatformAdminPanel from './admin/PlatformAdminPanel.svelte';
  import LanguageSwitcher from './i18n/LanguageSwitcher.svelte';
  import AppHeader from './design/AppHeader.svelte';
  import AppFooter from './design/AppFooter.svelte';
  import { _ } from 'svelte-i18n';
  import { routerState } from './router.svelte';
  import { authState, checkAuth } from './auth.svelte';

  $effect(() => {
    checkAuth();
  });

  const activationMatch = $derived(
    routerState.path.match(/^\/activate\/(.+)$/),
  );
  const anketaMatch = $derived(routerState.path.match(/^\/anketas\/([^/]+)$/));
  const resetPasswordMatch = $derived(
    routerState.path.match(/^\/reset-password\/(.+)$/),
  );

  /**
   * Named once and reused by both `showAppHeader` and the routing chain
   * below, rather than repeating the literal strings in each place — two
   * independently-typed copies of the same path is exactly what let a typo,
   * or a forgotten update to one side, silently show/hide the header on the
   * wrong route.
   */
  const PATHS = {
    forgotPassword: '/forgot-password',
    signup: '/signup',
    createCompany: '/create-company',
    anketaList: '/',
    report: '/report',
    admin: '/admin',
    adminReports: '/admin/reports',
    account: '/account',
    platformAdmin: '/platform-admin',
  } as const;

  // The redesigned header started with Login/Activate (Phase 8a), then covered
  // AnketaList/CreateAnketa/Report/AdminPanel (Phase 8b), and now Anketa.svelte
  // too (Phase 8c) — every authenticated page now uses AppHeader, closing out
  // the design-system rollout. `anketaMatch` already covers both
  // `/anketas/new` and `/anketas/:id`, so it folds into this check directly
  // rather than needing its own entry in MIGRATED_AUTHED_PATHS. Password reset
  // (forgot-password/reset-password) is unauthenticated the same way
  // Login/Activate are, and gets the same treatment as activationMatch.
  // /platform-admin (Phase C) is deliberately included here — same authenticated-page
  // header treatment as every other page — but AppHeader itself never links to it
  // (see PlatformAdminController's own docblock: reachable by URL, not discoverable).
  const MIGRATED_AUTHED_PATHS: string[] = [
    PATHS.anketaList,
    PATHS.report,
    PATHS.admin,
    PATHS.adminReports,
    PATHS.account,
    PATHS.platformAdmin,
  ];
  const showAppHeader = $derived(
    !!activationMatch ||
      !!resetPasswordMatch ||
      routerState.path === PATHS.forgotPassword ||
      routerState.path === PATHS.signup ||
      routerState.path === PATHS.createCompany ||
      !authState.authenticated ||
      !!anketaMatch ||
      MIGRATED_AUTHED_PATHS.includes(routerState.path),
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
  {:else if routerState.path === PATHS.forgotPassword}
    <ForgotPassword />
  {:else if routerState.path === PATHS.signup}
    <Signup />
  {:else if routerState.path === PATHS.createCompany}
    <CreateCompany />
  {:else if resetPasswordMatch}
    <ResetPassword token={resetPasswordMatch[1]} />
  {:else if !authState.checked}
    <p>{$_('common.loading')}</p>
  {:else if !authState.authenticated}
    <Login />
  {:else if routerState.path === '/anketas/new'}
    <CreateAnketa />
  {:else if anketaMatch}
    <AnketaPage id={anketaMatch[1]} />
  {:else if routerState.path === PATHS.report}
    <Report />
  {:else if routerState.path === PATHS.account}
    <AccountSettings />
  {:else if routerState.path === PATHS.admin}
    <AdminPanel />
  {:else if routerState.path === PATHS.adminReports}
    <AdminReports />
  {:else if routerState.path === PATHS.platformAdmin}
    <PlatformAdminPanel />
  {:else}
    <AnketaList />
  {/if}

  <AppFooter />
</div>

<style>
  .app-shell {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
  }
</style>
