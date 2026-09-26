import { describe, expect, it } from 'vitest';
import { MAX_NOTES_BLOB_LENGTH } from '../crypto/privateNotes';
import {
  backupOf,
  hasUnsavedWork,
  initialNotesModel,
  isEditable,
  keepBoth,
  notesReducer,
  notesSubtitleKey,
  type NotesBackup,
  type NotesEvent,
  type NotesKeys,
  type NotesModel,
  type ServerRow,
} from './notesState';

const fresh: NotesKeys = {
  notesKey: new Uint8Array([1]),
  encryptedNotesKey: 'fresh',
};
const serverKeys: NotesKeys = {
  notesKey: new Uint8Array([2]),
  encryptedNotesKey: 'server',
};
const otherKeys: NotesKeys = {
  notesKey: new Uint8Array([3]),
  encryptedNotesKey: 'other',
};

function opened(text: string, version: number, keys = serverKeys): ServerRow {
  return { kind: 'opened', keys, text, version };
}

function run(model: NotesModel, ...events: NotesEvent[]): NotesModel {
  return events.reduce(notesReducer, model);
}

function load(
  row: ServerRow | null,
  backup: NotesBackup | null = null,
): NotesModel {
  return run(initialNotesModel(), {
    type: 'loaded',
    row,
    backup,
    freshKeys: fresh,
  });
}

/** Idle at version 3 with text "base", saving "base plus". */
function savingModel(): NotesModel {
  return run(
    load(opened('base', 3)),
    { type: 'edited', text: 'base plus' },
    { type: 'saveRequested' },
  );
}

const tooLargeText = 'x'.repeat(MAX_NOTES_BLOB_LENGTH);

describe('loading', () => {
  it('opens an existing row as idle', () => {
    const model = load(opened('hello', 4));

    expect(model).toMatchObject({
      status: 'idle',
      text: 'hello',
      ackText: 'hello',
      ackVersion: 4,
      keys: serverKeys,
    });
  });

  it('starts empty with a fresh in-memory key when there is no row', () => {
    const model = load(null);

    expect(model).toMatchObject({
      status: 'idle',
      text: '',
      ackVersion: 0,
      keys: fresh,
      followUp: false,
    });
  });

  it.each<ServerRow>([
    { kind: 'keyUnreadable', version: 5 },
    { kind: 'blobUnreadable', keys: serverKeys, version: 5 },
  ])('shows a row that will not open as unreadable ($kind)', (row) => {
    const model = load(row);

    expect(model).toMatchObject({
      status: 'unreadable',
      keys: null,
      serverVersion: 5,
    });
    expect(isEditable(model)).toBe(false);
  });

  it('never treats a failed GET as "no row"', () => {
    const model = run(initialNotesModel(), {
      type: 'loadFailed',
      needsReload: false,
    });

    expect(model.status).toBe('loadError');
    expect(run(model, { type: 'retryLoad' }).status).toBe('loading');
  });

  it('offers no retry after a 401/403/404, only reload', () => {
    const model = run(initialNotesModel(), {
      type: 'loadFailed',
      needsReload: true,
    });

    expect(model.loadErrorNeedsReload).toBe(true);
    expect(run(model, { type: 'retryLoad' }).status).toBe('loadError');
  });
});

