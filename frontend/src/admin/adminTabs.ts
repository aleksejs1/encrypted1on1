import { PATHS } from '../routes';

const TABS = [
  { key: 'users', href: PATHS.admin, labelKey: 'admin.usersTab' },
  { key: 'reports', href: PATHS.adminReports, labelKey: 'admin.reportsTab' },
  { key: 'invites', href: PATHS.adminInvites, labelKey: 'admin.invitesTab' },
  {
    key: 'templates',
    href: PATHS.adminTemplates,
    labelKey: 'admin.templatesTab',
  },
] as const;

// Only the Cloud deployment has plans (GitHub issue #286).
const PLAN_TAB = {
  key: 'plan',
  href: PATHS.adminPlan,
  labelKey: 'admin.planTab',
} as const;

export type AdminTab = (typeof TABS)[number] | typeof PLAN_TAB;

/** The admin panel's tabs, in order. */
export function adminTabs(cloudMode: boolean): readonly AdminTab[] {
  return cloudMode ? [...TABS, PLAN_TAB] : TABS;
}
