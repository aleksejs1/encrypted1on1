import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api/client';
import { DiscussedSync, type DiscussedView } from './discussedSync';

/** A save whose calls are resolved or rejected by hand, in order. */
function manualSave() {
  const calls: {
    items: string[];
    expectedVersion: number;
    resolve: (version: number) => void;
    reject: (error: unknown) => void;
  }[] = [];
  const save = (items: string[], expectedVersion: number) =>
    new Promise<number>((resolve, reject) => {
      calls.push({ items, expectedVersion, resolve, reject });
    });
  return { calls, save };
}

/** Lets every pending promise callback run. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

function conflict(blob: string | null, version: number): ApiError {
  return new ApiError(409, 'Discussed questions changed.', {
    error: 'Discussed questions changed.',
    discussedBlob: blob,
    discussedVersion: version,
  });
}

function makeSync(
  save: (items: string[], expectedVersion: number) => Promise<number>,
  options: { items?: string[]; version?: number } = {},
) {
  const views: DiscussedView[] = [];
  const onArchived = vi.fn();
  const sync = new DiscussedSync({
    items: options.items ?? [],
    version: options.version ?? 0,
    save,
    // The "ciphertext" in these tests is just the JSON of the list.
    decrypt: async (blob) => (blob ? (JSON.parse(blob) as string[]) : []),
    onChange: (view) => views.push(view),
    onArchived,
    genericError: () => 'generic',
  });
  const last = () => views[views.length - 1] ?? sync.view();
  return { sync, views, last, onArchived };
}

describe('DiscussedSync', () => {
  it('shows a click at once and saves it against the loaded version', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save, { items: ['mood'], version: 3 });

    sync.toggle('workload');

    expect(last().discussed).toEqual(['mood', 'workload']);
    expect(calls).toHaveLength(1);
    expect(calls[0].items).toEqual(['mood', 'workload']);
    expect(calls[0].expectedVersion).toBe(3);
    expect(sync.busy).toBe(true);

    calls[0].resolve(4);
    await settle();

    expect(sync.busy).toBe(false);
    expect(sync.version).toBe(4);
    expect(last()).toEqual({ discussed: ['mood', 'workload'], error: null });
  });

  it('unticks a ticked question', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save, { items: ['mood'], version: 1 });

    sync.toggle('mood');
    expect(last().discussed).toEqual([]);
    calls[0].resolve(2);
    await settle();

    expect(last().discussed).toEqual([]);
  });

  it('queues clicks made while a save is in flight into one next save', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    sync.toggle('workload');
    sync.toggle('growth');

    expect(calls).toHaveLength(1);
    expect(last().discussed).toEqual(['mood', 'workload', 'growth']);

    calls[0].resolve(1);
    await settle();

    expect(calls).toHaveLength(2);
    expect(calls[1].items).toEqual(['mood', 'workload', 'growth']);
    expect(calls[1].expectedVersion).toBe(1);

    calls[1].resolve(2);
    await settle();
    expect(sync.busy).toBe(false);
    expect(sync.version).toBe(2);
  });

  it('sends nothing for queued clicks that cancel out', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    sync.toggle('workload');
    sync.toggle('workload');
    expect(last().discussed).toEqual(['mood']);

    calls[0].resolve(1);
    await settle();
    expect(calls).toHaveLength(1);
    expect(sync.busy).toBe(false);
    expect(sync.version).toBe(1);
  });

  it('on a stale version, retries on top of the list the 409 carries', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    // The counterpart ticked mood a moment earlier; this tab ticks it too.
    sync.toggle('mood');
    calls[0].reject(conflict(JSON.stringify(['mood', 'feedback']), 1));
    await settle();

    // Set, not toggle: mood stays ticked.
    expect(calls[1].items).toEqual(['mood', 'feedback']);
    expect(calls[1].expectedVersion).toBe(1);
    calls[1].resolve(2);
    await settle();

    expect(last()).toEqual({ discussed: ['mood', 'feedback'], error: null });
  });

  it('keeps retrying while conflicts continue, up to a limit, then reports an error', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    for (let version = 1; version <= 6; version++) {
      calls[version - 1].reject(conflict(JSON.stringify(['other']), version));
      await settle();
    }

    expect(calls).toHaveLength(6);
    expect(sync.busy).toBe(false);
    // The failed click is dropped; the last list the server sent stays.
    expect(last()).toEqual({
      discussed: ['other'],
      error: 'Discussed questions changed.',
    });
  });

  it('on a failure, drops only the failed batch and still sends clicks made since', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    sync.toggle('workload');
    calls[0].reject(new TypeError('Failed to fetch'));
    await settle();

    expect(last().error).toBe('generic');
    expect(calls[1].items).toEqual(['workload']);
    expect(calls[1].expectedVersion).toBe(0);
    calls[1].resolve(1);
    await settle();

    // The banner stays: it explains the lost mood click.
    expect(last()).toEqual({ discussed: ['workload'], error: 'generic' });
  });

  it('stops, and reports the archive, on a 409 without a version', async () => {
    const { calls, save } = manualSave();
    const { sync, last, onArchived } = makeSync(save, { items: ['mood'] });

    sync.toggle('workload');
    sync.toggle('growth');
    calls[0].reject(new ApiError(409, 'This anketa is archived.', {}));
    await settle();

    expect(onArchived).toHaveBeenCalledOnce();
    expect(calls).toHaveLength(1);
    // No banner: the page's archived state says it.
    expect(last()).toEqual({ discussed: ['mood'], error: null });

    sync.toggle('feedback');
    expect(calls).toHaveLength(1);
    expect(last().discussed).toEqual(['mood']);
  });

  it('a save failing after stop() changes nothing and reports nothing', async () => {
    const { calls, save } = manualSave();
    const { sync, last, onArchived } = makeSync(save);

    sync.toggle('mood');
    sync.stop();
    calls[0].reject(new ApiError(409, 'This anketa is archived.', {}));
    await settle();

    expect(onArchived).not.toHaveBeenCalled();
    expect(last()).toEqual({ discussed: [], error: null });
  });

  it('settled() resolves once the queue has drained', async () => {
    const { calls, save } = manualSave();
    const { sync } = makeSync(save);
    await sync.settled();

    sync.toggle('mood');
    sync.toggle('workload');
    let drained = false;
    void sync.settled().then(() => {
      drained = true;
    });

    calls[0].resolve(1);
    await settle();
    expect(drained).toBe(false);
    calls[1].resolve(2);
    await settle();
    expect(drained).toBe(true);
    expect(sync.version).toBe(2);
  });

  it('settled() reports a failed last save, and true when idle', async () => {
    const { calls, save } = manualSave();
    const { sync } = makeSync(save);

    sync.toggle('mood');
    const result = sync.settled();
    calls[0].reject(new TypeError('Failed to fetch'));
    expect(await result).toBe(false);
    // Nothing left to save: a second Archive goes ahead.
    expect(await sync.settled()).toBe(true);
  });

  it('stop() clears an earlier error, since nothing can be retried', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    calls[0].reject(new TypeError('Failed to fetch'));
    await settle();
    expect(last().error).toBe('generic');

    sync.stop();
    expect(last().error).toBeNull();
  });

  it('sends no conflict retry once stopped during the decrypt', async () => {
    const { calls, save } = manualSave();
    let finishDecrypt: (ids: string[]) => void = () => {};
    const sync = new DiscussedSync({
      items: [],
      version: 0,
      save,
      decrypt: () =>
        new Promise<string[]>((resolve) => {
          finishDecrypt = resolve;
        }),
      onChange: () => {},
      onArchived: () => {},
      genericError: () => 'generic',
    });

    sync.toggle('mood');
    calls[0].reject(conflict('[]', 1));
    await settle();
    sync.stop();
    finishDecrypt([]);
    await settle();

    expect(calls).toHaveLength(1);
    expect(sync.busy).toBe(false);
  });

  it('after stop(), drops queued clicks and ignores new ones, but lets the in-flight save finish', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    sync.toggle('workload');
    sync.stop();
    expect(last().discussed).toEqual(['mood']);

    calls[0].resolve(1);
    await settle();

    expect(calls).toHaveLength(1);
    expect(sync.version).toBe(1);
    expect(last().discussed).toEqual(['mood']);
    sync.toggle('growth');
    expect(calls).toHaveLength(1);
  });

  it('applies a newer remote list only when idle', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save, { version: 1 });

    sync.applyRemote(['feedback'], 2);
    expect(last().discussed).toEqual(['feedback']);
    expect(sync.version).toBe(2);

    // Not newer: ignored.
    sync.applyRemote([], 2);
    expect(last().discussed).toEqual(['feedback']);

    // Busy: ignored, so the optimistic tick isn't wiped out.
    sync.toggle('mood');
    sync.applyRemote(['support'], 3);
    expect(last().discussed).toEqual(['feedback', 'mood']);
    expect(sync.version).toBe(2);

    calls[0].resolve(3);
    await settle();
    expect(last().discussed).toEqual(['feedback', 'mood']);
  });

  it('keeps an earlier save error when a remote list arrives', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    calls[0].reject(new TypeError('Failed to fetch'));
    await settle();

    // The banner still explains the lost click.
    sync.applyRemote(['feedback'], 1);
    expect(last()).toEqual({ discussed: ['feedback'], error: 'generic' });
  });

  it('settled() reports a failure earlier in the drain, even if a later batch saved', async () => {
    const { calls, save } = manualSave();
    const { sync, last } = makeSync(save);

    sync.toggle('mood');
    sync.toggle('workload');
    const result = sync.settled();
    calls[0].reject(new TypeError('Failed to fetch'));
    await settle();
    calls[1].resolve(1);

    expect(await result).toBe(false);
    expect(last()).toEqual({ discussed: ['workload'], error: 'generic' });

    // The next round of saves that all go through clears it.
    sync.toggle('growth');
    calls[2].resolve(2);
    await settle();
    expect(last()).toEqual({ discussed: ['workload', 'growth'], error: null });
  });
});
