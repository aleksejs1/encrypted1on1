import {
  MAX_NOTES_BLOB_LENGTH,
  encodedNotesLength,
} from '../crypto/privateNotes';

/**
 * The private-notes panel's state machine (GitHub issue #132 §6.3–§6.5), as a
 * pure reducer. The panel's controller (notesSession.ts) performs the requests
 * and feeds their outcomes back in as events; everything that decides what the
 * panel shows or saves next lives here, so every transition is unit-tested.
 *
 * One status enum over the reachable states, not independent booleans
 * (docs/architecture-invariants.md §2).
 */
type NotesStatus =
  /** GET + decrypt in progress; textarea disabled. */
  | 'loading'
  /** GET failed. Network/5xx offers Retry; 401/403/404 offers only Reload. */
  | 'loadError'
  /** The server row won't open (a reset, a swapped key, corruption); only "Start new notes". */
  | 'unreadable'
  /** text === ackText, nothing in flight. */
  | 'idle'
  /** text !== ackText, nothing in flight. */
  | 'dirty'
  /** Exactly one PUT (or the re-GET after a null 409) in flight. */
  | 'saving'
  /** The last request hit network/5xx; a retry is scheduled or offered. */
  | 'retrying'
  /** An unresolved 409 against another tab's or device's text; saving paused, typing allowed. */
  | 'conflict'
  /** Saving can't continue in this tab; see stoppedReason. Typing still allowed. */
  | 'stopped';

type StoppedReason = 'tooLarge' | 'invalid' | 'sessionEnded' | 'resetElsewhere';

export interface NotesKeys {
  notesKey: Uint8Array;
  encryptedNotesKey: string;
}

/** A server row, as far as this client could open it. */
export type ServerRow =
  | { kind: 'opened'; keys: NotesKeys; text: string; version: number }
  /** The key opened (it's ours) but the blob didn't. */
  | { kind: 'blobUnreadable'; keys: NotesKeys; version: number }
  /** The key didn't open: not boxed by this keypair. */
  | { kind: 'keyUnreadable'; version: number };

/** The tab-local backup (notesBackup.ts), already decrypted. */
export interface NotesBackup {
  /** ackVersion when it was written. */
  baseVersion: number;
  /** sentSinceAck when it was written. */
  sentTexts: string[];
  text: string;
}

export interface NotesModel {
  status: NotesStatus;
  stoppedReason: StoppedReason | null;
  /** The server's message, for stopped('invalid'). */
  invalidMessage: string | null;
  /** loadError only: 401/403/404, so Retry is pointless and only Reload is offered. */
  loadErrorNeedsReload: boolean;
  text: string;
  /**
   * The last text the server confirmed as ours. `null` when there's nothing
   * of ours on the server to compare with (after "Start new notes" over an
   * unreadable row, or when a conflict's server text couldn't be opened), so
   * any text counts as unsaved.
   */
  ackText: string | null;
  /** The server version that ackText is at; the next save's expectedVersion. 0 = no row. */
  ackVersion: number;
  /**
   * The texts this tab has PUT since ackVersion. A 409 or a reload finding one
   * of these on the server is this tab's own save coming back (a retry, or a
   * lost response), not another tab's write. Cleared on every 200, every
   * silent adoption and every conflict resolution.
   */
  sentSinceAck: string[];
  /** The text of the PUT in flight, while saving. */
  inFlightText: string | null;
  /** A save should start as soon as the current one finishes (or right away, if dirty). */
  followUp: boolean;
  /** Consecutive network/5xx failures, for the 2s/5s/15s/manual retry schedule. */
  retryCount: number;
  /** The key and wrapped key saves use. null while loading, or while unreadable. */
  keys: NotesKeys | null;
  /** conflict: the server's text, or null if its blob couldn't be opened (then only "keep this tab's text"). */
  conflictServerText: string | null;
  /** conflict / unreadable: the server row's version, adopted once resolved. */
  serverVersion: number;
  /** unreadable: the backup's text, restored by "Start new notes". */
  unreadableBackupText: string | null;
}

export type ConflictChoice = 'keepBoth' | 'keepLocal' | 'loadServer';

type SaveFailure = 'network' | 'sessionEnded' | 'tooLarge' | 'invalid';

