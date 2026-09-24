import { describe, expect, it } from 'vitest';
import { decryptDraft, hasAnyAnswer, migrateLegacyDrafts } from './drafts';
import { ApiError } from '../api/client';
import { encryptBlob, generateAnketaKey } from '../crypto/anketaKey';
import type { Answers } from './questions';

describe('decryptDraft', () => {
  it('decrypts a draft saved under the draft key', async () => {
    const draftKey = await generateAnketaKey();
    const masterKey = await generateAnketaKey();
    const answers: Answers = { mood: 'good', notes: ['entry'] };

    const blob = await encryptBlob(answers, draftKey);

    expect(await decryptDraft(blob, draftKey, masterKey)).toEqual({
      answers,
      legacy: false,
    });
  });

  it('falls back to the master key for a draft saved before drafts moved off it', async () => {
    const draftKey = await generateAnketaKey();
    const masterKey = await generateAnketaKey();
    const answers: Answers = { mood: 'okay' };

    const blob = await encryptBlob(answers, masterKey);

    expect(await decryptDraft(blob, draftKey, masterKey)).toEqual({
      answers,
      legacy: true,
    });
  });

  it('returns null instead of throwing when neither key opens the draft', async () => {
    const lostKey = await generateAnketaKey();
    const draftKey = await generateAnketaKey();
    const masterKey = await generateAnketaKey();

    const blob = await encryptBlob({ mood: 'good' }, lostKey);

    expect(await decryptDraft(blob, draftKey, masterKey)).toBeNull();
    expect(await decryptDraft(blob, draftKey, null)).toBeNull();
  });

  it('does not try a missing master key', async () => {
    const draftKey = await generateAnketaKey();
    const masterKey = await generateAnketaKey();

    const blob = await encryptBlob({ mood: 'good' }, masterKey);

    expect(await decryptDraft(blob, draftKey, null)).toBeNull();
  });

  it('returns null for a corrupt blob', async () => {
    const draftKey = await generateAnketaKey();

    expect(await decryptDraft('not-a-real-blob', draftKey, null)).toBeNull();
  });
});

describe('hasAnyAnswer', () => {
  it('is false for a blank form', () => {
    expect(hasAnyAnswer({})).toBe(false);
    expect(
      hasAnyAnswer({ mood: '', feelings: [], notes: [], other: undefined }),
    ).toBe(false);
  });

  it('is true once any field has content', () => {
    expect(hasAnyAnswer({ mood: '', notes: 'x' })).toBe(true);
    expect(hasAnyAnswer({ feelings: ['calm'] })).toBe(true);
  });
});

describe('migrateLegacyDrafts', () => {
  async function setup() {
    const draftKey = await generateAnketaKey();
    const masterKey = await generateAnketaKey();
    const lostKey = await generateAnketaKey();
    return { draftKey, masterKey, lostKey };
  }

  it('saves only master-key drafts, re-encrypted under the draft key', async () => {
    const { draftKey, masterKey, lostKey } = await setup();
    const legacy: Answers = { mood: 'okay' };
    const saved: { anketaId: string; blob: string }[] = [];

    await migrateLegacyDrafts(
      [
        { anketaId: 'legacy', blob: await encryptBlob(legacy, masterKey) },
        { anketaId: 'current', blob: await encryptBlob({ a: 'b' }, draftKey) },
        { anketaId: 'lost', blob: await encryptBlob({ a: 'b' }, lostKey) },
      ],
      draftKey,
      masterKey,
      async (anketaId, blob) => {
        saved.push({ anketaId, blob });
      },
    );

    expect(saved.map((d) => d.anketaId)).toEqual(['legacy']);
    expect(await decryptDraft(saved[0].blob, draftKey, null)).toEqual({
      answers: legacy,
      legacy: false,
    });
  });

  it.each([403, 404, 409])(
    'skips a draft whose save fails with %i and carries on with the rest',
    async (status) => {
      const { draftKey, masterKey } = await setup();
      const saved: string[] = [];

      await migrateLegacyDrafts(
        [
          { anketaId: 'gone', blob: await encryptBlob({ a: '1' }, masterKey) },
          { anketaId: 'ok', blob: await encryptBlob({ a: '2' }, masterKey) },
        ],
        draftKey,
        masterKey,
        async (anketaId) => {
          if (anketaId === 'gone') throw new ApiError(status, 'nope');
          saved.push(anketaId);
        },
      );

      expect(saved).toEqual(['ok']);
    },
  );

  it.each([
    ['a server error', new ApiError(500, 'boom')],
    ['a network error', new TypeError('Failed to fetch')],
  ])('rethrows %s, stopping the migration', async (_, failure) => {
    const { draftKey, masterKey } = await setup();
    const saved: string[] = [];

    await expect(
      migrateLegacyDrafts(
        [
          { anketaId: 'bad', blob: await encryptBlob({ a: '1' }, masterKey) },
          { anketaId: 'next', blob: await encryptBlob({ a: '2' }, masterKey) },
        ],
        draftKey,
        masterKey,
        async (anketaId) => {
          if (anketaId === 'bad') throw failure;
          saved.push(anketaId);
        },
      ),
    ).rejects.toBe(failure);
    expect(saved).toEqual([]);
  });
});
