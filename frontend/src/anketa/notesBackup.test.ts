import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { encryptBlob, generateAnketaKey } from '../crypto/anketaKey';
import {
  clearNotesBackup,
  discardUnopenableNotesBackups,
  hasNotesBackups,
  readNotesBackup,
  writeNotesBackup,
} from './notesBackup';

// The node environment has no sessionStorage, and jsdom's TextEncoder output
// isn't a Uint8Array libsodium accepts — the same stand-in as draftBackup.test.ts.
class MemoryStorage implements Storage {
  private store = new Map<string, string>();
  get length(): number {
    return this.store.size;
  }
  key(index: number): string | null {
    return Array.from(this.store.keys())[index] ?? null;
  }
  getItem(key: string): string | null {
    return this.store.get(key) ?? null;
  }
  setItem(key: string, value: string): void {
    this.store.set(key, value);
  }
  removeItem(key: string): void {
    this.store.delete(key);
  }
  clear(): void {
    this.store.clear();
  }
}

beforeEach(() => {
  globalThis.sessionStorage = new MemoryStorage();
});

const backup = {
  baseVersion: 3,
  sentTexts: ['sent'],
  text: 'typed before logging out',
};

afterEach(() => {
  vi.restoreAllMocks();
});

describe('notes backup', () => {
  it('round-trips under the same master key', async () => {
    const masterKey = await generateAnketaKey();

    await writeNotesBackup('user-1', 'anketa-1', backup, masterKey);

    expect(await readNotesBackup('user-1', 'anketa-1', masterKey)).toEqual(
      backup,
    );
  });

  it('stores ciphertext, not the text', async () => {
    await writeNotesBackup(
      'user-1',
      'anketa-1',
      backup,
      await generateAnketaKey(),
    );

    const stored = sessionStorage.getItem('e1o1:notes-backup:user-1:anketa-1');
    expect(stored).not.toBeNull();
    expect(stored).not.toContain('typed before');
  });

  it("never reads another user's backup", async () => {
    const masterKey = await generateAnketaKey();
    await writeNotesBackup('user-1', 'anketa-1', backup, masterKey);

    expect(await readNotesBackup('user-2', 'anketa-1', masterKey)).toBeNull();
  });

  it('ignores and removes a backup that will not open (an old keypair)', async () => {
    await writeNotesBackup(
      'user-1',
      'anketa-1',
      backup,
      await generateAnketaKey(),
    );

    expect(
      await readNotesBackup('user-1', 'anketa-1', await generateAnketaKey()),
    ).toBeNull();
    expect(hasNotesBackups('user-1')).toBe(false);
  });

  it('ignores a backup of the wrong shape', async () => {
    const masterKey = await generateAnketaKey();
    sessionStorage.setItem(
      'e1o1:notes-backup:user-1:anketa-1',
      await encryptBlob({ text: 1 }, masterKey),
    );

    expect(await readNotesBackup('user-1', 'anketa-1', masterKey)).toBeNull();
  });

  it('never throws when storage is full', async () => {
    vi.spyOn(sessionStorage, 'setItem').mockImplementation(() => {
      throw new DOMException('full', 'QuotaExceededError');
    });

    await expect(
      writeNotesBackup('u', 'a', backup, await generateAnketaKey()),
    ).resolves.toBeUndefined();
  });

  it('clears', async () => {
    const masterKey = await generateAnketaKey();
    await writeNotesBackup('user-1', 'anketa-1', backup, masterKey);

    clearNotesBackup('user-1', 'anketa-1');

    expect(await readNotesBackup('user-1', 'anketa-1', masterKey)).toBeNull();
  });

  it('tells whether a user has any backup left, and never counts another user', async () => {
    const backupKey = await generateAnketaKey();
    expect(hasNotesBackups('user-1')).toBe(false);

    await writeNotesBackup('user-1', 'anketa-1', backup, backupKey);

    expect(hasNotesBackups('user-1')).toBe(true);
    expect(hasNotesBackups('user-2')).toBe(false);
    clearNotesBackup('user-1', 'anketa-1');
    expect(hasNotesBackups('user-1')).toBe(false);
  });

  it("discards only the backups that no longer open, and only this user's", async () => {
    const oldKey = await generateAnketaKey();
    const newKey = await generateAnketaKey();
    await writeNotesBackup('user-1', 'old-keypair', backup, oldKey);
    await writeNotesBackup('user-1', 'current', backup, newKey);
    await writeNotesBackup('user-2', 'someone-else', backup, oldKey);

    await discardUnopenableNotesBackups('user-1', newKey);

    expect(await readNotesBackup('user-1', 'current', newKey)).toEqual(backup);
    expect(
      sessionStorage.getItem('e1o1:notes-backup:user-1:old-keypair'),
    ).toBeNull();
    expect(
      sessionStorage.getItem('e1o1:notes-backup:user-2:someone-else'),
    ).not.toBeNull();
  });

  it("counts anyone's backups when asked for no user", async () => {
    await writeNotesBackup('user-1', 'a', backup, await generateAnketaKey());

    expect(hasNotesBackups(null)).toBe(true);
  });
});
