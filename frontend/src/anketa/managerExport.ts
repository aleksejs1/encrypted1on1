/**
 * My manager in the data export (GitHub issue #270, part of #265): who I report to
 * is data about me. My direct reports are not exported: that is data about them.
 */
import { ApiError } from '../api/client';

/** `manager` is absent, not null, when it couldn't be read: null means "none". */
export interface ExportedManager {
  manager?: { email: string; displayName: string } | null;
  managerUnavailable?: true;
}

const UNAVAILABLE: ExportedManager = { managerUnavailable: true };

/**
 * `fetchOrg` asks `GET /api/me/org`, so the manager is as the app itself shows it:
 * null with none, and for one whose account is blocked.
 *
 * The export must not be lost to this one optional request: a failure marks it
 * `managerUnavailable`, like `templateUnavailable`. Except a 401 or 403, which means
 * the session is gone and is rethrown, to fail the export like its other requests.
 *
 * Builds a new object from two fields, so nothing else of the answer, the direct
 * reports most of all, can reach the export through it.
 */
export async function managerForExport(
  fetchOrg: () => Promise<unknown>,
): Promise<ExportedManager> {
  let org: unknown;
  try {
    org = await fetchOrg();
  } catch (error) {
    if (error instanceof ApiError && [401, 403].includes(error.status)) {
      throw error;
    }
    return UNAVAILABLE;
  }

  if (typeof org !== 'object' || org === null || !('manager' in org)) {
    return UNAVAILABLE;
  }
  const manager = org.manager;
  if (manager === null) return { manager: null };
  if (
    typeof manager === 'object' &&
    'email' in manager &&
    typeof manager.email === 'string' &&
    'displayName' in manager &&
    typeof manager.displayName === 'string'
  ) {
    return {
      manager: { email: manager.email, displayName: manager.displayName },
    };
  }
  return UNAVAILABLE;
}
