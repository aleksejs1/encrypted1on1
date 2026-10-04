import { ApiError } from '../api/client';
import { versionConflictBody } from './blobSync';
import type { TopicItem } from './topics';

type Change = (current: TopicItem[]) => TopicItem[];

interface QueuedChange {
  apply: Change;
  resolve: () => void;
  reject: (reason: unknown) => void;
}

export interface TopicsSyncOptions {
  /** The list and version the page loaded. */
  items: TopicItem[];
  version: number;
  /**
   * Encrypts and PUTs the whole list against expectedVersion, resolving with
   * the new version. Bound to one anketa and its key when the page creates
   * the sync, so a save can never reach another anketa.
   */
  save: (items: TopicItem[], expectedVersion: number) => Promise<number>;
  /** Decrypts a stale-version 409 body's topicsBlob. */
  decrypt: (blob: string | null) => Promise<TopicItem[]>;
  /** Called with the list whenever it changes. */
  onChange: (items: TopicItem[]) => void;
  /** A save found the anketa archived (the counterpart archived since the last poll). */
  onArchived: () => void;
}

/**
 * Stale-version conflicts in a row before a change is given up on. Each one
 * means another save landed in between, so this is only reached if the
 * counterpart keeps saving at the same moment.
 */
const MAX_CONFLICTS = 5;

/**
 * The shared topics list of one open anketa (GitHub issue #206): the saved
 * list, and the changes to it, saved one at a time in the order they were
 * made. Like DiscussedSync, each save starts from the last saved list and
 * version rather than refetching, and a stale-version 409 reapplies the
 * change to the list its body carries. Unlike it, a change here can fail on
 * its own terms (the topic it edits was deleted meanwhile), so every change
 * has its own promise, and nothing shows before it is saved. The page
 * creates a new instance per loaded anketa.
 *
 * States: idle (nothing queued or in flight), saving (`running`, maybe with
 * more changes queued), and stopped (archived, found out here or by the
 * page, or the page left this anketa): queued changes are rejected and no
 * new ones are taken, though a save already in flight still finishes and is
 * applied.
 */
export class TopicsSync {
  private confirmed: TopicItem[];
  private confirmedVersion: number;
  private queue: QueuedChange[] = [];
  private running = false;
  private stopped = false;
  /** Resolvers of settled() calls waiting for the queue to drain. */
  private waiters: ((saved: boolean) => void)[] = [];

  constructor(private readonly options: TopicsSyncOptions) {
    this.confirmed = options.items;
    this.confirmedVersion = options.version;
  }

  /** The version of the list last saved or applied, for the live-update poll to compare. */
  get version(): number {
    return this.confirmedVersion;
  }

  /** While true the poll must not apply a remote list: this tab's own save is under way. */
  get busy(): boolean {
    return this.running || this.queue.length > 0;
  }

  items(): TopicItem[] {
    return this.confirmed;
  }

  /**
   * Queues a change. Resolves once it's saved; rejects with the save's
   * error, with what `apply` threw (the change no longer fits the list), or
   * because the sync was stopped first.
   */
  update(apply: Change): Promise<void> {
    if (this.stopped) return Promise.reject(stoppedError());
    return new Promise((resolve, reject) => {
      this.queue.push({ apply, resolve, reject });
      void this.run();
    });
  }

  /**
   * A newer list from the live-update poll. Ignored while busy (the save's
   * own response or conflict brings the newer list anyway) or if not newer.
   * Still taken once stopped: the last change before an archive may arrive
   * after it.
   */
  applyRemote(items: TopicItem[], version: number): void {
    if (this.busy || version <= this.confirmedVersion) return;
    this.setConfirmed(items, version);
  }

  /**
   * Resolves once nothing is queued or in flight: true if every change
   * since the queue last drained was saved, false if any failed. True at
   * once when idle, whatever happened earlier. Archiving waits for it, so
   * the last ticks are saved before the anketa is frozen.
   */
  settled(): Promise<boolean> {
    if (!this.busy) return Promise.resolve(true);
    return new Promise((resolve) => this.waiters.push(resolve));
  }

  /** The anketa was archived, or the page left it: reject what's queued, take nothing more. */
  stop(): void {
    this.stopped = true;
    for (const change of this.queue.splice(0)) change.reject(stoppedError());
  }

  private async run(): Promise<void> {
    if (this.running) return;
    this.running = true;
    let allSaved = true;
    for (
      let change = this.queue.shift();
      change !== undefined;
      change = this.queue.shift()
    ) {
      try {
        await this.send(change.apply);
        change.resolve();
      } catch (error) {
        allSaved = false;
        change.reject(error);
      }
    }
    this.running = false;
    // stop() may have emptied the queue under a save in flight.
    if (this.stopped) allSaved = false;
    for (const resolve of this.waiters.splice(0)) resolve(allSaved);
  }

  /** Saves one change, or throws why it couldn't be. */
  private async send(apply: Change): Promise<void> {
    for (let conflicts = 0; ; conflicts++) {
      const items = apply(this.confirmed);
      // Nothing to save: the list already is what the change asks for (the
      // counterpart ticked the same topic, or an add that had landed is
      // retried). No save, so no version bump for the counterpart to fetch.
      if (items === this.confirmed) return;
      try {
        const version = await this.options.save(items, this.confirmedVersion);
        this.setConfirmed(items, version);
        return;
      } catch (error) {
        const latest = versionConflictBody(
          error,
          'topicsBlob',
          'topicsVersion',
        );
        if (latest === null) {
          if (error instanceof ApiError && error.status === 409) {
            // The only other 409 PUT /topics returns: the anketa is archived.
            // Handled like DiscussedSync does, so the page has one rule for
            // both: stop (what's queued would only be refused too) and tell
            // the page, which moves to archived at once.
            this.stop();
            this.options.onArchived();
          }
          throw error;
        }
        // Shown even if this change is then given up on.
        this.setConfirmed(
          await this.options.decrypt(latest.blob),
          latest.version,
        );
        if (conflicts >= MAX_CONFLICTS || this.stopped) throw error;
      }
    }
  }

  private setConfirmed(items: TopicItem[], version: number): void {
    this.confirmed = items;
    this.confirmedVersion = version;
    this.options.onChange(items);
  }
}

function stoppedError(): Error {
  return new Error('TopicsSync: stopped (archived, or the page left)');
}
