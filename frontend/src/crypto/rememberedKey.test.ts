import { IDBFactory } from 'fake-indexeddb';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  discardExpiredRememberedKey,
  forgetRememberedMasterKey,
  loadRememberedMasterKey,
  rememberMasterKey,
  replaceRememberedMasterKey,
} from './rememberedKey';

const NOW = new Date('2026-10-04T12:00:00Z');
const THIRTY_DAYS = 30 * 24 * 60 * 60;

const OWNER = 'public-key-of-the-user';
const masterKey = new Uint8Array(32).fill(7);
const otherKey = new Uint8Array(32).fill(9);

interface StoredRecord {
  wrappingKey: CryptoKey;
  iv: Uint8Array;
  ciphertext: ArrayBuffer;
  expiresAt: number;
}

/** What is actually on disk, read without the module under test. */
function storedRecord(): Promise<StoredRecord | undefined> {
  return new Promise((resolve, reject) => {
    const open = indexedDB.open('e1o1', 1);
    open.onupgradeneeded = () => {
      open.result.createObjectStore('remembered');
    };
    open.onerror = () => reject(open.error);
    open.onsuccess = () => {
      const db = open.result;
      const get = db
        .transaction('remembered')
        .objectStore('remembered')
        .get('master-key');
      get.onerror = () => reject(get.error);
      get.onsuccess = () => {
        db.close();
        resolve(get.result as StoredRecord | undefined);
      };
    };
  });
}

beforeEach(() => {
  vi.useFakeTimers({ now: NOW, toFake: ['Date'] });
  globalThis.indexedDB = new IDBFactory();
});

afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('rememberMasterKey / loadRememberedMasterKey', () => {
  it('returns null when nothing is remembered', async () => {
    expect(await loadRememberedMasterKey()).toBeNull();
  });

  it('round-trips the key', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
  });

  it('stores the key encrypted, under a wrapping key that cannot be exported', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);

    const record = await storedRecord();
    expect(record?.wrappingKey.extractable).toBe(false);
    expect(record?.wrappingKey.algorithm.name).toBe('AES-GCM');
    // Counted on this browser's clock, in milliseconds.
    expect(record?.expiresAt).toBe(NOW.getTime() + THIRTY_DAYS * 1000);
    // 32 bytes of key plus the 16-byte GCM tag, and not the key itself.
    const ciphertext = new Uint8Array(record?.ciphertext ?? new ArrayBuffer(0));
    expect(ciphertext).toHaveLength(48);
    expect(ciphertext.slice(0, 32)).not.toEqual(masterKey);
    await expect(
      crypto.subtle.exportKey('raw', record!.wrappingKey),
    ).rejects.toThrow();
  });

  it('replaces a key remembered earlier', async () => {
    await rememberMasterKey(otherKey, THIRTY_DAYS, OWNER);
    await rememberMasterKey(masterKey, THIRTY_DAYS + 5, OWNER);

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
    expect((await storedRecord())?.expiresAt).toBe(
      NOW.getTime() + (THIRTY_DAYS + 5) * 1000,
    );
  });

  it('uses a fresh wrapping key and nonce each time', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);
    const first = await storedRecord();
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);
    const second = await storedRecord();

    expect(second?.iv).not.toEqual(first?.iv);
    expect(new Uint8Array(second!.ciphertext)).not.toEqual(
      new Uint8Array(first!.ciphertext),
    );
  });
});

describe('expiry', () => {
  it('still returns a key exactly at its end', async () => {
    await rememberMasterKey(masterKey, 60, OWNER);
    vi.setSystemTime(NOW.getTime() + 60_000);

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
  });

  it('deletes a key past its end instead of returning it', async () => {
    await rememberMasterKey(masterKey, 60, OWNER);
    vi.setSystemTime(NOW.getTime() + 60_001);

    expect(await loadRememberedMasterKey()).toBeNull();
    expect(await storedRecord()).toBeUndefined();
  });

  it('discardExpiredRememberedKey() deletes a key past its end', async () => {
    await rememberMasterKey(masterKey, 60, OWNER);
    vi.setSystemTime(NOW.getTime() + 60_001);

    await discardExpiredRememberedKey();

    expect(await storedRecord()).toBeUndefined();
  });

  it('discardExpiredRememberedKey() keeps a key that has not ended', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);

    await discardExpiredRememberedKey();

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
  });

  it('discardExpiredRememberedKey() does nothing when nothing is remembered', async () => {
    await discardExpiredRememberedKey();

    expect(await storedRecord()).toBeUndefined();
  });

  it('treats a key stored without a usable end date as past it', async () => {
    await rememberMasterKey(masterKey, Number.NaN, OWNER);

    expect(await loadRememberedMasterKey()).toBeNull();
    expect(await storedRecord()).toBeUndefined();
  });

  it('a password change does not bring back a key past its end', async () => {
    await rememberMasterKey(masterKey, 60, OWNER);
    vi.setSystemTime(NOW.getTime() + 60_001);

    await replaceRememberedMasterKey(otherKey, OWNER);

    expect(await storedRecord()).toBeUndefined();
  });
});

