import { apiGet } from './client';
import type { CompanyTemplate, TemplateVersion } from './types';

/**
 * GET /api/templates: the company's active templates, for the meeting-type
 * pickers (GitHub issue #144). Names and descriptions only.
 */
export function fetchCompanyTemplates(
  signal?: AbortSignal,
): Promise<CompanyTemplate[]> {
  return apiGet<CompanyTemplate[]>('/api/templates', { signal });
}

/**
 * GET /api/template-versions/{id}: the version a custom anketa renders,
 * archived templates' included.
 */
export function fetchTemplateVersion(
  versionId: string,
  signal?: AbortSignal,
): Promise<TemplateVersion> {
  return apiGet<TemplateVersion>(
    `/api/template-versions/${encodeURIComponent(versionId)}`,
    { signal },
  );
}
