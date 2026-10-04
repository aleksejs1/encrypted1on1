/**
 * "Remember this browser" (GitHub issue #205): the master key, kept in
 * IndexedDB so a new tab or a restarted browser can unlock without the
 * password. Opt-in at login, and only while the server session is a
 * remembered one — see docs/decisions/2026-10-04-remember-this-browser.md for
 * who writes and deletes it.
 *
 * The key is encrypted with an AES-GCM key created as non-extractable, stored
 * beside it. That stops a script from reading the wrapping key's bytes, and
 * nothing more: any code running on this origin can ask the browser to
 * decrypt, and both live in the browser profile on disk. Someone who can use
 * this browser, or copy its profile, has the master key.
 *
 * No function here rejects. A browser without IndexedDB (or with it blocked)
 * simply doesn't remember, and the password is asked for as before. Callers
 * don't wait for a write or a delete (`void`): the queue below keeps them in
 * order, and a slow or stuck store must not hold up a login or a logout.
 */
const DB_NAME = 'e1o1';
const STORE = 'remembered';
const RECORD_ID = 'master-key';

interface RememberedRecord {
  wrappingKey: CryptoKey;
  iv: Uint8Array<ArrayBuffer>;
  ciphertext: ArrayBuffer;
  /** Milliseconds on this browser's own clock: when the remembered login ends. */
  expiresAt: number;
  /** Whose key it is: the user's public key, base64, as the server sends it. */
  owner: string;
}

/**
 * How long opening the database may take. IndexedDB can hang without ever
 * failing (a blocked or broken profile database), and unlocking a tab waits
 * for a read. Giving up at this step keeps the order below intact: an
 * operation that never got a database has done nothing.
 */
const OPEN_TIMEOUT_MS = 3000;

/** The database can't be opened: expected in some browsers, so not logged. */
class StoreUnavailable extends Error {}

/**
 * Runs the operations one at a time, in the order they were called: a logout
 * that follows a still-running write deletes what it wrote, not the reverse.
 * A failed operation gives `fallback`; so does every operation in a browser
 * with no usable IndexedDB.
 */
let queue: Promise<unknown> = Promise.resolve();

function enqueue<T>(operation: () => Promise<T>, fallback: T): Promise<T> {
  const result = queue.then(operation).catch((error: unknown) => {
    if (!(error instanceof StoreUnavailable)) console.error(error);
    return fallback;
  });
  queue = result;
  return result;
}

function requestResult<T>(request: IDBRequest<T>): Promise<T> {
  return new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    let open: IDBOpenDBRequest;
    try {
      open = indexedDB.open(DB_NAME, 1);
    } catch {
      reject(new StoreUnavailable());
      return;
    }
    let gaveUp = false;
    const timer = setTimeout(() => {
      gaveUp = true;
      reject(new StoreUnavailable());
    }, OPEN_TIMEOUT_MS);
    open.onupgradeneeded = () => {
      open.result.createObjectStore(STORE);
    };
    open.onerror = () => {
      clearTimeout(timer);
      reject(new StoreUnavailable());
    };
    open.onsuccess = () => {
      clearTimeout(timer);
      // Too late to be used: nothing is waiting for it any more.
      if (gaveUp) open.result.close();
      else resolve(open.result);
    };
  });
}

/**
 * One transaction on the store. `work` must not await anything but its own
 * requests: a transaction commits as soon as it has nothing pending.
 */
async function inStore<T>(
  mode: IDBTransactionMode,
  work: (store: IDBObjectStore) => Promise<T>,
): Promise<T> {
  const db = await openDatabase();
  try {
    const transaction = db.transaction(STORE, mode);
    const done = new Promise<void>((resolve, reject) => {
      transaction.oncomplete = () => resolve();
      transaction.onerror = () => reject(transaction.error);
      transaction.onabort = () => reject(transaction.error);
    });
    // Awaited together, so a failed request doesn't leave the abort that
    // follows it as an unhandled rejection.
    const [result] = await Promise.all([
      work(transaction.objectStore(STORE)),
      done,
    ]);
    return result;
  } finally {
    db.close();
  }
}

/** The stored record, or null. One past its end date is deleted, not returned. */
async function readLiveRecord(
  store: IDBObjectStore,
): Promise<RememberedRecord | null> {
  const record = await requestResult(
    store.get(RECORD_ID) as IDBRequest<RememberedRecord | undefined>,
  );
  if (record === undefined) return null;
  // A record without a usable end date counts as past it.
  if (!(Date.now() <= record.expiresAt)) {
    await requestResult(store.delete(RECORD_ID));
    return null;
  }
  return record;
}

async function encrypt(
  masterKey: Uint8Array,
): Promise<Pick<RememberedRecord, 'wrappingKey' | 'iv' | 'ciphertext'>> {
  const wrappingKey = await crypto.subtle.generateKey(
    { name: 'AES-GCM', length: 256 },
    false,
    ['encrypt', 'decrypt'],
  );
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ciphertext = await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv },
    wrappingKey,
    new Uint8Array(masterKey),
  );
  return { wrappingKey, iv, ciphertext };
}

/**
 * Stores the key, replacing whatever was remembered. `secondsLeft` is what
 * the server says the remembered login still has; the end date is counted
 * from it on this browser's own clock, so a clock that disagrees with the
 * server's doesn't end the key early or late.
 */
export function rememberMasterKey(
  masterKey: Uint8Array,
  secondsLeft: number,
  owner: string,
): Promise<void> {
  const expiresAt = Date.now() + secondsLeft * 1000;
  return enqueue(async () => {
    const record: RememberedRecord = {
      ...(await encrypt(masterKey)),
      expiresAt,
      owner,
    };
    await inStore('readwrite', (store) =>
      requestResult(store.put(record, RECORD_ID)),
    );
  }, undefined);
}

/**
 * Puts a key in place of the one remembered for the same user, keeping its
 * end date: after a password change, and after "Unlock this tab" in a
 * browser whose remembered key no longer worked. Does nothing in a browser
 * that remembers no key, or another user's — which is what makes it safe
 * from a tab that can't know whether another tab has logged out since: a
 * logout deletes the record, and this never brings one back.
 */
export function replaceRememberedMasterKey(
  masterKey: Uint8Array,
  owner: string,
): Promise<void> {
  return enqueue(async () => {
    const encrypted = await encrypt(masterKey);
    await inStore('readwrite', async (store) => {
      const existing = await readLiveRecord(store);
      if (existing === null || existing.owner !== owner) return;
      const record: RememberedRecord = { ...existing, ...encrypted };
      await requestResult(store.put(record, RECORD_ID));
    });
  }, undefined);
}

/** The remembered key, or null. A key past its end date is deleted, not returned. */
export function loadRememberedMasterKey(): Promise<Uint8Array | null> {
  return enqueue(async () => {
    const record = await inStore('readwrite', readLiveRecord);
    if (record === null) return null;
    return new Uint8Array(
      await crypto.subtle.decrypt(
        { name: 'AES-GCM', iv: record.iv },
        record.wrappingKey,
        record.ciphertext,
      ),
    );
  }, null);
}

/**
 * Deletes a key past its end date. Run on every page load, logged in or not,
 * so a key doesn't stay on disk after its login has ended on the server.
 */
export function discardExpiredRememberedKey(): Promise<void> {
  return enqueue(async () => {
    await inStore('readwrite', readLiveRecord);
  }, undefined);
}

export function forgetRememberedMasterKey(): Promise<void> {
  return enqueue(async () => {
    await inStore('readwrite', (store) =>
      requestResult(store.delete(RECORD_ID)),
    );
  }, undefined);
}
