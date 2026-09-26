import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { NotesBackup, NotesModel } from './notesState';

/**
 * The controller in isolation: the API, the crypto and the backup are mocked,
 * so these tests are about when requests go out and what happens to their
 * outcomes (GitHub issue #132 §6.3). The real crypto and the real stack are
 * covered by crypto/privateNotes.test.ts and e2e/private-notes.spec.ts.
 */

const api = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPut: vi.fn(),
}));

vi.mock('../api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../api/client')>();
  return {
    ApiError: actual.ApiError,
    apiGet: api.apiGet,
    apiPut: api.apiPut,
    warmCsrfToken: () => Promise.resolve(),
  };
});

vi.mock('../crypto/privateNotes', () => ({
  MAX_NOTES_BLOB_LENGTH: 262_144,
  encodedNotesLength: (text: string) => text.length,
  encryptNotes: (text: string) => Promise.resolve(`blob:${text}`),
  decryptNotes: (blob: string) => Promise.resolve(blob.slice('blob:'.length)),
  unwrapNotesKey: (key: string) =>
    key === 'foreign'
      ? Promise.reject(new Error('no'))
      : Promise.resolve(new Uint8Array([1])),
  wrapNotesKey: () => Promise.resolve('wrapped'),
  generateNotesKey: () => Promise.resolve(new Uint8Array([9])),
  notesAssociatedData: () => new Uint8Array([0]),
}));

const backups = new Map<string, NotesBackup>();
vi.mock('./notesBackup', () => ({
  writeNotesBackup: (userId: string, anketa: string, backup: NotesBackup) => {
    backups.set(`${userId}:${anketa}`, backup);
    return Promise.resolve();
  },
  readNotesBackup: (userId: string, anketa: string) =>
    Promise.resolve(backups.get(`${userId}:${anketa}`) ?? null),
  clearNotesBackup: (userId: string, anketa: string) => {
    backups.delete(`${userId}:${anketa}`);
  },
  hasNotesBackups: (userId: string | null) =>
    [...backups.keys()].some(
      (key) => userId === null || key.startsWith(`${userId}:`),
    ),
}));

const { ApiError } = await import('../api/client');
const { NotesSession } = await import('./notesSession');
const { refreshNotesUnloadWarning } = await import('./notesUnloadWarning');

interface Deferred<T> {
  promise: Promise<T>;
  resolve: (value: T) => void;
  reject: (error: unknown) => void;
}

