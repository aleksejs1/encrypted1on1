import { describe, expect, it } from 'vitest';
import { updateBlobWithRetry } from './blobSync';
import {
  decryptBlob,
  encryptBlob,
  generateAnketaKey,
} from '../crypto/anketaKey';
import { ApiError } from '../api/client';

describe('updateBlobWithRetry', () => {
  it('encrypts and saves the applied result against the initial version', async () => {
    const key = await generateAnketaKey();
    const initialBlob = await encryptBlob(['a'], key);
    const saved: { blob: string; version: number }[] = [];

    const result = await updateBlobWithRetry<string[]>(
      key,
      { blob: initialBlob, version: 1 },
      (current) => [...current, 'b'],
      async (blob, expectedVersion) => {
        saved.push({ blob, version: expectedVersion });
      },
      () => undefined,
    );

    expect(result).toEqual(['a', 'b']);
    expect(saved).toHaveLength(1);
    expect(saved[0].version).toBe(1);
    expect((await decryptBlob<string[]>(saved[0].blob, key)).data).toEqual([
      'a',
      'b',
    ]);
  });

  it('treats a null initial blob as an empty starting list', async () => {
    const key = await generateAnketaKey();

    const result = await updateBlobWithRetry<string[]>(
      key,
      { blob: null, version: 0 },
      (current) => [...current, 'first'],
      async () => {},
      () => undefined,
    );

    expect(result).toEqual(['first']);
  });

  it('retries once against the conflict payload when save reports a 409, without a second fetch', async () => {
    const key = await generateAnketaKey();
    const initialBlob = await encryptBlob(['stale'], key);
    const remoteBlob = await encryptBlob(['a', 'b'], key);
    const saved: { blob: string; version: number }[] = [];
    let attempts = 0;

    const result = await updateBlobWithRetry<string[]>(
      key,
      { blob: initialBlob, version: 1 },
      (current) => [...current, 'mine'],
      async (blob, expectedVersion) => {
        attempts += 1;
        saved.push({ blob, version: expectedVersion });
        if (attempts === 1) {
          throw new ApiError(409, 'conflict', {
            blob: remoteBlob,
            version: 2,
          });
        }
      },
      (error) => {
        if (!(error instanceof ApiError) || error.status !== 409)
          return undefined;
        const body = error.body as { blob: string; version: number };
        return { blob: body.blob, version: body.version };
      },
    );

    expect(attempts).toBe(2);
    expect(result).toEqual(['a', 'b', 'mine']);
    expect(saved[1].version).toBe(2);
  });

  it('rethrows without retrying when onConflict declines the error', async () => {
    const key = await generateAnketaKey();
    const initialBlob = await encryptBlob(['a'], key);

    await expect(
      updateBlobWithRetry<string[]>(
        key,
        { blob: initialBlob, version: 1 },
        (current) => [...current, 'b'],
        async () => {
          throw new ApiError(500, 'server error');
        },
        () => undefined,
      ),
    ).rejects.toThrow('server error');
  });
});
