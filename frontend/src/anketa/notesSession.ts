import { ApiError, apiGet, apiPut, warmCsrfToken } from '../api/client';
import {
  MAX_NOTES_BLOB_LENGTH,
  decryptNotes,
  encryptNotes,
  generateNotesKey,
  notesAssociatedData,
  unwrapNotesKey,
  wrapNotesKey,
} from '../crypto/privateNotes';
import {
  clearNotesBackup,
  readNotesBackup,
  writeNotesBackup,
} from './notesBackup';
import { refreshNotesUnloadWarning } from './notesUnloadWarning';
import {
  backupOf,
  hasUnsavedWork,
  initialNotesModel,
  notesReducer,
  type ConflictChoice,
  type NotesEvent,
  type NotesKeys,
  type NotesModel,
  type ServerRow,
} from './notesState';

/** The wire shape of a private-notes row (backend AnketaController::getPrivateNotes()). */
interface RowResponse {
  encryptedNotesKey: string;
  notesBlob: string;
  version: number;
}

/** A 409's body: the current row, or all nulls when the server couldn't show one. */
interface ConflictBody {
  encryptedNotesKey: string | null;
  notesBlob: string | null;
  version: number | null;
}

export interface NotesSessionOptions {
  anketaId: string;
  userId: string;
  publicKey: Uint8Array;
  privateKey: Uint8Array;
  /** The backup key (crypto/privateNotes.ts's deriveNotesBackupKey()), captured now. */
  backupKey: Uint8Array;
  /** getGeneration() when the panel was created; a different value later means a logout or expiry. */
  generation: number;
  getGeneration: () => number;
  /** The logged-in user's id right now (identity.svelte.ts's loggedInUserId()). */
  loggedInUserId: () => string | null;
  onChange: (model: NotesModel) => void;
}

/** Wait after the last keystroke before saving. */
const DEBOUNCE_MS = 1000;
/** Automatic retries after a network/5xx failure; after these, a manual Retry. */
const RETRY_DELAYS_MS = [2000, 5000, 15000];
export const AUTOMATIC_RETRY_COUNT = RETRY_DELAYS_MS.length;
/** How long a new panel waits for a destroyed one's save before loading anyway. */
const DETACHED_WAIT_MS = 5000;
/** keepalive requests have a ~64 KB body limit; a bigger unload save goes as a normal request. */
const KEEPALIVE_QUOTA = 60_000;
/** A GET can bring back a row as large as the cap. */
const MAX_ROW_LENGTH = MAX_NOTES_BLOB_LENGTH + 200;
/**
 * A request still unanswered after this counts as a network failure, so a
 * stalled connection can't leave the panel "Saving…" for good. Whether it
 * landed is then unknown, which sentSinceAck already accounts for. Scaled
 * with the body, so a near-cap save on a slow link isn't cut off (a 409
 * brings a row of the same size back).
 */
function requestTimeout(bodyLength: number): AbortSignal {
  return AbortSignal.timeout(20_000 + 2 * Math.ceil(bodyLength / 1000) * 100);
}

/**
 * What makes a save request, where it matters: while retrying, only the retry
 * timer, the manual Retry, a hidden tab, pagehide and destroy send; the
 * debounce and blur leave the backoff to run (§6.3).
 */
type SaveTrigger =
  | 'debounce'
  | 'blur'
  | 'hidden'
  | 'pagehide'
  | 'destroy'
  | 'retryTimer'
  | 'manual';

interface DetachedPanel {
  /** Resolves once the destroyed panel's last request, and every older one's, has finished. */
  settled: Promise<void>;
  /**
   * A new panel is about to read the backup: the destroyed ones stop touching
   * it, and ignore whatever their late requests bring back.
   */
  yieldBackup: () => void;
}

/**
 * One owner of an anketa's backup at a time (§6.3). A destroyed panel makes
 * one last save of its pending text and then does nothing more: no retries,
 * no listeners. It's kept here while that save runs. A new panel for the same
 * anketa waits for it, at most DETACHED_WAIT_MS, then takes the backup over
 * (yieldBackup) before reading it, so the old panel can never clear or
 * overwrite what the new one writes. A save of the old panel's that lands
 * after that still reads as this tab's own, through the backup's sent texts.
 */
