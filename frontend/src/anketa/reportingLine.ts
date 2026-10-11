/**
 * The create form's use of the company org structure (GitHub issue #269, part of
 * #265): what the chosen colleague is to me, and whether the role I clicked says the
 * opposite. Facts and a warning only. Nothing here may ever select a role or stop a
 * 1:1 from being created; see docs/decisions/2026-10-10-explicit-role-selection.md.
 */
import type { Side } from './questions';

/** `GET /api/me/org`, as much of it as the form reads. */
export interface MyOrg {
  manager: { id: string } | null;
  directReports: { id: string }[];
}

/** What a colleague is to me: my manager, my direct report, or neither. */
export type ReportingLine = 'manager' | 'directReport' | null;

/**
 * Null without an org structure (not loaded, or the company has none). Also null for
 * someone who is both my manager and my report: two admins at once can store such a
 * loop, and then neither fact is one to show.
 */
export function reportingLine(
  org: MyOrg | null,
  colleagueId: string,
): ReportingLine {
  if (org === null || colleagueId === '') return null;
  const isManager = org.manager?.id === colleagueId;
  const isReport = org.directReports.some((r) => r.id === colleagueId);
  if (isManager === isReport) return null;
  return isManager ? 'manager' : 'directReport';
}

/** Which way the clicked role contradicts the reporting line, if it does. */
export type RoleContradiction = 'leadingMyManager' | 'ledByMyReport' | null;

/**
 * `role` is mine in the 1:1 ('manager' means I lead it). Leading a 1:1 with my own
 * manager, or being led by my own report, is allowed and sometimes meant; it is also
 * exactly how a pair's roles end up inverted (GitHub issue #250).
 */
export function roleContradiction(
  line: ReportingLine,
  role: Side | null,
): RoleContradiction {
  if (line === 'manager' && role === 'manager') return 'leadingMyManager';
  if (line === 'directReport' && role === 'employee') return 'ledByMyReport';
  return null;
}
