import type { TemplateVersion } from '../api/types';

/**
 * A custom anketa's template in the data export (GitHub issue #144, #133 §7.5):
 * its version's name and definition, so the export says what the answers were
 * answers to. Nothing for a built-in template, whose questions are in the app.
 */
export interface ExportedTemplate {
  template?: { name: string; definition: unknown };
  templateUnavailable?: true;
}

/**
 * The export's template fields per anketa, fetching each distinct version once,
 * however many anketas share it. A version that can't be fetched is marked
 * `templateUnavailable` instead of aborting the export.
 */
export function templateExporter(
  fetchVersion: (versionId: string) => Promise<TemplateVersion>,
): (versionId: string | null) => Promise<ExportedTemplate> {
  const versions = new Map<string, Promise<TemplateVersion | null>>();
  return async (versionId) => {
    if (versionId === null) return {};
    let version = versions.get(versionId);
    if (version === undefined) {
      version = fetchVersion(versionId).catch(() => null);
      versions.set(versionId, version);
    }
    const fetched = await version;
    return fetched === null
      ? { templateUnavailable: true }
      : { template: { name: fetched.name, definition: fetched.definition } };
  };
}
