import { describe, expect, it } from 'vitest';
import { decryptBlob, encryptBlob, generateAnketaKey } from './anketaKey';
import { deriveDraftKey, generateKeyPair } from './keypair';
import {
  decryptNotes,
  deriveNotesBackupKey,
  encodedNotesLength,
  encryptNotes,
  generateNotesKey,
  notesAssociatedData,
  unwrapNotesKey,
  wrapNotesKey,
} from './privateNotes';
import { getSodium } from './sodium';

describe('notes key wrapping', () => {
  it('wraps and unwraps with the same keypair', async () => {
    const { publicKey, privateKey } = await generateKeyPair();
    const notesKey = await generateNotesKey();

    const wrapped = await wrapNotesKey(notesKey, publicKey, privateKey);

    expect(await unwrapNotesKey(wrapped, publicKey, privateKey)).toEqual(
      notesKey,
    );
  });

  it('is exactly the 96 base64 characters the server accepts', async () => {
    const { publicKey, privateKey } = await generateKeyPair();

    const wrapped = await wrapNotesKey(
      await generateNotesKey(),
      publicKey,
      privateKey,
    );

    expect(wrapped).toHaveLength(96);
  });

  it('rejects a box made with a different keypair (a reset, or a swapped key)', async () => {
    const mine = await generateKeyPair();
    const other = await generateKeyPair();
    const wrappedByOther = await wrapNotesKey(
      await generateNotesKey(),
      other.publicKey,
      other.privateKey,
    );

    await expect(
      unwrapNotesKey(wrappedByOther, mine.publicKey, mine.privateKey),
    ).rejects.toThrow();
  });

  it('rejects a key sealed anonymously to my public key, which anyone can make', async () => {
    const sodium = await getSodium();
    const { publicKey, privateKey } = await generateKeyPair();
    const serverChosenKey = await generateNotesKey();
    const sealed = sodium.to_base64(
      sodium.crypto_box_seal(serverChosenKey, publicKey),
      sodium.base64_variants.ORIGINAL,
    );

    await expect(
      unwrapNotesKey(sealed, publicKey, privateKey),
    ).rejects.toThrow();
  });
});

describe('notes encryption', () => {
  const ad = notesAssociatedData('anketa-1', 'user-1');

  it('round-trips the text', async () => {
    const key = await generateNotesKey();

    const blob = await encryptNotes('Remember: ask about the move.', key, ad);

    expect(await decryptNotes(blob, key, ad)).toBe(
      'Remember: ask about the move.',
    );
  });

  it('fails when the blob is moved to another anketa', async () => {
    const key = await generateNotesKey();
    const blob = await encryptNotes('secret', key, ad);

    await expect(
      decryptNotes(blob, key, notesAssociatedData('anketa-2', 'user-1')),
    ).rejects.toThrow();
  });

  it('fails when the blob is moved to another user', async () => {
    const key = await generateNotesKey();
    const blob = await encryptNotes('secret', key, ad);

    await expect(
      decryptNotes(blob, key, notesAssociatedData('anketa-1', 'user-2')),
    ).rejects.toThrow();
  });

  it('fails with the wrong key', async () => {
    const blob = await encryptNotes('secret', await generateNotesKey(), ad);

    await expect(
      decryptNotes(blob, await generateNotesKey(), ad),
    ).rejects.toThrow();
  });

  it('rejects a blob that decrypts but holds no notes text', async () => {
    const key = await generateNotesKey();
    const blob = await encryptBlob({ other: 1 }, key, ad);

    await expect(decryptNotes(blob, key, ad)).rejects.toThrow();
  });

  it('leaves encryptBlob without associated data working as before', async () => {
    const key = await generateAnketaKey();
    const blob = await encryptBlob({ a: 1 }, key);

    expect((await decryptBlob<{ a: number }>(blob, key)).data).toEqual({
      a: 1,
    });
    await expect(decryptBlob(blob, key, ad)).rejects.toThrow();
  });
});

describe('encodedNotesLength', () => {
  it.each([
    '',
    'short',
    'Кириллица и латышские буквы: ā, č, ē, ģ, ī, ķ, ļ, ņ, š, ū, ž',
    'emoji 🙂 and "quotes" and \\ backslashes\nand newlines',
    'x'.repeat(5000),
  ])('matches the real blob length for %j', async (text) => {
    const blob = await encryptNotes(
      text,
      await generateNotesKey(),
      notesAssociatedData('a', 'u'),
    );

    expect(encodedNotesLength(text)).toBe(blob.length);
  });
});

describe('deriveNotesBackupKey', () => {
  it('is stable for the same private key, so a backup opens after logging back in', async () => {
    const { privateKey } = await generateKeyPair();

    expect(await deriveNotesBackupKey(privateKey)).toEqual(
      await deriveNotesBackupKey(privateKey),
    );
  });

  it('differs from the drafts key and between keypairs', async () => {
    const mine = await generateKeyPair();
    const other = await generateKeyPair();
    const backupKey = await deriveNotesBackupKey(mine.privateKey);

    expect(backupKey).not.toEqual(await deriveDraftKey(mine.privateKey));
    expect(backupKey).not.toEqual(await deriveNotesBackupKey(other.privateKey));
  });
});
