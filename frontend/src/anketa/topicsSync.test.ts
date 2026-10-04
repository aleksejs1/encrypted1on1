import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/client';
import {
  addTopic,
  newTopic,
  deleteTopic,
  editTopic,
  setTopicDiscussed,
  type TopicItem,
} from './topics';

import { TopicsSync } from './topicsSync';

/** A new topic by `authorId`, appended. */
function add(existing: TopicItem[], authorId: string, text: string) {
  return addTopic(existing, newTopic(authorId, text));
}

/** A stand-in for the server's topics column: a list and its version. */
class FakeServer {
  items: TopicItem[];
  version: number;
  archived = false;
  /** Every save attempt's expected version, in order. */
  attempts: number[] = [];
  /** Held saves, released by the test: lets a save stay in flight. */
  private gate: Promise<void> | null = null;
  private openGate: (() => void) | null = null;
  failNext: unknown = null;

  constructor(items: TopicItem[] = [], version = 0) {
    this.items = items;
    this.version = version;
  }

  hold(): void {
    this.gate = new Promise((resolve) => {
      this.openGate = resolve;
    });
  }

  release(): void {
    this.openGate?.();
    this.gate = null;
  }

  /** Another participant's save landing. */
  counterpartSaves(apply: (items: TopicItem[]) => TopicItem[]): void {
    this.items = apply(this.items);
    this.version++;
  }

  save = async (items: TopicItem[], expectedVersion: number) => {
    this.attempts.push(expectedVersion);
    if (this.gate) await this.gate;
    if (this.failNext !== null) {
      const failure = this.failNext;
      this.failNext = null;
      throw failure;
    }
    if (this.archived) throw new ApiError(409, 'This 1:1 is archived.', {});
    if (expectedVersion !== this.version) {
      // The body's blob stands for the ciphertext: the test's decrypt reads
      // the list straight from here.
      throw new ApiError(409, 'Topics changed.', {
        topicsBlob: 'current',
        topicsVersion: this.version,
      });
    }
    this.items = items;
    this.version++;
    return this.version;
  };
}

function setup(server = new FakeServer()) {
  const shown: TopicItem[][] = [];
  let refused = 0;
  const sync = new TopicsSync({
    items: server.items,
    version: server.version,
    save: server.save,
    decrypt: () => Promise.resolve(server.items),
    onChange: (items) => shown.push(items),
    onArchived: () => refused++,
  });
  return { sync, server, shown, refusedCount: () => refused };
}

const texts = (items: TopicItem[]) => items.map((item) => item.text);