describe('reconciling the local backup on load (§6.4)', () => {
  it('uses the server alone when there is no backup', () => {
    expect(load(opened('S', 2), null)).toMatchObject({
      status: 'idle',
      text: 'S',
    });
  });

  it('restores a backup and inserts it when there is no row', () => {
    const model = load(null, { baseVersion: 0, sentTexts: [], text: 'B' });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'B',
      ackVersion: 0,
      keys: fresh,
      followUp: true,
    });
  });

  it("carries the backup's sent texts over when there is no row yet", () => {
    const model = load(null, {
      baseVersion: 0,
      sentTexts: ['abc'],
      text: 'abcdef',
    });

    const landed = run(
      model,
      { type: 'saveRequested' },
      { type: 'saveConflicted', row: opened('abc', 1) },
    );

    expect(landed.status).not.toBe('conflict');
  });

  it('discards a backup equal to the server text with nothing else sent', () => {
    const model = load(opened('same', 7), {
      baseVersion: 2,
      sentTexts: ['same'],
      text: 'same',
    });

    expect(model).toMatchObject({ status: 'idle', text: 'same' });
  });

  it('sends again when the text matches but another sent text may still land', () => {
    const model = load(opened('T0', 7), {
      baseVersion: 7,
      sentTexts: ['T0 with a line deleted later'],
      text: 'T0',
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'T0',
      sentSinceAck: ['T0 with a line deleted later'],
      followUp: true,
    });
    expect(run(model, { type: 'saveRequested' })).toMatchObject({
      status: 'saving',
      inFlightText: 'T0',
    });
  });

  it('keeps newer typing based on the current server version', () => {
    const model = load(opened('S', 4), {
      baseVersion: 4,
      sentTexts: [],
      text: 'S and more',
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'S and more',
      ackText: 'S',
      ackVersion: 4,
      followUp: true,
    });
  });

  it("carries the backup's sent texts over, so a late landing reads as our own", () => {
    const model = load(opened('S', 4), {
      baseVersion: 4,
      sentTexts: ['S, sent by the old panel'],
      text: 'S, sent by the old panel, and more',
    });

    const landed = run(
      model,
      { type: 'saveRequested' },
      { type: 'saveConflicted', row: opened('S, sent by the old panel', 5) },
    );

    expect(landed.status).not.toBe('conflict');
  });

  it("silently adopts this tab's own save whose response was lost", () => {
    const model = load(opened('sent earlier', 5), {
      baseVersion: 4,
      sentTexts: ['sent earlier'],
      text: 'sent earlier, then typed on',
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'sent earlier, then typed on',
      ackText: 'sent earlier',
      ackVersion: 5,
    });
  });

  it('never discards a backup when another tab or device wrote meanwhile', () => {
    const model = load(opened('from elsewhere', 6), {
      baseVersion: 4,
      sentTexts: ['mine'],
      text: 'my local text',
    });

    expect(model).toMatchObject({
      status: 'conflict',
      text: 'my local text',
      conflictServerText: 'from elsewhere',
      serverVersion: 6,
      keys: serverKeys,
    });
  });

  it('treats a backup ahead of the server version as a conflict', () => {
    const model = load(opened('S', 3), {
      baseVersion: 9,
      sentTexts: [],
      text: 'B',
    });

    expect(model.status).toBe('conflict');
  });

  it('keeps a conflict a conflict across a reload', () => {
    const conflicted = load(opened('from elsewhere', 6), {
      baseVersion: 4,
      sentTexts: [],
      text: 'mine',
    });
    const typedOn = run(conflicted, { type: 'edited', text: 'mine, more' });

    const reloaded = load(opened('from elsewhere', 6), backupOf(typedOn));

    expect(reloaded.status).toBe('conflict');
    expect(reloaded.text).toBe('mine, more');
  });

  it('remembers the backup text of an unreadable row for "Start new notes"', () => {
    const model = load(
      { kind: 'keyUnreadable', version: 5 },
      { baseVersion: 5, sentTexts: [], text: 'typed before the reset' },
    );

    expect(model.unreadableBackupText).toBe('typed before the reset');
  });
});

describe('typing', () => {
  it('goes dirty, and back to idle when the text returns to the saved one', () => {
    const dirty = run(load(opened('S', 1)), { type: 'edited', text: 'S!' });
    expect(dirty.status).toBe('dirty');

    expect(run(dirty, { type: 'edited', text: 'S' }).status).toBe('idle');
  });

  it('keeps saving, retrying and conflict while typing', () => {
    expect(run(savingModel(), { type: 'edited', text: 'more' })).toMatchObject({
      status: 'saving',
      text: 'more',
    });
    const retrying = run(savingModel(), {
      type: 'saveFailed',
      failure: 'network',
    });
    expect(run(retrying, { type: 'edited', text: 'more' }).status).toBe(
      'retrying',
    );
  });

  it('ignores typing while loading, failed or unreadable', () => {
    const loading = initialNotesModel();
    expect(run(loading, { type: 'edited', text: 'x' }).text).toBe('');
    const unreadable = load({ kind: 'keyUnreadable', version: 1 });
    expect(run(unreadable, { type: 'edited', text: 'x' }).text).toBe('');
  });
});