const detachedPanels = new Map<string, DetachedPanel>();

function isNeedsReloadStatus(status: number): boolean {
  return status === 401 || status === 403 || status === 404;
}

/**
 * The private-notes panel's controller (GitHub issue #132 §6.3): performs the
 * requests the state machine in notesState.ts asks for, and feeds their
 * outcomes back in. One instance per panel, created for one anketa.
 */
export class NotesSession {
  private model: NotesModel = initialNotesModel();
  private readonly associatedData: Uint8Array;
  private readonly url: string;
  /** Detached panels are per user and anketa, like backups. */
  private readonly chainKey: string;
  private debounceTimer: ReturnType<typeof setTimeout> | null = null;
  private retryTimer: ReturnType<typeof setTimeout> | null = null;
  /** The request (a PUT, or a re-GET after a null 409) in flight, if any. */
  private activeRequest: Promise<void> | null = null;
  private destroyed = false;
  private backupPending = false;
  /** The backup write loop, while it runs. */
  private backupLoop: Promise<void> | null = null;
  /** A newer panel owns this anketa's backup: this one no longer acts on anything. */
  private yielded = false;
  /** Consecutive "409 with nulls, then no row" outcomes, to stop a tight insert loop. */
  private nullConflictStreak = 0;

  constructor(private readonly options: NotesSessionOptions) {
    this.associatedData = notesAssociatedData(options.anketaId, options.userId);
    this.url = `/api/anketas/${options.anketaId}/private-notes`;
    this.chainKey = `${options.userId}:${options.anketaId}`;
    window.addEventListener('pagehide', this.onPageHide);
    document.addEventListener('visibilitychange', this.onVisibilityChange);
  }

  getModel(): NotesModel {
    return this.model;
  }

  async load(): Promise<void> {
    const previous = detachedPanels.get(this.chainKey);
    if (previous) {
      await Promise.race([
        previous.settled,
        new Promise((resolve) => setTimeout(resolve, DETACHED_WAIT_MS)),
      ]);
      // Destroyed while waiting: this panel never takes anything over.
      if (this.destroyed) return;
      previous.yieldBackup();
    }
    // A cached token lets an unload save go out within the pagehide task.
    warmCsrfToken().catch(() => {});

    let response: RowResponse | null;
    try {
      response = await apiGet<RowResponse | null>(this.url, {
        signal: requestTimeout(MAX_ROW_LENGTH),
      });
    } catch (error) {
      this.apply({
        type: 'loadFailed',
        needsReload:
          error instanceof ApiError && isNeedsReloadStatus(error.status),
      });
      return;
    }
    const [row, backup, freshKeys] = await Promise.all([
      response === null ? null : this.openRow(response),
      readNotesBackup(
        this.options.userId,
        this.options.anketaId,
        this.options.backupKey,
      ),
      // Only needed when there's no row yet.
      response === null ? this.freshKeys() : null,
    ]);
    // A panel already gone would start a save nothing tracks; a logout meanwhile
    // means the page is going too. Either way the backup stays for next time.
    if (
      this.destroyed ||
      this.options.getGeneration() !== this.options.generation
    ) {
      return;
    }
    this.apply({ type: 'loaded', row, backup, freshKeys });
  }

  retryLoad(): void {
    this.apply({ type: 'retryLoad' });
    if (this.model.status === 'loading') void this.load();
  }

  edit(text: string): void {
    this.apply({ type: 'edited', text });
    this.clearDebounce();
    this.debounceTimer = setTimeout(
      () => this.requestSave('debounce'),
      DEBOUNCE_MS,
    );
  }

