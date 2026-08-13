import { test, expect } from '@playwright/test';
import { createActivationLink, uniqueEmail } from './helpers/provision.js';

const PASSWORD = 'correct horse battery staple 123';

/**
 * Regression test for a real bug: AuthSession::logOut() calls
 * $session->invalidate() server-side, which wipes the session-stored CSRF
 * secret backing whatever token api/client.ts has cached — without
 * resetCsrfToken() in auth.svelte.ts's logOut(), the very next state-changing
 * request in the same tab (e.g. logging back in) sent the now-stale token and
 * got a genuine 403 "Invalid CSRF token". Confirmed against the real backend
 * with curl before fixing, and confirmed this spec genuinely fails without
 * the fix (temporarily reverted it and re-ran) before keeping it.
 */
test('login, then logout, then login again in the same tab succeeds', async ({
  browser,
}) => {
  const email = uniqueEmail('csrf-relogin');
  const token = createActivationLink(email);

  const ctx = await browser.newContext();
  const page = await ctx.newPage();

  await page.goto('/activate/' + token);
  await page.locator('#act-password').fill(PASSWORD);
  await page.locator('#act-confirm').fill(PASSWORD);
  await page.getByRole('button', { name: 'Activate' }).click();
  await page.waitForURL('/');

  // Log out. The router is hand-rolled with no client-side navigation on
  // this action — the URL stays "/", only the rendered content switches to
  // the Login form based on authState.authenticated.
  await page.getByRole('button', { name: 'Log out' }).click();
  await expect(page.locator('#login-email')).toBeVisible();

  // Now log back in, in the SAME tab (same cached CSRF token before the fix) —
  // this is the exact reported bug: without resetCsrfToken() in logOut(),
  // this second login fails with a real "Invalid CSRF token" 403.
  await page.locator('#login-email').fill(email);
  await page.locator('#login-password').fill(PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();

  // Should land back on the authenticated home page (login form gone), not
  // show a CSRF error banner.
  await expect(page.locator('#login-email')).toHaveCount(0);
  await expect(page.getByRole('alert')).toHaveCount(0);
});