describe('replaceRememberedMasterKey', () => {
  it('puts the new key in place of a remembered one, keeping its end', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);

    await replaceRememberedMasterKey(otherKey, OWNER);

    expect(await loadRememberedMasterKey()).toEqual(otherKey);
    expect((await storedRecord())?.expiresAt).toBe(
      NOW.getTime() + THIRTY_DAYS * 1000,
    );
  });

  it("leaves another user's key alone", async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, 'someone-else');

    await replaceRememberedMasterKey(otherKey, OWNER);

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
  });

  it('does not start remembering in a browser that remembers nothing', async () => {
    await replaceRememberedMasterKey(otherKey, OWNER);

    expect(await storedRecord()).toBeUndefined();
  });
});

describe('forgetRememberedMasterKey', () => {
  it('deletes the key', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);

    await forgetRememberedMasterKey();

    expect(await loadRememberedMasterKey()).toBeNull();
    expect(await storedRecord()).toBeUndefined();
  });

  it('is fine with nothing to delete', async () => {
    await forgetRememberedMasterKey();

    expect(await storedRecord()).toBeUndefined();
  });

  it('wins over a write that was started first and is still running', async () => {
    const remembering = rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);
    const forgetting = forgetRememberedMasterKey();
    await Promise.all([remembering, forgetting]);

    expect(await storedRecord()).toBeUndefined();
  });

  it('loses to a write started after it', async () => {
    const forgetting = forgetRememberedMasterKey();
    const remembering = rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);
    await Promise.all([forgetting, remembering]);

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
  });
});

describe('a browser without IndexedDB', () => {
  beforeEach(() => {
    // @ts-expect-error -- what a browser with IndexedDB turned off looks like.
    delete globalThis.indexedDB;
  });

  it('remembers nothing, rejects nothing and logs nothing', async () => {
    const logged = vi.spyOn(console, 'error');

    await expect(
      rememberMasterKey(masterKey, THIRTY_DAYS, OWNER),
    ).resolves.toBeUndefined();
    await expect(
      replaceRememberedMasterKey(masterKey, OWNER),
    ).resolves.toBeUndefined();
    await expect(loadRememberedMasterKey()).resolves.toBeNull();
    await expect(discardExpiredRememberedKey()).resolves.toBeUndefined();
    await expect(forgetRememberedMasterKey()).resolves.toBeUndefined();
    expect(logged).not.toHaveBeenCalled();
  });

  it('keeps working once a later operation can reach the store again', async () => {
    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);
    globalThis.indexedDB = new IDBFactory();

    await rememberMasterKey(masterKey, THIRTY_DAYS, OWNER);

    expect(await loadRememberedMasterKey()).toEqual(masterKey);
  });
});

describe('a browser whose IndexedDB never answers', () => {
  beforeEach(() => {
    vi.useFakeTimers({ now: NOW });
    // open() hands back a request that neither succeeds nor fails.
    globalThis.indexedDB = {
      open: () => ({}),
    } as unknown as IDBFactory;
  });

  it('gives up on each operation after a few seconds, in order, without logging', async () => {
    const logged = vi.spyOn(console, 'error');
    const loading = loadRememberedMasterKey();
    const forgetting = forgetRememberedMasterKey();
    let loaded = false;
    void loading.then(() => {
      loaded = true;
    });

    await vi.advanceTimersByTimeAsync(2999);
    expect(loaded).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    await expect(loading).resolves.toBeNull();
    await vi.advanceTimersByTimeAsync(3000);
    await expect(forgetting).resolves.toBeUndefined();
    expect(logged).not.toHaveBeenCalled();
  });
});