  /** Save now if there's anything to save; see SaveTrigger. */
  requestSave(trigger: SaveTrigger): void {
    this.clearDebounce();
    if (
      this.model.status === 'retrying' &&
      (trigger === 'debounce' || trigger === 'blur')
    ) {
      return;
    }
    this.clearRetry();
    if (this.options.getGeneration() !== this.options.generation) {
      this.apply({ type: 'sessionEnded' });
      return;
    }
    const wasSaving = this.model.status === 'saving';
    this.apply({ type: 'saveRequested' });
    if (!wasSaving && this.model.status === 'saving') {
      // Only pagehide sends keepalive: the page is going. Everything else
      // is a plain request with a timeout (keepalive requests share a small
      // quota and can't time out).
      this.track(this.sendSave(trigger === 'pagehide'));
    }
  }

  resolveConflict(choice: ConflictChoice): void {
    this.apply({ type: 'resolveConflict', choice });
  }

  async startNewNotes(): Promise<void> {
    const freshKeys = await this.freshKeys();
    // Destroyed meanwhile: a save now would be one nothing tracks.
    if (this.destroyed) return;
    this.apply({ type: 'startNewNotes', freshKeys });
  }

  /**
   * The panel is going away (in-app navigation, another anketa, a logout).
   * Pending text is saved once more, and that's all: no retries, no
   * listeners. Whatever stays unsaved is in the backup, and closing the tab
   * warns about it (notesUnloadWarning.ts) until a panel for this
   * anketa saves it. Never touches a new panel's state.
   */
  destroy(): void {
    if (this.destroyed) return;
    this.destroyed = true;
    this.options.onChange = () => {};
    this.removeListeners();
    this.requestSave('destroy');
    this.refreshUnloadWarning();
    const key = this.chainKey;
    const previous = detachedPanels.get(key);
    const detached: DetachedPanel = {
      settled: Promise.all([previous?.settled, this.whenSettled()]).then(() => {
        this.refreshUnloadWarning();
        if (detachedPanels.get(key) === detached) detachedPanels.delete(key);
      }),
      yieldBackup: () => {
        previous?.yieldBackup();
        this.yielded = true;
      },
    };
    detachedPanels.set(key, detached);
  }

  /**
   * The beforeunload listener is attached only while something is unsaved:
   * Firefox keeps no page with one in its back-forward cache.
   */
  private syncUnloadWarning(before: NotesModel): void {
    const was = hasUnsavedWork(before);
    const now = hasUnsavedWork(this.model);
    if (now && !was)
      window.addEventListener('beforeunload', this.onBeforeUnload);
    if (was && !now) {
      window.removeEventListener('beforeunload', this.onBeforeUnload);
    }
  }

  private refreshUnloadWarning(): void {
    refreshNotesUnloadWarning(this.options.loggedInUserId());
  }

  private removeListeners(): void {
    window.removeEventListener('beforeunload', this.onBeforeUnload);
    window.removeEventListener('pagehide', this.onPageHide);
    document.removeEventListener('visibilitychange', this.onVisibilityChange);
  }

  private apply(event: NotesEvent): void {
    if (this.yielded) return;
    const before = this.model;
    this.model = notesReducer(before, event);
    if (this.model === before) return;
    this.options.onChange(this.model);
    this.syncBackup(before);
    this.syncUnloadWarning(before);
    if (this.model.status === 'retrying' && before.status !== 'retrying') {
      this.scheduleRetry();
    }
    if (this.model.status === 'dirty' && this.model.followUp) {
      this.requestSave('manual');
    }
  }

  /** Writes or clears the backup to match the model, one write at a time, latest state last. */
  private syncBackup(before: NotesModel): void {
    const { status } = this.model;
    if (status === 'loading' || status === 'loadError') return;
    if (status === 'unreadable') return;
    if (
      status !== 'idle' &&
      this.model.text === before.text &&
      this.model.sentSinceAck === before.sentSinceAck &&
      this.model.ackVersion === before.ackVersion
    ) {
      return;
    }
    this.backupPending = true;
    if (this.backupLoop) return;
    this.backupLoop = (async () => {
      // Deferred one tick: a loop that finished synchronously would clear
      // backupLoop in `finally` before this assignment, leaving it set for good.
      await Promise.resolve();
      try {
        while (this.backupPending && !this.yielded) {
          this.backupPending = false;
          const current = this.model;
          if (current.status === 'idle') {
            clearNotesBackup(this.options.userId, this.options.anketaId);
          } else {
            await writeNotesBackup(
              this.options.userId,
              this.options.anketaId,
              backupOf(current),
              this.options.backupKey,
              // Checked again after encrypting: a newer panel may own it by then.
              () => !this.yielded,
            );
          }
        }
      } finally {
        this.backupLoop = null;
        this.refreshUnloadWarning();
      }
    })();
  }

