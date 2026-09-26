<script lang="ts">
  import Activate from './pages/Activate.svelte';
  import AnketaList from './pages/AnketaList.svelte';
  import CreateAnketa from './pages/CreateAnketa.svelte';
  import AnketaPage from './pages/Anketa.svelte';
  import Login from './pages/Login.svelte';
  import UnlockTab from './pages/UnlockTab.svelte';
  import ForgotPassword from './pages/ForgotPassword.svelte';
  import ResetPassword from './pages/ResetPassword.svelte';
  import Signup from './pages/Signup.svelte';
  import CreateCompany from './pages/CreateCompany.svelte';
  import Report from './pages/Report.svelte';
  import AccountSettings from './pages/AccountSettings.svelte';
  import NotFound from './pages/NotFound.svelte';
  import AdminPanel from './admin/AdminPanel.svelte';
  import AdminReports from './admin/AdminReports.svelte';
  import AdminInvites from './admin/AdminInvites.svelte';
  import PlatformAdminPanel from './admin/PlatformAdminPanel.svelte';
  import LanguageSwitcher from './i18n/LanguageSwitcher.svelte';
  import AppHeader from './design/AppHeader.svelte';
  import AppFooter from './design/AppFooter.svelte';
  import { onMount } from 'svelte';
  import { _ } from 'svelte-i18n';
  import { routerState } from './router.svelte';
  import { authState, checkAuth } from './auth.svelte';
  import { ensureUnlocked, loggedInUserId } from './crypto/identity.svelte';
  import { deriveNotesBackupKey } from './crypto/privateNotes';
  import { discardUnopenableNotesBackups } from './anketa/notesBackup';
  import { refreshNotesUnloadWarning } from './anketa/notesUnloadWarning';
  import {
    PATHS,
    MIGRATED_AUTHED_PATHS,
    ACTIVATION_PATTERN,
    RESET_PASSWORD_PATTERN,
    ANKETA_PATTERN,
    isKnownPath,
  } from './routes';

  // checkAuth() (auth.svelte.ts) resolves both authState.authenticated and,
  // from the same /api/me response, authState.unlockStatus — see its own
  // docblock. A same-tab relogin after logOut() goes through
  // markAuthenticated() instead (Login/Activate/ResetPassword.svelte),
  // which resolves unlockStatus directly; this only ever needs to run once,
  // at mount — onMount (unlike an $effect) doesn't track reactive reads
  // inside it, so checkAuth()'s synchronous read of getGeneration() can't
  // re-trigger this when an unauthenticated 401 triggers
  // markSessionExpired() -> invalidateIdentity() (generation++).
  onMount(() => {
    // checkAuth() re-throws any unexpected (non-session-expired) error after
    // already setting authState.checked, so this tab still renders correctly
    // either way — but nothing else here awaits/catches it, so the rejection
    // itself needs handling to avoid a silent unhandled promise rejection.
    checkAuth().catch((error: unknown) => {
      console.error(error);
    });
  });

  // Private notes left unsaved in this tab's backup warn on closing it, on
  // every page and after a refresh, for the logged-in user only
  // (anketa/notesUnloadWarning.ts).
  $effect(() => {
    const userId = loggedInUserId();
    refreshNotesUnloadWarning(userId);
    if (userId === null) return;
    // Backups under the keypair from before a forgotten-password reset can
    // never be restored: dropped, so they can't keep the warning on.
    void ensureUnlocked()
      .then((identity) => deriveNotesBackupKey(identity.privateKey))
      .then((backupKey) => discardUnopenableNotesBackups(userId, backupKey))
      .then(() => refreshNotesUnloadWarning(loggedInUserId()))
      .catch(() => {});
  });

  const activationMatch = $derived(routerState.path.match(ACTIVATION_PATTERN));
  const anketaMatch = $derived(routerState.path.match(ANKETA_PATTERN));
  const resetPasswordMatch = $derived(
    routerState.path.match(RESET_PASSWORD_PATTERN),
  );
  // NotFound is the terminal fallback for an authenticated, unlocked user on
  // an unrecognized path — see the routing chain below. It deliberately does
  // NOT gate the auth/unlock loading states above it (checked/authenticated/
  // unlockStatus): a locked tab or a not-yet-authenticated visitor must
  // still reach UnlockTab/Login from ANY path, known or not, rather than
  // dead-ending on a 404 with no way to unlock or log in.
  const knownPath = $derived(isKnownPath(routerState.path));

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
  // The last clause covers an unrecognized path once authenticated —
  // NotFound itself once unlocked, but also UnlockTab/the loading state on
  // the way there — the same header treatment MIGRATED_AUTHED_PATHS already
  // gives every *known* authenticated path regardless of lock state, rather
  // than only once unlocked.
  const showAppHeader = $derived(
    !!activationMatch ||
      !!resetPasswordMatch ||
      routerState.path === PATHS.forgotPassword ||
      routerState.path === PATHS.signup ||
      routerState.path === PATHS.createCompany ||
      !authState.authenticated ||
      !!anketaMatch ||
      MIGRATED_AUTHED_PATHS.includes(routerState.path) ||
      (authState.authenticated && !knownPath),
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
  {:else if authState.unlockStatus === 'unknown'}
    <p>{$_('common.loading')}</p>
  {:else if authState.unlockStatus === 'locked'}
    <UnlockTab />
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
  {:else if routerState.path === PATHS.adminInvites}
    <AdminInvites />
  {:else if routerState.path === PATHS.platformAdmin}
    <PlatformAdminPanel />
  {:else if routerState.path === PATHS.anketaList}
    <AnketaList />
  {:else}
    <NotFound />
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
