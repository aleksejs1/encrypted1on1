import {
  SCHEMA_VERSION,
  decryptBlob,
  encryptBlob,
  generateAnketaKey,
} from './anketaKey';
import { fromBase64, toBase64 } from './encoding';
import { deriveSymmetricKey } from './keypair';
import { getSodium } from './sodium';

/**
 * Private notes (GitHub issue #132 §4.1): one pad per anketa per participant,
 * readable only by its author.
 *
 * - A random notes key, the same primitive as the anketa key.
 * - The key is wrapped in an *authenticated* box from the author's keypair to
 *   itself: `nonce || crypto_box_easy(key, nonce, ownPublic, ownPrivate)`.
 *   Only the holder of the private key can produce a box that opens, so a
 *   server that swaps in a key of its own fails to open and the notes show as
 *   unreadable instead of adopting it. `crypto_box_seal` would not do: anyone
 *   can seal to a public key, and with no second reader a swap would go
 *   unnoticed.
 * - The notes blob is bound to its anketa and author as AEAD associated data,
 *   so the server can't move a row to another anketa or another user.
 *
 * Not the anketa key (the counterpart has it) and not the password-derived
 * master key (an in-app password change would strand the notes, the #129 bug
 * class). A forgotten-password reset makes a new keypair, and the notes can no
 * longer be opened: accepted and warned about in the panel.
 */

interface NotesContent {
  text: string;
}

/** The server's cap on the encoded blob (backend SavePrivateNotesRequest::MAX_NOTES_BLOB_LENGTH). */
export const MAX_NOTES_BLOB_LENGTH = 262_144;

export const generateNotesKey = generateAnketaKey;

export async function wrapNotesKey(
  notesKey: Uint8Array,
  publicKey: Uint8Array,
  privateKey: Uint8Array,
): Promise<string> {
  const sodium = await getSodium();
  const nonce = sodium.randombytes_buf(sodium.crypto_box_NONCEBYTES);
  const box = sodium.crypto_box_easy(notesKey, nonce, publicKey, privateKey);
  const combined = new Uint8Array(nonce.length + box.length);
  combined.set(nonce, 0);
  combined.set(box, nonce.length);
  return toBase64(combined);
}

/** Throws if the box wasn't made by this keypair (a reset, a swapped key, corruption). */
export async function unwrapNotesKey(
  encryptedNotesKey: string,
  publicKey: Uint8Array,
  privateKey: Uint8Array,
): Promise<Uint8Array> {
  const sodium = await getSodium();
  const combined = await fromBase64(encryptedNotesKey);
  const nonce = combined.slice(0, sodium.crypto_box_NONCEBYTES);
  const box = combined.slice(sodium.crypto_box_NONCEBYTES);
  return sodium.crypto_box_open_easy(box, nonce, publicKey, privateKey);
}

/**
 * The key the tab-local notes backup (anketa/notesBackup.ts) is encrypted
 * with: derived from the X25519 private key, like the drafts key
 * (crypto/keypair.ts's deriveDraftKey(), its own context label). The same after
 * logging back in, and unchanged by an in-app password change, which re-wraps
 * the same private key. A backup can be the only copy of unsaved text, so it
 * mustn't be stranded the way a password-derived key would strand it (#129).
 */
export function deriveNotesBackupKey(
  privateKey: Uint8Array,
): Promise<Uint8Array> {
  return deriveSymmetricKey(privateKey, 'e1o1note');
}

export function notesAssociatedData(
  anketaId: string,
  userId: string,
): Uint8Array {
  return new TextEncoder().encode(
    `e1o1:private-notes:v1:${anketaId}:${userId}`,
  );
}

export function encryptNotes(
  text: string,
  notesKey: Uint8Array,
  associatedData: Uint8Array,
): Promise<string> {
  return encryptBlob<NotesContent>({ text }, notesKey, associatedData);
}

/** Throws if the key or the associated data doesn't match, or the content isn't notes. */
export async function decryptNotes(
  notesBlob: string,
  notesKey: Uint8Array,
  associatedData: Uint8Array,
): Promise<string> {
  const envelope = await decryptBlob<NotesContent>(
    notesBlob,
    notesKey,
    associatedData,
  );
  const text: unknown = envelope.data?.text;
  if (typeof text !== 'string') {
    throw new Error('Not a private notes blob.');
  }
  return text;
}

/** XChaCha20-Poly1305's nonce and MAC sizes, as encryptBlob lays them out. */
const NONCE_BYTES = 24;
const MAC_BYTES = 16;

/**
 * The length of the blob `encryptNotes(text, …)` produces, without encrypting:
 * base64 of nonce + MAC + the JSON envelope's UTF-8 bytes. The cap is measured
 * on this, the same thing the server checks. privateNotes.test.ts checks it
 * against real encryptNotes output, so a change to the envelope shows up there.
 */
export function encodedNotesLength(text: string): number {
  const envelope = JSON.stringify({
    schemaVersion: SCHEMA_VERSION,
    data: { text },
  });
  const bytes =
    NONCE_BYTES + MAC_BYTES + new TextEncoder().encode(envelope).length;
  return 4 * Math.ceil(bytes / 3);
}

/**
 * The blob's share of the cap in whole percent, cheaply 0 for text far below
 * it. A UTF-16 unit costs at most 6 JSON bytes (a `\u00XX` escape), so text
 * under this length can't reach 90% and skips the full encode on each keystroke.
 */
export function notesCapPercent(text: string): number {
  const cannotReach90 = (MAX_NOTES_BLOB_LENGTH * 0.9 * 3) / 4 / 6 - 100;
  if (text.length < cannotReach90) return 0;
  return Math.floor((encodedNotesLength(text) / MAX_NOTES_BLOB_LENGTH) * 100);
}