  private async sendSave(keepalive: boolean): Promise<void> {
    const { inFlightText, keys, ackVersion } = this.model;
    if (inFlightText === null || keys === null) return;
    try {
      const result = await this.put(inFlightText, keys, ackVersion, keepalive);
      if (result === null) {
        this.apply({ type: 'sessionEnded' });
        return;
      }
      this.nullConflictStreak = 0;
      this.apply({ type: 'saveSucceeded', version: result.version });
    } catch (error) {
      await this.onSaveError(error);
    }
  }

  /**
   * Encrypts and PUTs `text`. null, without a request, when the identity
   * generation moved meanwhile (a logout): nothing is ever sent then.
   */
  private async put(
    text: string,
    keys: NotesKeys,
    expectedVersion: number,
    keepalive: boolean,
  ): Promise<{ version: number } | null> {
    const notesBlob = await encryptNotes(
      text,
      keys.notesKey,
      this.associatedData,
    );
    if (this.options.getGeneration() !== this.options.generation) return null;
    // Every field is ASCII (base64, ids, a number): the JSON is about this long.
    const bodyLength = notesBlob.length + keys.encryptedNotesKey.length + 200;
    const sentKeepalive = keepalive && bodyLength < KEEPALIVE_QUOTA;
    return apiPut<{ version: number }>(
      this.url,
      {
        authorId: this.options.userId,
        encryptedNotesKey: keys.encryptedNotesKey,
        notesBlob,
        expectedVersion,
      },
      {
        keepalive: sentKeepalive,
        // Timed out too: if the page survives (the back-forward cache), a
        // stalled request mustn't leave the panel "Saving…". Once the page is
        // gone, no timer fires and the request goes on.
        signal: requestTimeout(bodyLength),
      },
    );
  }

  private async onSaveError(error: unknown): Promise<void> {
    if (!(error instanceof ApiError)) {
      this.apply({ type: 'saveFailed', failure: 'network' });
      return;
    }
    if (error.status === 409) {
      const body = error.body as ConflictBody | null;
      if (
        body?.encryptedNotesKey != null &&
        body.notesBlob != null &&
        body.version != null
      ) {
        this.nullConflictStreak = 0;
        this.apply({
          type: 'saveConflicted',
          row: await this.openRow({
            encryptedNotesKey: body.encryptedNotesKey,
            notesBlob: body.notesBlob,
            version: body.version,
          }),
        });
        return;
      }
      // A lost first-insert race, or no row at all: the server couldn't show
      // the row, so read it (still saving).
      await this.refetchAfterConflict();
      return;
    }
    if (error.status === 400 || error.status === 422) {
      const violations = (
        error.body as { violations?: { property?: string }[] }
      )?.violations;
      // The server's validation failures are 400s with translated messages
      // (docs/decisions/2026-09-26-private-notes-storage-and-api.md): the
      // notesBlob rule a client can break is the size cap.
      const tooLarge =
        violations?.some((violation) => violation.property === 'notesBlob') ??
        false;
      this.apply({
        type: 'saveFailed',
        failure: tooLarge ? 'tooLarge' : 'invalid',
        message: error.message,
      });
      return;
    }
    if (isNeedsReloadStatus(error.status)) {
      // Never retried: refreshing the token and retrying is exactly how text
      // could be sent under another user's session (§6.3).
      this.apply({ type: 'saveFailed', failure: 'sessionEnded' });
      return;
    }
    this.apply({ type: 'saveFailed', failure: 'network' });
  }

