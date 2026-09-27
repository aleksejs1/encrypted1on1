import { apiGet } from './client';
import type { AdminTemplate } from './types';

/**
 * GET /api/admin/templates: every company template, archived ones included,
 * each with its current version's content (GitHub issue #142). Used by the
 * template list and the editor.
 */
export function fetchAdminTemplates(
  signal?: AbortSignal,
): Promise<AdminTemplate[]> {
  return apiGet<AdminTemplate[]>('/api/admin/templates', { signal });
}