export type NotesEvent =
  | {
      type: 'loaded';
      row: ServerRow | null;
      backup: NotesBackup | null;
      /**
       * Present when there's no row yet: a key generated in memory, persisted
       * by the first save. null when a row came back.
       */
      freshKeys: NotesKeys | null;
    }
  | { type: 'loadFailed'; needsReload: boolean }
  | { type: 'retryLoad' }
  | { type: 'edited'; text: string }
  /** The debounce, blur, a hidden page, destroy, a retry timer or button: start a save if there's one to start. */
  | { type: 'saveRequested' }
  | { type: 'saveSucceeded'; version: number }
  | { type: 'saveFailed'; failure: SaveFailure; message?: string }
  /**
   * A 409 with the current row, opened. `null` only after a 409 with nulls
   * was followed by a re-GET that found no row.
   */
  | { type: 'saveConflicted'; row: ServerRow | null }
  /** A pagehide while saving: an extra keepalive PUT of the current text is going out. */
  | { type: 'unloadSaveSent' }
  /** Logout or session expiry in this tab (the identity generation moved). */
  | { type: 'sessionEnded' }
  | { type: 'resolveConflict'; choice: ConflictChoice }
  | { type: 'startNewNotes'; freshKeys: NotesKeys };

export function initialNotesModel(): NotesModel {
  return {
    status: 'loading',
    stoppedReason: null,
    invalidMessage: null,
    loadErrorNeedsReload: false,
    text: '',
    ackText: '',
    ackVersion: 0,
    sentSinceAck: [],
    inFlightText: null,
    followUp: false,
    retryCount: 0,
    keys: null,
    conflictServerText: null,
    serverVersion: 0,
    unreadableBackupText: null,
  };
}

function isTooLarge(text: string): boolean {
  return encodedNotesLength(text) > MAX_NOTES_BLOB_LENGTH;
}

/**
 * "Keep both" (§6.5): if one text extends the other, the longer one; else the
 * server's text, a separator, then this tab's. Shared text can repeat in the
 * last case — visible and easy to fix by hand, unlike a clever merge.
 */
export function keepBoth(server: string, local: string): string {
  if (local.startsWith(server)) return local;
  if (server.startsWith(local)) return server;
  return `${server}\n\n---\n\n${local}`;
}

/**
 * The panel's always-visible privacy line. On the shared demo account anyone
 * with the published password can read the notes, so it says that instead
 * (design decision 8, §10).
 */
export function notesSubtitleKey(
  isDemo: boolean,
): 'privateNotes.demoSubtitle' | 'privateNotes.subtitle' {
  return isDemo ? 'privateNotes.demoSubtitle' : 'privateNotes.subtitle';
}

/** Whether leaving the page now could lose text, so beforeunload should warn. */
export function hasUnsavedWork(model: NotesModel): boolean {
  return (
    // Backup text "Start new notes" would restore, saved nowhere else.
    (model.status === 'unreadable' && model.unreadableBackupText !== null) ||
    model.status === 'dirty' ||
    model.status === 'saving' ||
    model.status === 'retrying' ||
    model.status === 'conflict' ||
    model.status === 'stopped'
  );
}

/** Whether the textarea accepts input. */
export function isEditable(model: NotesModel): boolean {
  return (
    model.status !== 'loading' &&
    model.status !== 'loadError' &&
    model.status !== 'unreadable'
  );
}

/**
 * Settled, not saving: idle when the text is what the server has and nothing
 * this tab sent is unaccounted for; dirty otherwise. A sent text whose
 * response never came may have landed, so it must still be overwritten.
 */
function settle(model: NotesModel): NotesModel {
  const saved = model.text === model.ackText && model.sentSinceAck.length === 0;
  return { ...model, status: saved ? 'idle' : 'dirty', stoppedReason: null };
}

function stop(
  model: NotesModel,
  reason: StoppedReason,
  message: string | null = null,
): NotesModel {
  return {
    ...model,
    status: 'stopped',
    stoppedReason: reason,
    invalidMessage: message,
    inFlightText: null,
    followUp: false,
  };
}