describe('saving', () => {
  it('sends the current text and remembers it as sent', () => {
    const model = savingModel();

    expect(model).toMatchObject({
      status: 'saving',
      inFlightText: 'base plus',
      sentSinceAck: ['base plus'],
    });
  });

  it('does nothing when idle', () => {
    const idle = load(opened('S', 1));

    expect(run(idle, { type: 'saveRequested' })).toBe(idle);
  });

  it('never starts a second request while one is in flight, but remembers to follow up', () => {
    const model = run(savingModel(), { type: 'saveRequested' });

    expect(model).toMatchObject({
      status: 'saving',
      inFlightText: 'base plus',
      followUp: true,
    });
  });

  it('stops without a request when the text is over the cap', () => {
    const model = run(
      load(opened('S', 1)),
      { type: 'edited', text: tooLargeText },
      { type: 'saveRequested' },
    );

    expect(model).toMatchObject({
      status: 'stopped',
      stoppedReason: 'tooLarge',
      inFlightText: null,
    });
  });

  it('resumes once typing brings the text back under the cap', () => {
    const stopped = run(
      load(opened('S', 1)),
      { type: 'edited', text: tooLargeText },
      { type: 'saveRequested' },
    );

    expect(run(stopped, { type: 'edited', text: 'short' }).status).toBe(
      'dirty',
    );
  });

  it('settles on 200, idle when nothing was typed meanwhile', () => {
    const model = run(savingModel(), { type: 'saveSucceeded', version: 4 });

    expect(model).toMatchObject({
      status: 'idle',
      ackText: 'base plus',
      ackVersion: 4,
      sentSinceAck: [],
      followUp: false,
    });
  });

  it('leaves text typed during the request to the debounce', () => {
    const model = run(
      savingModel(),
      { type: 'edited', text: 'base plus more' },
      { type: 'saveSucceeded', version: 4 },
    );

    expect(model).toMatchObject({ status: 'dirty', followUp: false });
  });

  it('saves again straight after a 200 when a trigger arrived during the request', () => {
    const model = run(
      savingModel(),
      { type: 'edited', text: 'base plus more' },
      { type: 'saveRequested' },
      { type: 'saveSucceeded', version: 4 },
    );

    expect(model).toMatchObject({ status: 'dirty', followUp: true });
  });

  it('still sends when typing returns to the saved text after a lost save', () => {
    const model = run(
      load(opened('A0', 1)),
      { type: 'edited', text: 'A0 minus a line' },
      { type: 'saveRequested' },
      { type: 'saveFailed', failure: 'network' },
      { type: 'edited', text: 'A0' },
      { type: 'saveRequested' },
    );

    expect(model).toMatchObject({ status: 'saving', inFlightText: 'A0' });
  });

  it('retries after a network failure, then sends the latest text', () => {
    const retrying = run(
      savingModel(),
      { type: 'saveFailed', failure: 'network' },
      { type: 'edited', text: 'latest' },
    );
    expect(retrying).toMatchObject({ status: 'retrying', retryCount: 1 });

    const retried = run(retrying, { type: 'saveRequested' });

    expect(retried).toMatchObject({
      status: 'saving',
      inFlightText: 'latest',
      sentSinceAck: ['base plus', 'latest'],
    });
  });

  it.each([
    ['sessionEnded' as const, 'sessionEnded'],
    ['tooLarge' as const, 'tooLarge'],
    ['invalid' as const, 'invalid'],
  ])('stops on %s', (failure, reason) => {
    const model = run(savingModel(), {
      type: 'saveFailed',
      failure,
      message: 'server says no',
    });

    expect(model).toMatchObject({ status: 'stopped', stoppedReason: reason });
  });

  it('never retries once the session ended, and keeps typing', () => {
    const stopped = run(savingModel(), {
      type: 'saveFailed',
      failure: 'sessionEnded',
    });

    const afterTyping = run(
      stopped,
      { type: 'edited', text: 'still typing' },
      { type: 'saveRequested' },
    );

    expect(afterTyping).toMatchObject({
      status: 'stopped',
      stoppedReason: 'sessionEnded',
      text: 'still typing',
      inFlightText: null,
    });
  });

  it('stops with no request when the identity generation moves', () => {
    expect(run(savingModel(), { type: 'sessionEnded' })).toMatchObject({
      status: 'stopped',
      stoppedReason: 'sessionEnded',
    });
  });

  it('leaves an unreadable panel as it is on logout, backup text and all', () => {
    const unreadable = load(
      { kind: 'keyUnreadable', version: 5 },
      { baseVersion: 5, sentTexts: [], text: 'restorable' },
    );

    expect(run(unreadable, { type: 'sessionEnded' })).toBe(unreadable);
  });

  it('leaves a panel with nothing unsaved as it is on logout', () => {
    const idle = load(opened('S', 1));

    expect(run(idle, { type: 'sessionEnded' })).toBe(idle);
  });

  it('records a re-sent text once, however often it is retried', () => {
    const model = run(
      savingModel(),
      { type: 'saveFailed', failure: 'network' },
      { type: 'saveRequested' },
      { type: 'saveFailed', failure: 'network' },
      { type: 'saveRequested' },
    );

    expect(model.sentSinceAck).toEqual(['base plus']);
  });

  it('saves the unload re-sent text once the page survives (back-forward cache)', () => {
    const model = run(
      savingModel(),
      { type: 'edited', text: 'typed after' },
      { type: 'unloadSaveSent' },
      { type: 'saveSucceeded', version: 4 },
    );

    expect(model).toMatchObject({ status: 'dirty', followUp: true });
  });

  it('remembers sent texts only up to a size budget, the newest always', () => {
    const big = (n: number) => `${n}`.padEnd(90_000, 'x');
    let model = savingModel();
    for (let i = 0; i < 4; i++) {
      model = run(
        model,
        { type: 'saveFailed', failure: 'network' },
        { type: 'edited', text: big(i) },
        { type: 'saveRequested' },
      );
    }

    // About two near-cap copies fit; older big ones are dropped, a small one kept.
    expect(model.sentSinceAck).toEqual(['base plus', big(2), big(3)]);
  });

  it('moves a re-sent text to the newest position', () => {
    const model = run(
      savingModel(),
      { type: 'saveFailed', failure: 'network' },
      { type: 'edited', text: 'second' },
      { type: 'saveRequested' },
      { type: 'saveFailed', failure: 'network' },
      { type: 'edited', text: 'base plus' },
      { type: 'saveRequested' },
    );

    expect(model.sentSinceAck).toEqual(['second', 'base plus']);
  });

  it('keeps small sent texts', () => {
    const model = run(
      savingModel(),
      { type: 'saveFailed', failure: 'network' },
      { type: 'edited', text: 'second' },
      { type: 'saveRequested' },
    );

    expect(model.sentSinceAck).toEqual(['base plus', 'second']);
  });

  it('counts the extra keepalive PUT of a pagehide as sent', () => {
    const model = run(
      savingModel(),
      { type: 'edited', text: 'typed after' },
      { type: 'unloadSaveSent' },
    );

    expect(model.sentSinceAck).toEqual(['base plus', 'typed after']);
  });
});

