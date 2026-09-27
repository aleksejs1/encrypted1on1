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
  adminTemplates: '/admin/templates',
  adminTemplateNew: '/admin/templates/new',
  account: '/account',
  platformAdmin: '/platform-admin',
} as const;

export const MIGRATED_AUTHED_PATHS: string[] = [
  PATHS.anketaList,
  PATHS.report,
  PATHS.admin,
  PATHS.adminReports,
  PATHS.adminInvites,
  PATHS.adminTemplates,
  PATHS.account,
  PATHS.platformAdmin,
];

export const ACTIVATION_PATTERN = /^\/activate\/(.+)$/;
export const RESET_PASSWORD_PATTERN = /^\/reset-password\/(.+)$/;
// Also matches /anketas/new — App.svelte's routing chain checks that literal
// path first, before falling through to this pattern for the id case.
export const ANKETA_PATTERN = /^\/anketas\/([^/]+)$/;
// Also matches /admin/templates/new — App.svelte checks that literal path
// first, like /anketas/new above (GitHub issue #143).
export const ADMIN_TEMPLATE_PATTERN = /^\/admin\/templates\/([^/]+)$/;

/**
 * One template's editor page, the path ADMIN_TEMPLATE_PATTERN matches. Ids
 * are UUIDs, so nothing needs encoding (and nothing decodes the match).
 */
export function adminTemplatePath(id: string): string {
  return `${PATHS.adminTemplates}/${id}`;
}

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
    ANKETA_PATTERN.test(path) ||
    ADMIN_TEMPLATE_PATTERN.test(path)
  );
}