function reconcileLoaded(
  model: NotesModel,
  row: ServerRow | null,
  backup: NotesBackup | null,
  freshKeys: NotesKeys | null,
): NotesModel {
  if (row === null) {
    if (freshKeys === null) {
      // Can't happen: the controller generates keys whenever there's no row.
      return { ...model, status: 'loadError', loadErrorNeedsReload: false };
    }
    // No row yet. A backup (typed before a logout, say) is restored and
    // inserted with expectedVersion 0. Its sent texts carry over, as below.
    const loaded = {
      ...model,
      keys: freshKeys,
      ackText: '',
      ackVersion: 0,
      text: backup?.text ?? '',
      sentSinceAck: backup?.sentTexts ?? [],
    };
    const settled = settle(loaded);
    return { ...settled, followUp: settled.status === 'dirty' };
  }

  if (row.kind !== 'opened') {
    return {
      ...model,
      status: 'unreadable',
      keys: null,
      serverVersion: row.version,
      unreadableBackupText: backup?.text ?? null,
    };
  }

  const opened = {
    ...model,
    keys: row.keys,
    ackText: row.text,
    ackVersion: row.version,
    text: row.text,
  };
  const otherSent = (backup?.sentTexts ?? []).filter(
    (sent) => sent !== row.text,
  );
  if (backup === null || (backup.text === row.text && otherSent.length === 0)) {
    return settle(opened);
  }
  if (backup.text === row.text) {
    // The text is what the server has, but a save of other text from this tab
    // may still land. Send it again: a stale save then fails its version check,
    // or, if it already landed, reads as this tab's own and is overwritten.
    return {
      ...opened,
      status: 'dirty',
      sentSinceAck: otherSent,
      followUp: true,
    };
  }
  // This tab's typing is newer than the server row it was based on, or the
  // server row is one of this tab's own saves whose response was lost.
  if (
    backup.baseVersion === row.version ||
    backup.sentTexts.includes(row.text)
  ) {
    // The backup's sent texts carry over: a save the previous panel sent may
    // still land after this load, and must read as this tab's own.
    return {
      ...settle({
        ...opened,
        text: backup.text,
        sentSinceAck: otherSent,
      }),
      followUp: true,
    };
  }
  // Another tab or device wrote meanwhile: never silently discarded.
  return enterConflict(
    {
      ...opened,
      text: backup.text,
      ackText: null,
      ackVersion: backup.baseVersion,
      sentSinceAck: otherSent,
    },
    row.keys,
    row.text,
    row.version,
  );
}

/**
 * Pauses saving against a server row that isn't this tab's. ackText and
 * ackVersion stay where they were until the conflict is resolved, so a
 * backup written meanwhile still reconciles as a conflict after a reload,
 * instead of looking like a newer edit of the server row.
 */
function enterConflict(
  model: NotesModel,
  keys: NotesKeys,
  serverText: string | null,
  serverVersion: number,
): NotesModel {
  return {
    ...model,
    status: 'conflict',
    stoppedReason: null,
    keys,
    conflictServerText: serverText,
    serverVersion,
    inFlightText: null,
    followUp: false,
    retryCount: 0,
  };
}

function onConflictRow(model: NotesModel, row: ServerRow | null): NotesModel {
  if (row === null) {
    // The 409 said nulls and a re-GET found no row: insert again.
    return {
      ...model,
      status: 'dirty',
      ackText: null,
      ackVersion: 0,
      inFlightText: null,
      followUp: true,
    };
  }
  if (row.kind === 'keyUnreadable') {
    return stop(model, 'resetElsewhere');
  }
  if (row.kind === 'blobUnreadable') {
    return enterConflict(model, row.keys, null, row.version);
  }
  if (model.sentSinceAck.includes(row.text) || row.text === model.text) {
    // This tab's own save coming back (a retried or lost save): adopt it
    // silently. The textarea is left as it is.
    const adopted = settle({
      ...model,
      keys: row.keys,
      ackText: row.text,
      ackVersion: row.version,
      sentSinceAck: [],
      inFlightText: null,
      retryCount: 0,
    });
    return { ...adopted, followUp: adopted.status === 'dirty' };
  }
  return enterConflict(model, row.keys, row.text, row.version);
}

/**
 * How much unacknowledged sent text is remembered, in characters. Each entry
 * is a full copy of the notes that goes into the backup on every keystroke,
 * so a long outage mustn't grow the backup toward the storage quota: near the
 * cap, that's the newest sent text alone. An older text landing late then
 * reads as a conflict, which "Keep both" resolves without losing anything.
 */
const MAX_SENT_CHARACTERS = 200_000;

/**
 * Adds a sent text as the newest (moving it there if it was sent before):
 * the newest always, older ones, newest first, while they fit the budget.
 */
function withSent(sent: string[], text: string): string[] {
  const kept = [text];
  let total = text.length;
  for (const older of sent.filter((s) => s !== text).reverse()) {
    if (total + older.length > MAX_SENT_CHARACTERS) continue;
    total += older.length;
    kept.unshift(older);
  }
  return kept;
}

function onSaveRequested(model: NotesModel): NotesModel {
  switch (model.status) {
    case 'saving':
      return { ...model, followUp: true };
    case 'dirty':
    case 'retrying': {
      // Back to the saved text with nothing sent since: nothing to save. With
      // a sent text whose response was lost, the server may hold it, so it's
      // still sent (a deletion undone to the saved text must not be lost).
      if (model.text === model.ackText && model.sentSinceAck.length === 0) {
        return { ...settle(model), followUp: false, retryCount: 0 };
      }
      if (isTooLarge(model.text)) {
        return stop(model, 'tooLarge');
      }
      return {
        ...model,
        status: 'saving',
        inFlightText: model.text,
        sentSinceAck: withSent(model.sentSinceAck, model.text),
        followUp: false,
      };
    }
    default:
      return model;
  }
}