describe('a 409 while saving', () => {
  it("silently adopts this tab's own earlier save coming back", () => {
    const model = run(savingModel(), {
      type: 'saveConflicted',
      row: opened('base plus', 4, otherKeys),
    });

    expect(model).toMatchObject({
      status: 'idle',
      text: 'base plus',
      ackText: 'base plus',
      ackVersion: 4,
      keys: otherKeys,
      sentSinceAck: [],
    });
  });

  it('adopts the server text when it equals the textarea', () => {
    const model = run(
      savingModel(),
      { type: 'edited', text: 'typed the same elsewhere' },
      {
        type: 'saveConflicted',
        row: opened('typed the same elsewhere', 9),
      },
    );

    expect(model.status).toBe('idle');
  });

  it('does not undo a deletion made after a lost save', () => {
    const sentSecret = run(
      load(opened('abc', 1)),
      { type: 'edited', text: 'abc SECRET' },
      { type: 'saveRequested' },
      { type: 'saveFailed', failure: 'network' },
      { type: 'edited', text: 'abc' },
      { type: 'edited', text: 'abc again' },
      { type: 'saveRequested' },
    );

    const model = run(sentSecret, {
      type: 'saveConflicted',
      row: opened('abc SECRET', 2),
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'abc again',
      ackText: 'abc SECRET',
      ackVersion: 2,
      followUp: true,
    });
  });

  it("shows a conflict for another device's text, never merging silently", () => {
    const model = run(savingModel(), {
      type: 'saveConflicted',
      row: opened('from another device', 7, otherKeys),
    });

    expect(model).toMatchObject({
      status: 'conflict',
      text: 'base plus',
      conflictServerText: 'from another device',
      serverVersion: 7,
      keys: otherKeys,
      ackVersion: 3,
    });
    expect(hasUnsavedWork(model)).toBe(true);
  });

  it('offers only keeping this text when the server blob will not open', () => {
    const model = run(savingModel(), {
      type: 'saveConflicted',
      row: { kind: 'blobUnreadable', keys: otherKeys, version: 7 },
    });

    expect(model).toMatchObject({
      status: 'conflict',
      conflictServerText: null,
      keys: otherKeys,
    });
  });

  it('stops when the server key will not open (a reset in another session)', () => {
    const model = run(savingModel(), {
      type: 'saveConflicted',
      row: { kind: 'keyUnreadable', version: 7 },
    });

    expect(model).toMatchObject({
      status: 'stopped',
      stoppedReason: 'resetElsewhere',
    });
  });

  it('inserts again when a null 409 is followed by a GET finding no row', () => {
    const model = run(savingModel(), { type: 'saveConflicted', row: null });

    expect(model).toMatchObject({
      status: 'dirty',
      ackVersion: 0,
      followUp: true,
    });
  });
});

