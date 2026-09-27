import { ApiError } from '../api/client';
import { applyDiscussedIntents, type DiscussedBlobData } from './discussed';

/** Queued clicks: question ID → its intended state (see discussed.ts's setDiscussed()). */
type Intents = Record<string, boolean>;

export interface DiscussedView {
  /** What the checkboxes show: the saved list with every unsaved click on top. */
  discussed: DiscussedBlobData;
  /**
   * Why a click was lost, until a later round of saves goes through without
   * a failure (or the sync stops, when nothing can be retried any more).
   */
  error: string | null;
}

export interface DiscussedSyncOptions {
  /** The list and version the page loaded. */
  items: DiscussedBlobData;
  version: number;
  /**
   * Encrypts and PUTs the whole list against expectedVersion, resolving with
   * the new version. Bound to one anketa and its key when the page creates
   * the sync, so a save can never reach another anketa.
   */
  save: (items: DiscussedBlobData, expectedVersion: number) => Promise<number>;
  /**
   * Decrypts and parses a 409 body's discussedBlob. Must not throw: an
   * unreadable blob reads as [] (see discussed.ts's decryptDiscussed()).
   */
  decrypt: (blob: string | null) => Promise<DiscussedBlobData>;
  /** Called on every change of the view. */
  onChange: (view: DiscussedView) => void;
  /** A save found the anketa archived (the counterpart archived since the last poll). */
  onArchived: () => void;
  /** The message for a failure that isn't an ApiError, in the current locale. */
  genericError: () => string;
}

/**
 * Stale-version conflicts in a row before a batch is given up on. Each one
 * means another save landed in between, so this is only reached if both
 * participants keep clicking at the same moment.
 */
const MAX_CONFLICTS = 5;

/**
 * The "discussed" checkboxes of one open anketa (GitHub issue #168). A click
 * shows at once and is queued; one save at a time sends the whole queue,
 * starting from the last saved list and version rather than refetching, and
 * a stale-version 409 retries against the list its body carries. The page
 * creates a new instance per loaded anketa, so nothing crosses over to
 * another one.
 *
 * States: idle (nothing queued or in flight), saving (`inFlight` set, maybe
 * with more clicks queued behind it), and stopped (archived, or the page left
 * this anketa): no more saves, though one already in flight still finishes,
 * silently — its outcome no longer matters to anyone.
 */
export class DiscussedSync {
  private confirmed: DiscussedBlobData;
  private confirmedVersion: number;
  private inFlight: Intents | null = null;
  private pending: Intents = {};
  private stopped = false;
  private error: string | null = null;
  /** Resolvers of settled() calls waiting for the queue to drain. */
  private waiters: ((saved: boolean) => void)[] = [];

  constructor(private readonly options: DiscussedSyncOptions) {
    this.confirmed = options.items;
    this.confirmedVersion = options.version;
  }

  /** The version of the last list saved or applied, for the live-update poll to compare. */
  get version(): number {
    return this.confirmedVersion;
  }

  /** While true the poll must not apply a remote list: this tab's own save is under way. */
  get busy(): boolean {
    return this.inFlight !== null || Object.keys(this.pending).length > 0;
  }

  view(): DiscussedView {
    return {
      discussed: applyDiscussedIntents(
        applyDiscussedIntents(this.confirmed, this.inFlight ?? {}),
        this.pending,
      ),
      error: this.error,
    };
  }

  toggle(questionId: string): void {
    if (this.stopped) return;
    const discussed = !this.view().discussed.includes(questionId);
    this.pending = { ...this.pending, [questionId]: discussed };
    this.notify();
    void this.flush();
  }

  /**
   * A newer list from the live-update poll. Ignored while busy (the save's
   * own response or conflict brings the newer list anyway) or if not newer.
   */
  applyRemote(items: DiscussedBlobData, version: number): void {
    if (this.busy || version <= this.confirmedVersion) return;
    this.confirmed = items;
    this.confirmedVersion = version;
    this.notify();
  }

  /**
   * Resolves once nothing is queued or in flight: true if every save since
   * the queue last drained succeeded, false if any failed. Archiving
   * waits for it, so the last ticks are saved before the anketa is frozen.
   */
  settled(): Promise<boolean> {
    if (!this.busy) return Promise.resolve(true);
    return new Promise((resolve) => this.waiters.push(resolve));
  }

  /** The anketa was archived, or the page left it: drop unsent clicks, send nothing more. */
  stop(): void {
    this.stopped = true;
    this.pending = {};
    // Nothing can be retried any more, so an earlier failure has no use.
    this.error = null;
    this.notify();
  }

  private async flush(): Promise<void> {
    if (this.inFlight !== null) return;
    let allSaved = true;
    while (!this.stopped && Object.keys(this.pending).length > 0) {
      const intents = this.pending;
      this.pending = {};
      // Clicks that cancel out (ticked, then unticked while queued) change
      // nothing: no save, so no version bump for the counterpart to fetch.
      if (applyDiscussedIntents(this.confirmed, intents) === this.confirmed) {
        continue;
      }
      this.inFlight = intents;
      if (!(await this.send(intents))) allSaved = false;
      this.inFlight = null;
      this.notify();
    }
    if (allSaved && this.error !== null) {
      this.error = null;
      this.notify();
    }
    for (const resolve of this.waiters.splice(0)) resolve(allSaved);
  }

  /**
   * Saves one batch and reports whether it landed. Never throws: flush()
   * must always get to clear inFlight.
   */
  private async send(intents: Intents): Promise<boolean> {
    for (let conflicts = 0; ; conflicts++) {
      // stop() may have come during the previous attempt's decrypt.
      if (this.stopped) return false;
      const items = applyDiscussedIntents(this.confirmed, intents);
      try {
        const version = await this.options.save(items, this.confirmedVersion);
        this.confirmed = items;
        this.confirmedVersion = version;
        return true;
      } catch (error) {
        if (this.stopped) return false;
        const latest = conflictBody(error);
        if (latest !== null && conflicts < MAX_CONFLICTS) {
          this.confirmed = await this.options.decrypt(latest.blob);
          this.confirmedVersion = latest.version;
          continue;
        }
        if (error instanceof ApiError && error.status === 409 && !latest) {
          // The only other 409 PUT /discussed returns: the anketa is archived.
          // No banner: the page switching to archived says it, the same as
          // when the poll notices the archive first.
          this.stop();
          this.options.onArchived();
          return false;
        }
        this.error =
          error instanceof ApiError
            ? error.message
            : this.options.genericError();
        return false;
      }
    }
  }

  private notify(): void {
    this.options.onChange(this.view());
  }
}

/** A stale-version 409's body (the server's current list), or null for any other failure. */
function conflictBody(
  error: unknown,
): { blob: string | null; version: number } | null {
  if (!(error instanceof ApiError) || error.status !== 409) return null;
  const body = error.body as {
    discussedBlob?: string | null;
    discussedVersion?: unknown;
  } | null;
  if (typeof body?.discussedVersion !== 'number') return null;
  return { blob: body.discussedBlob ?? null, version: body.discussedVersion };
}
