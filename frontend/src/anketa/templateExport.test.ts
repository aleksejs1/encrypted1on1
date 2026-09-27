import { describe, expect, it, vi } from 'vitest';
import { templateExporter } from './templateExport';

const definition = { schemaVersion: 1, employee: [], manager: [] };

describe('templateExporter', () => {
  it('adds nothing for a built-in template', async () => {
    const fetchVersion = vi.fn();
    expect(await templateExporter(fetchVersion)(null)).toEqual({});
    expect(fetchVersion).not.toHaveBeenCalled();
  });

  it("adds a custom anketa's name and definition, fetching each version once", async () => {
    const fetchVersion = vi.fn((versionId: string) =>
      Promise.resolve({ name: `Name of ${versionId}`, definition }),
    );
    const exportTemplate = templateExporter(fetchVersion);

    expect(await exportTemplate('v1')).toEqual({
      template: { name: 'Name of v1', definition },
    });
    expect(await exportTemplate('v1')).toEqual({
      template: { name: 'Name of v1', definition },
    });
    expect(await exportTemplate('v2')).toEqual({
      template: { name: 'Name of v2', definition },
    });
    expect(fetchVersion.mock.calls).toEqual([['v1'], ['v2']]);
  });

  it('marks a version it cannot fetch, without throwing', async () => {
    const fetchVersion = vi.fn(() => Promise.reject(new Error('offline')));
    const exportTemplate = templateExporter(fetchVersion);

    expect(await exportTemplate('v1')).toEqual({ templateUnavailable: true });
    expect(await exportTemplate('v1')).toEqual({ templateUnavailable: true });
    expect(fetchVersion).toHaveBeenCalledTimes(1);
  });
});
