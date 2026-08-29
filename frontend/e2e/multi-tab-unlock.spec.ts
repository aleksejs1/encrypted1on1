import { test, expect } from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

/**
 * Regression test for a real reported bug: opening a second tab left the app
 * authenticated (the session cookie is shared across tabs) but crypto-locked
 * (the master key lives in sessionStorage, which is NOT shared across tabs —
 * see crypto/session.ts) — with nothing reconciling the two states, the
 * second tab silently misbehaved: the header lost the user's name
 * (AppHeader.svelte's ensureUnlocked().then() had no .catch, so the rejection
 * was swallowed and `email` just stayed null) and CreateAnketa.svelte showed
 * a misleading "Could not load users" error (ensureUnlocked() throwing a
 * plain Error, not an ApiError, fell into the generic-failure branch). Users
 * had no indication anything could be done about it.
 *
 * App.svelte now detects authenticated-but-locked via authState.unlockStatus
 * (checkUnlocked() in auth.svelte.ts) and routes to UnlockTab.svelte — a
 * password-only re-entry screen — instead of rendering the normal app shell.
 */
test('opening a second tab shows UnlockTab instead of a broken app, and the right password unlocks it', async ({
  browser,
}) => {
  const email = uniqueEmail('multi-tab');
  const token = createActivationLink(email);

  const ctx = await browser.newContext();
  const firstTab = await ctx.newPage();
  await firstTab.goto('/activate/' + token);
  await firstTab.locator('#act-password').fill(PASSWORD);
  await firstTab.locator('#act-confirm').fill(PASSWORD);
  await firstTab.getByRole('button', { name: 'Activate' }).click();
  await firstTab.waitForURL('/');
  await expect(firstTab.locator('.user-email')).toHaveText(email);

  // A genuinely separate tab in the same browser context: shares the session
  // cookie (real second-tab semantics), but NOT sessionStorage — unlike
  // window.open(), Playwright's context.newPage() does not inherit the
  // opener's sessionStorage, matching how a manually opened second tab
  // behaves in a real browser. Goes straight to the exact page from the
  // report (creating an anketa), not the home page.
  const secondTab = await ctx.newPage();
  await secondTab.goto('/anketas/new');

  // Authenticated (no Login form) but locked: the create-anketa form and its
  // "Could not load users" failure never render at all — UnlockTab does.
  await expect(secondTab.locator('#login-email')).toHaveCount(0);
  await expect(secondTab.getByText('Could not load users.')).toHaveCount(0);
  const unlockPassword = secondTab.locator('#unlock-password');
  await expect(unlockPassword).toBeVisible();
  // The header renders with no name and no crash while locked — not the
  // silently-vanished username from the reported bug, which looked
  // indistinguishable from "something is broken".
  await expect(secondTab.locator('.user-email')).toHaveCount(0);

  // Wrong password: stays locked, with a clear, specific error.
  await unlockPassword.fill('definitely the wrong password');
  await secondTab.getByRole('button', { name: 'Unlock' }).click();
  await expect(secondTab.getByText('Incorrect password.')).toBeVisible();
  await expect(unlockPassword).toBeVisible();

  // Correct password unlocks this tab: its own master key is re-derived and
  // stored in its own sessionStorage, independent of the first tab.
  await unlockPassword.fill(PASSWORD);
  await secondTab.getByRole('button', { name: 'Unlock' }).click();

  await expect(unlockPassword).toHaveCount(0);
  await expect(secondTab.locator('.user-email')).toHaveText(email);
  await expect(
    secondTab.getByPlaceholder('Type a name or email to search…'),
  ).toBeVisible();

  // The first tab was completely unaffected throughout.
  await expect(firstTab.locator('.user-email')).toHaveText(email);
});