describe('TopicsSync', () => {
  it('saves a change against the loaded version and shows the saved list', async () => {
    const { sync, server, shown } = setup();

    await sync.update((current) => add(current, 'user-1', 'first'));

    expect(texts(server.items)).toEqual(['first']);
    expect(server.attempts).toEqual([0]);
    expect(sync.version).toBe(1);
    expect(texts(sync.items())).toEqual(['first']);
    expect(shown.map(texts)).toEqual([['first']]);
    expect(sync.busy).toBe(false);
  });

  it('shows nothing before it is saved', async () => {
    const { sync, server, shown } = setup();
    server.hold();

    const saving = sync.update((current) => add(current, 'user-1', 'a'));
    await Promise.resolve();

    expect(sync.busy).toBe(true);
    expect(sync.items()).toEqual([]);
    expect(shown).toEqual([]);

    server.release();
    await saving;
    expect(texts(sync.items())).toEqual(['a']);
  });

  it('saves queued changes one at a time, each on the list the previous one left', async () => {
    const { sync, server } = setup();

    await Promise.all([
      sync.update((current) => add(current, 'user-1', 'a')),
      sync.update((current) => add(current, 'user-1', 'b')),
      sync.update((current) => add(current, 'user-1', 'c')),
    ]);

    expect(texts(server.items)).toEqual(['a', 'b', 'c']);
    // No conflicts among this tab's own saves: 0, then 1, then 2.
    expect(server.attempts).toEqual([0, 1, 2]);
  });

  it("reapplies a change to the counterpart's newer list after a conflict", async () => {
    const { sync, server, shown } = setup(
      new FakeServer(add([], 'user-1', 'first'), 4),
    );
    server.counterpartSaves((items) => add(items, 'user-2', 'theirs'));

    await sync.update((current) => add(current, 'user-1', 'mine'));

    expect(texts(server.items)).toEqual(['first', 'theirs', 'mine']);
    expect(server.attempts).toEqual([4, 5]);
    // The counterpart's list is shown as soon as the conflict brings it.
    expect(shown.map(texts)).toEqual([
      ['first', 'theirs'],
      ['first', 'theirs', 'mine'],
    ]);
  });

  it('does not untick a topic the counterpart ticked a moment earlier, and saves nothing more', async () => {
    const initial = add([], 'user-1', 'first');
    const { sync, server } = setup(new FakeServer(initial, 1));
    server.counterpartSaves((items) =>
      setTopicDiscussed(items, initial[0].id, true),
    );

    await sync.update((current) =>
      setTopicDiscussed(current, initial[0].id, true),
    );

    expect(server.items[0].discussed).toBe(true);
    // The refused attempt only; the list it brought already has the tick.
    expect(server.attempts).toEqual([1]);
    expect(server.version).toBe(2);
    expect(sync.version).toBe(2);
  });

  it('sends nothing for a change that changes nothing', async () => {
    const item = newTopic('user-1', 'once');
    const { sync, server, shown } = setup(new FakeServer([item], 3));

    await sync.update((current) => addTopic(current, item));
    await sync.update((current) => setTopicDiscussed(current, item.id, false));

    expect(server.attempts).toEqual([]);
    expect(shown).toEqual([]);
  });

  it('rejects a change that no longer fits, shows the current list, and keeps going', async () => {
    const initial = add([], 'user-2', 'theirs');
    const { sync, server } = setup(new FakeServer(initial, 1));
    server.counterpartSaves((items) =>
      deleteTopic(items, initial[0].id, 'user-2'),
    );

    const tick = sync.update((current) =>
      setTopicDiscussed(current, initial[0].id, true),
    );
    const adding = sync.update((current) => add(current, 'user-1', 'next'));

    await expect(tick).rejects.toThrow(/deleted elsewhere/);
    await adding;
    expect(texts(sync.items())).toEqual(['next']);
    expect(texts(server.items)).toEqual(['next']);
  });

  it("rejects an edit of someone else's topic without saving anything", async () => {
    const initial = add([], 'user-2', 'theirs');
    const { sync, server } = setup(new FakeServer(initial, 1));

    await expect(
      sync.update((current) =>
        editTopic(current, initial[0].id, 'user-1', 'x'),
      ),
    ).rejects.toThrow();
    expect(server.attempts).toEqual([]);
    expect(texts(server.items)).toEqual(['theirs']);
  });

  it('gives up after repeated conflicts, with the latest list shown', async () => {
    const server = new FakeServer();
    const save = server.save;
    // The counterpart lands a save before every one of this tab's attempts.
    server.save = (items, expectedVersion) => {
      server.counterpartSaves((current) =>
        add(current, 'user-2', `theirs ${server.version}`),
      );
      return save(items, expectedVersion);
    };
    const { sync } = setup(server);

    await expect(
      sync.update((current) => add(current, 'user-1', 'mine')),
    ).rejects.toBeInstanceOf(ApiError);

    expect(server.attempts).toHaveLength(6);
    expect(texts(server.items)).not.toContain('mine');
    expect(sync.items()).toEqual(server.items);
    expect(sync.busy).toBe(false);
  });

  it('stops on the archived refusal: one report, queued changes rejected unsent', async () => {
    const { sync, server, refusedCount } = setup();
    server.archived = true;

    const first = sync.update((current) => add(current, 'user-1', 'late'));
    const second = sync.update((current) => add(current, 'user-1', 'later'));
    const settled = sync.settled();

    await expect(first).rejects.toThrow('This 1:1 is archived.');
    await expect(second).rejects.toThrow(/stopped/);
    expect(await settled).toBe(false);
    expect(refusedCount()).toBe(1);
    // One attempt in all: an archived 409 carries no version to retry
    // against, and the second change is never sent.
    expect(server.attempts).toEqual([0]);
    await expect(
      sync.update((current) => add(current, 'user-1', 'after')),
    ).rejects.toThrow(/stopped/);
  });

  it('rejects a failed request without reporting a refusal, and saves the next change', async () => {
    const { sync, server, refusedCount } = setup();
    server.failNext = new TypeError('Failed to fetch');

    await expect(
      sync.update((current) => add(current, 'user-1', 'lost')),
    ).rejects.toThrow('Failed to fetch');
    await sync.update((current) => add(current, 'user-1', 'kept'));

    expect(refusedCount()).toBe(0);
    expect(texts(server.items)).toEqual(['kept']);
  });

  describe('applyRemote', () => {
    it('takes a newer list from the poll', () => {
      const { sync, shown } = setup();
      const remote = add([], 'user-2', 'theirs');

      sync.applyRemote(remote, 3);

      expect(sync.items()).toBe(remote);
      expect(sync.version).toBe(3);
      expect(shown).toEqual([remote]);
    });

    it('ignores a list that is not newer', async () => {
      const { sync } = setup();
      await sync.update((current) => add(current, 'user-1', 'mine'));

      sync.applyRemote([], 1);
      sync.applyRemote([], 0);

      expect(texts(sync.items())).toEqual(['mine']);
    });

    it('ignores the poll while a save is under way', async () => {
      const { sync, server } = setup();
      server.hold();
      const saving = sync.update((current) => add(current, 'user-1', 'mine'));
      await Promise.resolve();

      sync.applyRemote(add([], 'user-2', 'stale poll'), 7);

      server.release();
      await saving;
      expect(texts(sync.items())).toEqual(['mine']);
      expect(sync.version).toBe(1);
    });

    it('still takes the last list once stopped', () => {
      const { sync } = setup();
      sync.stop();

      sync.applyRemote(add([], 'user-2', 'just before archive'), 2);

      expect(texts(sync.items())).toEqual(['just before archive']);
    });
  });

  describe('settled', () => {
    it('is true at once when idle, even after an earlier failure', async () => {
      const { sync, server } = setup();
      server.failNext = new TypeError('Failed to fetch');
      await expect(
        sync.update((current) => add(current, 'user-1', 'lost')),
      ).rejects.toThrow();

      expect(await sync.settled()).toBe(true);
    });

    it('waits for the queue and reports that everything was saved', async () => {
      const { sync, server } = setup();
      server.hold();
      const saving = sync.update((current) => add(current, 'user-1', 'a'));
      let settled: boolean | null = null;
      void sync.settled().then((saved) => (settled = saved));
      await Promise.resolve();
      expect(settled).toBeNull();

      server.release();
      await saving;
      await Promise.resolve();

      expect(settled).toBe(true);
      expect(texts(server.items)).toEqual(['a']);
    });

    it('reports false if a change under way failed', async () => {
      const { sync, server } = setup();
      server.hold();
      server.failNext = new TypeError('Failed to fetch');
      const failing = sync.update((current) => add(current, 'user-1', 'a'));
      const settled = sync.settled();

      server.release();

      await expect(failing).rejects.toThrow();
      expect(await settled).toBe(false);
    });
  });

  describe('stop', () => {
    it('takes no new changes', async () => {
      const { sync, server } = setup();
      sync.stop();

      await expect(
        sync.update((current) => add(current, 'user-1', 'late')),
      ).rejects.toThrow(/stopped/);
      expect(server.attempts).toEqual([]);
    });

    it('rejects queued changes but lets the save in flight finish and show', async () => {
      const { sync, server } = setup();
      server.hold();
      const inFlight = sync.update((current) =>
        add(current, 'user-1', 'in flight'),
      );
      const queued = sync.update((current) => add(current, 'user-1', 'queued'));
      const settled = sync.settled();
      await Promise.resolve();

      sync.stop();
      server.release();

      await expect(queued).rejects.toThrow(/stopped/);
      await inFlight;
      expect(texts(server.items)).toEqual(['in flight']);
      expect(texts(sync.items())).toEqual(['in flight']);
      // Something asked for wasn't saved.
      expect(await settled).toBe(false);
    });
  });
});