function onEdited(model: NotesModel, text: string): NotesModel {
  switch (model.status) {
    case 'idle':
    case 'dirty':
      return settle({ ...model, text });
    case 'saving':
    case 'retrying':
    case 'conflict':
      return { ...model, text };
    case 'stopped':
      if (model.stoppedReason === 'tooLarge' && !isTooLarge(text)) {
        return settle({ ...model, text });
      }
      // Typing stays allowed, and the backup keeps it: only Reload leaves.
      return { ...model, text };
    default:
      // loading, loadError, unreadable: the textarea is disabled.
      return model;
  }
}

function onResolveConflict(
  model: NotesModel,
  choice: ConflictChoice,
): NotesModel {
  const server = model.conflictServerText;
  let text = model.text;
  if (server !== null && choice === 'loadServer') text = server;
  if (server !== null && choice === 'keepBoth') text = keepBoth(server, text);
  // sentSinceAck is kept: a save of this tab's that is still in flight (a
  // destroyed panel's) may land after this, and must read as its own.
  const resolved = settle({
    ...model,
    text,
    ackText: server,
    ackVersion: model.serverVersion,
    conflictServerText: null,
  });
  return { ...resolved, followUp: resolved.status === 'dirty' };
}

export function notesReducer(model: NotesModel, event: NotesEvent): NotesModel {
  switch (event.type) {
    case 'loaded':
      if (model.status !== 'loading') return model;
      return reconcileLoaded(model, event.row, event.backup, event.freshKeys);

    case 'loadFailed':
      if (model.status !== 'loading') return model;
      return {
        ...model,
        status: 'loadError',
        loadErrorNeedsReload: event.needsReload,
      };

    case 'retryLoad':
      if (model.status !== 'loadError' || model.loadErrorNeedsReload) {
        return model;
      }
      return { ...model, status: 'loading' };

    case 'edited':
      return onEdited(model, event.text);

    case 'saveRequested':
      return onSaveRequested(model);

    case 'saveSucceeded': {
      if (model.status !== 'saving') return model;
      const acked = settle({
        ...model,
        ackText: model.inFlightText,
        ackVersion: event.version,
        sentSinceAck: [],
        inFlightText: null,
        retryCount: 0,
      });
      // Straight on only if a trigger (the debounce, blur, a hidden tab)
      // arrived meanwhile. Otherwise the debounce set by the typing decides,
      // so steady typing saves about once a second, not once per round trip.
      return {
        ...acked,
        followUp: acked.status === 'dirty' && model.followUp,
      };
    }

    case 'saveFailed':
      if (model.status !== 'saving') return model;
      if (event.failure === 'network') {
        return {
          ...model,
          status: 'retrying',
          inFlightText: null,
          followUp: false,
          retryCount: model.retryCount + 1,
        };
      }
      return stop(model, event.failure, event.message ?? null);

    case 'saveConflicted':
      if (model.status !== 'saving') return model;
      return onConflictRow(model, event.row);

    case 'unloadSaveSent':
      if (model.status !== 'saving') return model;
      // followUp: if the page survives (the back-forward cache), the text is
      // saved once the request in flight finishes.
      return {
        ...model,
        sentSinceAck: withSent(model.sentSinceAck, model.text),
        followUp: true,
      };

    case 'sessionEnded':
      // Nothing unsaved (idle, or never loaded): nothing to stop or warn about.
      // Unreadable stays as it is: stopping it would show an empty textarea
      // whose first keystroke overwrites the restorable backup.
      if (!hasUnsavedWork(model) || model.status === 'unreadable') {
        return model;
      }
      if (model.stoppedReason === 'sessionEnded') return model;
      return stop(model, 'sessionEnded');

    case 'resolveConflict':
      if (model.status !== 'conflict') return model;
      return onResolveConflict(model, event.choice);

    case 'startNewNotes': {
      if (model.status !== 'unreadable') return model;
      const started = settle({
        ...model,
        keys: event.freshKeys,
        text: model.unreadableBackupText ?? '',
        ackText: null,
        ackVersion: model.serverVersion,
        unreadableBackupText: null,
      });
      // Always saved, even when empty: the unreadable row must be replaced.
      return { ...started, status: 'dirty', followUp: true };
    }
  }
}

/** What the tab-local backup should hold for this model. */
export function backupOf(model: NotesModel): NotesBackup {
  return {
    baseVersion: model.ackVersion,
    sentTexts: model.sentSinceAck,
    text: model.text,
  };
}
