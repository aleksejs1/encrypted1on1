import { beforeEach, describe, expect, it } from 'vitest';
import {
  clearDraftBackup,
  loadDraftBackup,
  saveDraftBackup,
} from './draftBackup';
import { generateAnketaKey } from '../crypto/anketaKey';
import type { Answers } from './questions';

// The default `node` test environment has no sessionStorage global. A jsdom
// environment would provide one, but its TextEncoder output isn't a Uint8Array
// libsodium's WASM bindings recognize ("unsupported input type for message")
// — a real conflict hit while writing this test, not assumed. draftBackup.ts
// only ever calls getItem/setItem/removeItem, so a minimal in-memory stand-in
// is simpler and sidesteps the conflict entirely.
class MemoryStorage implements Storage {
  private store = new Map<string, string>();
  get length(): number {
    return this.store.size;
  }
  key(index: number): string | null {
    return Array.from(this.store.keys())[index] ?? null;
  }
  getItem(key: string): string | null {
    return this.store.has(key) ? this.store.get(key)! : null;
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

describe('draft backup', () => {
  it('round-trips answers through sessionStorage', async () => {
    const masterKey = await generateAnketaKey();
    const answers: Answers = { mood: 'good', notes: ['first entry'] };

    await saveDraftBackup('anketa-1', answers, masterKey);
    const loaded = await loadDraftBackup('anketa-1', masterKey);

    expect(loaded).toEqual(answers);
  });

  it('returns null when no backup exists', async () => {
    const masterKey = await generateAnketaKey();

    expect(await loadDraftBackup('anketa-missing', masterKey)).toBeNull();
  });

  it('returns null (not throws) when the backup is sealed under a different key', async () => {
    const masterKey = await generateAnketaKey();
    const wrongKey = await generateAnketaKey();
    await saveDraftBackup('anketa-1', { mood: 'good' }, masterKey);

    expect(await loadDraftBackup('anketa-1', wrongKey)).toBeNull();
  });

  it('clears the backup', async () => {
    const masterKey = await generateAnketaKey();
    await saveDraftBackup('anketa-1', { mood: 'good' }, masterKey);

    clearDraftBackup('anketa-1');

    expect(await loadDraftBackup('anketa-1', masterKey)).toBeNull();
  });

  it('keeps backups for different anketas independent', async () => {
    const masterKey = await generateAnketaKey();
    await saveDraftBackup('anketa-1', { mood: 'good' }, masterKey);
    await saveDraftBackup('anketa-2', { mood: 'bad' }, masterKey);

    clearDraftBackup('anketa-1');

    expect(await loadDraftBackup('anketa-1', masterKey)).toBeNull();
    expect(await loadDraftBackup('anketa-2', masterKey)).toEqual({
      mood: 'bad',
    });
  });
});
