/**
 * Route path constants and matching, shared by App.svelte's routing chain,
 * its showAppHeader check, and isKnownPath below — one typed copy instead
 * of the independently-typed duplicates that used to drift between the
 * routing chain and showAppHeader (see git history around 9feab6d).
 */
export const PATHS = {
  forgotPassword: '/forgot-password',
  signup: '/signup',
  createCompany: '/create-company',
  anketaList: '/',
  report: '/report',
  admin: '/admin',
  adminReports: '/admin/reports',
  adminInvites: '/admin/invites',
  account: '/account',
  platformAdmin: '/platform-admin',
} as const;

export const MIGRATED_AUTHED_PATHS: string[] = [
  PATHS.anketaList,
  PATHS.report,
  PATHS.admin,
  PATHS.adminReports,
  PATHS.adminInvites,
  PATHS.account,
  PATHS.platformAdmin,
];

export const ACTIVATION_PATTERN = /^\/activate\/(.+)$/;
export const RESET_PASSWORD_PATTERN = /^\/reset-password\/(.+)$/;
// Also matches /anketas/new — App.svelte's routing chain checks that literal
// path first, before falling through to this pattern for the id case.
export const ANKETA_PATTERN = /^\/anketas\/([^/]+)$/;

const KNOWN_STATIC_PATHS: string[] = Object.values(PATHS);

/**
 * True for every path the routing chain in App.svelte actually renders a
 * page for. Anything else should render NotFound rather than silently
 * falling back to AnketaList/Login.
 */
export function isKnownPath(path: string): boolean {
  return (
    KNOWN_STATIC_PATHS.includes(path) ||
    ACTIVATION_PATTERN.test(path) ||
    RESET_PASSWORD_PATTERN.test(path) ||
    ANKETA_PATTERN.test(path)
  );
}