describe('resolving a conflict (§6.5)', () => {
  function conflicted(): NotesModel {
    return run(
      load(opened('from A', 2)),
      { type: 'edited', text: 'from B' },
      { type: 'saveRequested' },
      { type: 'saveConflicted', row: opened('from A, edited', 3, otherKeys) },
    );
  }

  it('keeps both with the server text first', () => {
    const model = run(conflicted(), {
      type: 'resolveConflict',
      choice: 'keepBoth',
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'from A, edited\n\n---\n\nfrom B',
      ackText: 'from A, edited',
      ackVersion: 3,
      keys: otherKeys,
      // Kept: a save of this tab's still in flight must read as its own.
      sentSinceAck: ['from B'],
      followUp: true,
    });
  });

  it("keeps this tab's text", () => {
    const model = run(conflicted(), {
      type: 'resolveConflict',
      choice: 'keepLocal',
    });

    expect(model).toMatchObject({ status: 'dirty', text: 'from B' });
  });

  it('loads the saved version, and saves it once more over anything of this tab still in flight', () => {
    const model = run(conflicted(), {
      type: 'resolveConflict',
      choice: 'loadServer',
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'from A, edited',
      ackText: 'from A, edited',
      followUp: true,
    });
  });

  it('settles idle only once nothing sent is unaccounted for', () => {
    const model = load(null, { baseVersion: 0, sentTexts: ['abc'], text: '' });

    expect(model).toMatchObject({ status: 'dirty', followUp: true });
  });

  it('pauses saving until resolved, while typing continues', () => {
    const model = run(
      conflicted(),
      { type: 'edited', text: 'from B, more' },
      { type: 'saveRequested' },
    );

    expect(model).toMatchObject({ status: 'conflict', text: 'from B, more' });
  });

  it('saves this text over an unopenable server blob', () => {
    const model = run(
      savingModel(),
      {
        type: 'saveConflicted',
        row: { kind: 'blobUnreadable', keys: otherKeys, version: 8 },
      },
      { type: 'resolveConflict', choice: 'keepLocal' },
    );

    expect(model).toMatchObject({
      status: 'dirty',
      text: 'base plus',
      ackVersion: 8,
      followUp: true,
    });
  });
});

describe('start new notes over an unreadable row', () => {
  it('replaces the row with a fresh key, even when empty', () => {
    const model = run(load({ kind: 'keyUnreadable', version: 5 }), {
      type: 'startNewNotes',
      freshKeys: fresh,
    });

    expect(model).toMatchObject({
      status: 'dirty',
      text: '',
      keys: fresh,
      ackText: null,
      ackVersion: 5,
      followUp: true,
    });
  });

  it('restores the backup text', () => {
    const model = run(
      load(
        { kind: 'keyUnreadable', version: 5 },
        { baseVersion: 5, sentTexts: [], text: 'from the backup' },
      ),
      { type: 'startNewNotes', freshKeys: fresh },
    );

    expect(model.text).toBe('from the backup');
  });
});

describe('keepBoth', () => {
  it('keeps the local text when it extends the server text', () => {
    expect(keepBoth('abc', 'abc def')).toBe('abc def');
  });

  it('keeps the server text when it extends the local text', () => {
    expect(keepBoth('abc def', 'abc')).toBe('abc def');
  });

  it('otherwise puts the server text first, then a separator', () => {
    expect(keepBoth('from A', 'from B')).toBe('from A\n\n---\n\nfrom B');
  });
});

describe('hasUnsavedWork', () => {
  it('warns before unload in every state that could lose text', () => {
    const idle = load(opened('S', 1));
    const dirty = run(idle, { type: 'edited', text: 'x' });

    expect(hasUnsavedWork(idle)).toBe(false);
    expect(hasUnsavedWork(dirty)).toBe(true);
    expect(hasUnsavedWork(savingModel())).toBe(true);
    expect(hasUnsavedWork(run(dirty, { type: 'sessionEnded' }))).toBe(true);
  });
});

describe('notesSubtitleKey', () => {
  it('says "only you" normally, and warns other visitors can read on the demo account', () => {
    expect(notesSubtitleKey(false)).toBe('privateNotes.subtitle');
    expect(notesSubtitleKey(true)).toBe('privateNotes.demoSubtitle');
  });
});
