import { decryptBlob } from '../crypto/anketaKey';

/**
 * Live-synced "discussed" question checkboxes (GitHub issue #168): the IDs of
 * the question blocks marked as discussed during the meeting.
 */
export type DiscussedBlobData = string[];

/**
 * What is actually encrypted under anketaKey as the anketa's discussedBlob:
 * the list, padded. Question IDs differ in length and the built-in ones are
 * public, so an unpadded list's ciphertext size would tell the server which
 * questions were ticked. Padded to a multiple of this many bytes, a list the
 * size of any built-in template's reveals nothing; only a very long custom
 * template's list reaches the next step, which reveals a rough count.
 */
export const DISCUSSED_PADDING_BYTES = 512;

interface PaddedDiscussed {
  ids: DiscussedBlobData;
  pad: string;
}

export function encodeDiscussed(ids: DiscussedBlobData): PaddedDiscussed {
  const unpadded = new TextEncoder().encode(
    JSON.stringify({ ids, pad: '' }),
  ).length;
  const target =
    Math.ceil(unpadded / DISCUSSED_PADDING_BYTES) * DISCUSSED_PADDING_BYTES;
  return { ids, pad: ' '.repeat(target - unpadded) };
}

/**
 * The decrypted blob's list of question IDs. Either participant can write any
 * blob under anketaKey and the server can't check it, so anything else (a
 * string would even substring-match in `.includes()`) reads as nothing
 * discussed rather than breaking the page.
 */
export function parseDiscussed(data: unknown): DiscussedBlobData {
  const ids = (data as { ids?: unknown } | null)?.ids;
  return Array.isArray(ids)
    ? ids.filter((id): id is string => typeof id === 'string')
    : [];
}

/**
 * Decrypts and parses a discussedBlob. A blob that won't decrypt or parse
 * (only a modified client could write one: it needs anketaKey) reads as
 * nothing discussed too, so it can't stop the page, its live updates or the
 * data export; the next tick overwrites it with a valid one.
 */
export async function decryptDiscussed(
  blob: string | null,
  key: Uint8Array,
): Promise<DiscussedBlobData> {
  if (blob === null) return [];
  try {
    return parseDiscussed((await decryptBlob(blob, key)).data);
  } catch {
    return [];
  }
}

/**
 * Marks a question as discussed or not. Sets rather than toggles, because a
 * save that hits a version conflict reapplies this to the server's newer list
 * (discussedSync.ts): if the counterpart ticked the same question a moment
 * earlier, a toggle would untick it again.
 */
export function setDiscussed(
  current: DiscussedBlobData,
  questionId: string,
  discussed: boolean,
): DiscussedBlobData {
  if (current.includes(questionId) === discussed) return current;
  return discussed
    ? [...current, questionId]
    : current.filter((id) => id !== questionId);
}

/**
 * Applies queued clicks — question ID to its intended state — to a list, in
 * click order. Used both for the save itself and to lay clicks still waiting
 * for the next save over the list the previous save returned.
 */
export function applyDiscussedIntents(
  current: DiscussedBlobData,
  intents: Record<string, boolean>,
): DiscussedBlobData {
  return Object.entries(intents).reduce(
    (list, [questionId, discussed]) =>
      setDiscussed(list, questionId, discussed),
    current,
  );
}