  private async refetchAfterConflict(): Promise<void> {
    try {
      const response = await apiGet<RowResponse | null>(this.url, {
        signal: requestTimeout(MAX_ROW_LENGTH),
      });
      if (response !== null) {
        this.nullConflictStreak = 0;
      } else {
        // A second "no row" in a row means the insert keeps failing for a
        // reason the client can't see: back off instead of looping.
        this.nullConflictStreak += 1;
        if (this.nullConflictStreak > 1) {
          this.nullConflictStreak = 0;
          this.apply({ type: 'saveFailed', failure: 'network' });
          return;
        }
      }
      this.apply({
        type: 'saveConflicted',
        row: response === null ? null : await this.openRow(response),
      });
    } catch (error) {
      this.apply({
        type: 'saveFailed',
        failure:
          error instanceof ApiError && isNeedsReloadStatus(error.status)
            ? 'sessionEnded'
            : 'network',
      });
    }
  }

  /** Opens a server row as far as it will: a key that won't open is never adopted. */
  private async openRow(response: RowResponse): Promise<ServerRow> {
    let notesKey: Uint8Array;
    try {
      notesKey = await unwrapNotesKey(
        response.encryptedNotesKey,
        this.options.publicKey,
        this.options.privateKey,
      );
    } catch {
      return { kind: 'keyUnreadable', version: response.version };
    }
    const keys = { notesKey, encryptedNotesKey: response.encryptedNotesKey };
    try {
      const text = await decryptNotes(
        response.notesBlob,
        notesKey,
        this.associatedData,
      );
      return { kind: 'opened', keys, text, version: response.version };
    } catch {
      return { kind: 'blobUnreadable', keys, version: response.version };
    }
  }

  private async freshKeys(): Promise<NotesKeys> {
    const notesKey = await generateNotesKey();
    return {
      notesKey,
      encryptedNotesKey: await wrapNotesKey(
        notesKey,
        this.options.publicKey,
        this.options.privateKey,
      ),
    };
  }

  private scheduleRetry(): void {
    this.clearRetry();
    const delay = RETRY_DELAYS_MS[this.model.retryCount - 1];
    // After the last automatic retry, the panel offers a manual one. A
    // destroyed panel doesn't retry: the next panel restores the backup.
    if (delay === undefined || this.destroyed) return;
    this.retryTimer = setTimeout(() => {
      this.retryTimer = null;
      this.requestSave('retryTimer');
    }, delay);
  }

  private track(request: Promise<void>): void {
    this.activeRequest = request;
    void request.finally(() => {
      if (this.activeRequest === request) this.activeRequest = null;
    });
  }

  /** Resolves once no request is in flight (a destroyed panel schedules no retries). */
  private async whenSettled(): Promise<void> {
    while (this.activeRequest) await this.activeRequest;
  }

  private clearDebounce(): void {
    if (this.debounceTimer) clearTimeout(this.debounceTimer);
    this.debounceTimer = null;
  }

  private clearRetry(): void {
    if (this.retryTimer) clearTimeout(this.retryTimer);
    this.retryTimer = null;
  }

  private readonly onBeforeUnload = (event: BeforeUnloadEvent): void => {
    if (hasUnsavedWork(this.model)) {
      event.preventDefault();
      // Older browsers need returnValue set to show the prompt.
      event.returnValue = '';
    }
  };

  private readonly onVisibilityChange = (): void => {
    // Switching to the video-call tab: save early. The page stays alive, so
    // a request in flight completes and this just sets a follow-up.
    if (document.visibilityState === 'hidden') this.requestSave('hidden');
  };

  private readonly onPageHide = (): void => {
    if (this.model.status !== 'saving') {
      this.requestSave('pagehide');
      return;
    }
    // The request in flight is a plain one (only pagehide itself sends
    // keepalive), and it's cancelled with the page: the latest text goes out
    // once more as keepalive, against the same version. If the first one
    // landed already, this one gets a 409 and the text typed during that
    // round trip is lost on a forced close; beforeunload has warned by then.
    // Sending against the next version too was tried and rejected: that
    // blindly overwrites whatever else took that version, another device's
    // save included.
    const { text, keys, ackVersion } = this.model;
    if (keys === null) return;
    this.apply({ type: 'unloadSaveSent' });
    this.put(text, keys, ackVersion, true).catch(() => {});
  };
}