function deferred<T>(): Deferred<T> {
  let resolve!: (value: T) => void;
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

/**
 * Each test starts on its own generation and its own user. The unload warning
 * reads the logged-in user's backups (notesUnloadWarning.ts), and backups here
 * are keyed by user, so a previous test's leftovers never make it fire.
 */
let generation = 0;
let testGeneration = 0;
/** Per test too: the unload warning is per user, and entries are module-level. */
let currentUser = 'user-0';
const listeners = new Map<string, Set<EventListener>>();

function stubPageTarget(): EventTarget & Record<string, unknown> {
  return {
    addEventListener: (type: string, listener: EventListener) => {
      if (!listeners.has(type)) listeners.set(type, new Set());
      listeners.get(type)!.add(listener);
    },
    removeEventListener: (type: string, listener: EventListener) => {
      listeners.get(type)?.delete(listener);
    },
    dispatchEvent: () => true,
    visibilityState: 'visible',
  };
}

/**
 * Each test gets its own anketa id too: the controller keeps detached panels
 * at module level, keyed by user and anketa, and one test's must never hold up
 * another's load.
 */
let anketaId = 'anketa-0';

function makeSession() {
  const models: NotesModel[] = [];
  const session = new NotesSession({
    anketaId,
    userId: currentUser,
    publicKey: new Uint8Array([1]),
    privateKey: new Uint8Array([2]),
    backupKey: new Uint8Array([3]),
    generation,
    getGeneration: () => generation,
    loggedInUserId: () => currentUser,
    onChange: (model) => models.push(model),
  });
  return { session, models };
}

/** Runs every registered beforeunload listener, as closing the tab would. */
function beforeUnloadWarns(): boolean {
  let prevented = false;
  const event = {
    preventDefault: () => {
      prevented = true;
    },
    returnValue: undefined,
  };
  for (const listener of listeners.get('beforeunload') ?? []) {
    listener(event as unknown as Event);
  }
  return prevented;
}

function putBodies(): { notesBlob: string; expectedVersion: number }[] {
  return api.apiPut.mock.calls.map(
    (call) => call[1] as { notesBlob: string; expectedVersion: number },
  );
}

beforeEach(() => {
  vi.useFakeTimers();
  testGeneration += 100;
  generation = testGeneration;
  currentUser = `user-${testGeneration}`;
  anketaId = `anketa-${Math.random()}`;
  backups.clear();
  listeners.clear();
  api.apiGet.mockReset();
  api.apiPut.mockReset();
  globalThis.window = stubPageTarget() as unknown as Window & typeof globalThis;
  globalThis.document = stubPageTarget() as unknown as Document;
});

afterEach(() => {
  vi.useRealTimers();
});

describe('NotesSession', () => {
  it('saves one second after the last keystroke, inserting at version 0', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockResolvedValue({ version: 1 });
    const { session } = makeSession();
    await session.load();

    session.edit('first');
    await vi.advanceTimersByTimeAsync(999);
    expect(api.apiPut).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);

    expect(putBodies()).toEqual([
      expect.objectContaining({ notesBlob: 'blob:first', expectedVersion: 0 }),
    ]);
    expect(session.getModel().status).toBe('idle');
  });

  it('keeps steady typing to about one save a second, not one per round trip', async () => {
    api.apiGet.mockResolvedValue(null);
    const firstPut = deferred<{ version: number }>();
    api.apiPut
      .mockReturnValueOnce(firstPut.promise)
      .mockResolvedValue({ version: 2 });
    const { session } = makeSession();
    await session.load();

    session.edit('a');
    await vi.advanceTimersByTimeAsync(1000);
    session.edit('ab');
    firstPut.resolve({ version: 1 });
    await vi.advanceTimersByTimeAsync(0);

    expect(api.apiPut).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1000);
    expect(api.apiPut).toHaveBeenCalledTimes(2);
    expect(putBodies()[1]).toMatchObject({
      notesBlob: 'blob:ab',
      expectedVersion: 1,
    });
  });

  it('while retrying, typing waits for the backoff instead of sending', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut
      .mockRejectedValueOnce(new TypeError('offline'))
      .mockResolvedValue({ version: 1 });
    const { session } = makeSession();
    await session.load();
    session.edit('a');
    await vi.advanceTimersByTimeAsync(1000);
    expect(session.getModel().status).toBe('retrying');

    session.edit('ab');
    session.requestSave('blur');
    await vi.advanceTimersByTimeAsync(1500);
    expect(api.apiPut).toHaveBeenCalledTimes(1);

    await vi.advanceTimersByTimeAsync(500);
    expect(api.apiPut).toHaveBeenCalledTimes(2);
    expect(putBodies()[1].notesBlob).toBe('blob:ab');
  });

  it('backs off instead of looping when an insert keeps getting a null 409', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockRejectedValue(
      new ApiError(409, 'conflict', {
        encryptedNotesKey: null,
        notesBlob: null,
        version: null,
      }),
    );
    const { session } = makeSession();
    await session.load();

    session.edit('a');
    await vi.advanceTimersByTimeAsync(1000);

    expect(api.apiPut).toHaveBeenCalledTimes(2);
    expect(session.getModel().status).toBe('retrying');
  });

  it('never sends after the identity generation moves (a logout)', async () => {
    api.apiGet.mockResolvedValue(null);
    const { session } = makeSession();
    await session.load();

    session.edit('typed');
    generation += 1;
    await vi.advanceTimersByTimeAsync(1000);

    expect(api.apiPut).not.toHaveBeenCalled();
    expect(session.getModel()).toMatchObject({
      status: 'stopped',
      stoppedReason: 'sessionEnded',
    });
    expect(backups.get(`${currentUser}:${anketaId}`)?.text).toBe('typed');
  });

  it('saves pending text on destroy, and a new panel waits for that save before loading', async () => {
    api.apiGet.mockResolvedValue(null);
    const put = deferred<{ version: number }>();
    api.apiPut.mockReturnValue(put.promise);
    const old = makeSession();
    await old.session.load();
    old.session.edit('unsaved on navigation');

    old.session.destroy();
    await vi.advanceTimersByTimeAsync(0);
    expect(putBodies()[0].notesBlob).toBe('blob:unsaved on navigation');

    api.apiGet.mockClear();
    const fresh = makeSession();
    const loading = fresh.session.load();
    await vi.advanceTimersByTimeAsync(0);
    expect(api.apiGet).not.toHaveBeenCalled();

    api.apiGet.mockResolvedValue({
      encryptedNotesKey: 'wrapped',
      notesBlob: 'blob:unsaved on navigation',
      version: 1,
    });
    put.resolve({ version: 1 });
    await loading;

    expect(fresh.session.getModel()).toMatchObject({
      status: 'idle',
      text: 'unsaved on navigation',
    });
  });

  it('a destroyed panel stops retrying: the next panel restores its backup instead', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockRejectedValue(new TypeError('offline'));
    const old = makeSession();
    await old.session.load();
    old.session.edit('offline text');
    await vi.advanceTimersByTimeAsync(1000);
    expect(old.session.getModel().status).toBe('retrying');

    old.session.destroy();
    await vi.advanceTimersByTimeAsync(0);
    const putsAfterDestroy = api.apiPut.mock.calls.length;
    await vi.advanceTimersByTimeAsync(30_000);

    expect(api.apiPut.mock.calls.length).toBe(putsAfterDestroy);
    expect(backups.get(`${currentUser}:${anketaId}`)?.text).toBe(
      'offline text',
    );
  });

  it('keeps warning before unload for a destroyed panel with unsaved text, until a panel for it loads', async () => {
    api.apiGet.mockResolvedValue({
      encryptedNotesKey: 'wrapped',
      notesBlob: 'blob:server',
      version: 3,
    });
    api.apiPut.mockRejectedValue(
      new ApiError(409, 'conflict', {
        encryptedNotesKey: 'wrapped',
        notesBlob: 'blob:from another device',
        version: 4,
      }),
    );
    const old = makeSession();
    await old.session.load();
    old.session.edit('mine');
    await vi.advanceTimersByTimeAsync(1000);
    expect(old.session.getModel().status).toBe('conflict');

    old.session.destroy();
    await vi.advanceTimersByTimeAsync(0);
    expect(beforeUnloadWarns()).toBe(true);

    // The server now holds the other device's text.
    api.apiGet.mockResolvedValue({
      encryptedNotesKey: 'wrapped',
      notesBlob: 'blob:from another device',
      version: 4,
    });
    const fresh = makeSession();
    await fresh.session.load();

    expect(fresh.session.getModel().status).toBe('conflict');
    // The new panel warns on its own now; resolving leaves nothing to warn about.
    fresh.session.resolveConflict('loadServer');
    // It saves the loaded text once more (the old panel's text may still be in
    // flight); that settles as the server's own text.
    await vi.advanceTimersByTimeAsync(0);
    expect(fresh.session.getModel().status).toBe('idle');
    expect(beforeUnloadWarns()).toBe(false);
  });

  it("an old panel's late 200 after the handover never clears the new panel's backup", async () => {
    api.apiGet.mockResolvedValue(null);
    const hung = deferred<{ version: number }>();
    api.apiPut.mockReturnValueOnce(hung.promise);
    const old = makeSession();
    await old.session.load();
    old.session.edit('old text');
    old.session.destroy();
    await vi.advanceTimersByTimeAsync(0);

    // The old save hangs past the wait; the new panel loads and types on.
    api.apiPut.mockReturnValue(new Promise(() => {}));
    const fresh = makeSession();
    const loading = fresh.session.load();
    await vi.advanceTimersByTimeAsync(5000);
    await loading;
    fresh.session.edit('old text, then new typing');
    await vi.advanceTimersByTimeAsync(0);
    expect(backups.get(`${currentUser}:${anketaId}`)?.text).toBe(
      'old text, then new typing',
    );

    hung.resolve({ version: 1 });
    await vi.advanceTimersByTimeAsync(0);

    expect(backups.get(`${currentUser}:${anketaId}`)?.text).toBe(
      'old text, then new typing',
    );
  });

  it('keeps the old panel warning when the new panel fails to load', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockRejectedValue(new TypeError('offline'));
    const old = makeSession();
    await old.session.load();
    old.session.edit('only in the backup');
    await vi.advanceTimersByTimeAsync(1000);
    old.session.destroy();
    await vi.advanceTimersByTimeAsync(0);

    api.apiGet.mockRejectedValue(new TypeError('offline'));
    const fresh = makeSession();
    await fresh.session.load();

    expect(fresh.session.getModel().status).toBe('loadError');
    // The text is only in the backup, so closing the tab still warns.
    expect(beforeUnloadWarns()).toBe(true);
  });

  it('leaves no unload warning behind after logging out with everything saved', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockResolvedValue({ version: 1 });
    const { session } = makeSession();
    await session.load();
    session.edit('saved');
    await vi.advanceTimersByTimeAsync(1000);
    expect(session.getModel().status).toBe('idle');

    generation += 1;
    session.destroy();
    await vi.advanceTimersByTimeAsync(0);

    expect(session.getModel().status).toBe('idle');
    expect(beforeUnloadWarns()).toBe(false);
  });

  it('warns while the save made on destroy is still in flight', async () => {
    api.apiGet.mockResolvedValue(null);
    const put = deferred<{ version: number }>();
    api.apiPut.mockReturnValue(put.promise);
    const { session } = makeSession();
    await session.load();
    session.edit('typed just before navigating');

    session.destroy();
    await vi.advanceTimersByTimeAsync(0);
    expect(beforeUnloadWarns()).toBe(true);

    put.resolve({ version: 1 });
    await vi.advanceTimersByTimeAsync(0);
    expect(beforeUnloadWarns()).toBe(false);
  });

  it("isn't held up by an older panel's hung request", async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockReturnValueOnce(new Promise(() => {}));
    const a = makeSession();
    await a.session.load();
    a.session.edit('from A');
    a.session.destroy();

    const b = makeSession();
    const loading = b.session.load();
    await vi.advanceTimersByTimeAsync(5000);
    await loading;
    api.apiPut.mockRejectedValue(new TypeError('offline'));
    b.session.edit('from B');
    await vi.advanceTimersByTimeAsync(1000);
    b.session.destroy();
    await vi.advanceTimersByTimeAsync(0);

    expect(beforeUnloadWarns()).toBe(true);
  });

  it('still warns after a handover when the new panel then fails to load', async () => {
    api.apiGet.mockResolvedValue(null);
    const put = deferred<{ version: number }>();
    api.apiPut.mockReturnValue(put.promise);
    const old = makeSession();
    await old.session.load();
    old.session.edit('only in the backup');
    old.session.destroy();

    api.apiGet.mockRejectedValue(new TypeError('offline'));
    const fresh = makeSession();
    const loading = fresh.session.load();
    await vi.advanceTimersByTimeAsync(5000);
    await loading;
    put.reject(new TypeError('offline'));
    await vi.advanceTimersByTimeAsync(0);

    expect(fresh.session.getModel().status).toBe('loadError');
    expect(beforeUnloadWarns()).toBe(true);
  });

  it('on pagehide, re-sends as keepalive over a plain save still in flight', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockReturnValue(new Promise(() => {}));
    const { session } = makeSession();
    await session.load();
    session.edit('last line');
    await vi.advanceTimersByTimeAsync(1000);
    expect(api.apiPut.mock.calls[0][2]).toMatchObject({ keepalive: false });

    for (const listener of listeners.get('pagehide') ?? []) {
      listener(new Event('pagehide'));
    }
    await vi.advanceTimersByTimeAsync(0);

    expect(api.apiPut).toHaveBeenCalledTimes(2);
    expect(api.apiPut.mock.calls[1][1]).toMatchObject({
      notesBlob: 'blob:last line',
    });
    expect(api.apiPut.mock.calls[1][2]).toMatchObject({ keepalive: true });
  });

  it('saves plainly, with a timeout, when the tab is hidden; keepalive is for pagehide only', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockResolvedValue({ version: 1 });
    const { session } = makeSession();
    await session.load();
    session.edit('switching to the call');
    session.requestSave('hidden');
    await vi.advanceTimersByTimeAsync(0);

    expect(api.apiPut.mock.calls[0][2]).toMatchObject({ keepalive: false });
    expect(api.apiPut.mock.calls[0][2].signal).toBeInstanceOf(AbortSignal);
  });

  it('on pagehide with newer text, re-sends it once as keepalive against the same version', async () => {
    api.apiGet.mockResolvedValue({
      encryptedNotesKey: 'wrapped',
      notesBlob: 'blob:S0',
      version: 4,
    });
    api.apiPut.mockReturnValue(new Promise(() => {}));
    const { session } = makeSession();
    await session.load();
    session.edit('S1');
    await vi.advanceTimersByTimeAsync(1000);
    session.edit('S1 S2');

    for (const listener of listeners.get('pagehide') ?? []) {
      listener(new Event('pagehide'));
    }
    await vi.advanceTimersByTimeAsync(0);

    expect(putBodies().slice(1)).toEqual([
      expect.objectContaining({ notesBlob: 'blob:S1 S2', expectedVersion: 4 }),
    ]);
    expect(api.apiPut.mock.calls[1][2]).toMatchObject({ keepalive: true });
  });

  it('keeps warning for the same user after logging out and back in, never for another', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockRejectedValue(new TypeError('offline'));
    const { session } = makeSession();
    await session.load();
    session.edit('unsaved');
    await vi.advanceTimersByTimeAsync(1000);
    session.destroy();
    await vi.advanceTimersByTimeAsync(0);
    const me = currentUser;

    // App.svelte re-evaluates the warning on every login and logout.
    const switchUser = (user: string | null) => {
      currentUser = user ?? 'nobody';
      refreshNotesUnloadWarning(user);
    };
    // Logged out: any backup warns (the person who just logged out).
    switchUser(null);
    expect(beforeUnloadWarns()).toBe(true);
    switchUser('someone-else');
    expect(beforeUnloadWarns()).toBe(false);
    switchUser(me);
    expect(beforeUnloadWarns()).toBe(true);
  });

  it('attaches its unload warning only while something is unsaved', async () => {
    api.apiGet.mockResolvedValue(null);
    api.apiPut.mockResolvedValue({ version: 1 });
    const { session } = makeSession();
    await session.load();
    expect(listeners.get('beforeunload')?.size ?? 0).toBe(0);

    session.edit('typing');
    expect(listeners.get('beforeunload')?.size).toBe(1);
    await vi.advanceTimersByTimeAsync(1000);

    expect(session.getModel().status).toBe('idle');
    expect(listeners.get('beforeunload')?.size ?? 0).toBe(0);
  });
});
