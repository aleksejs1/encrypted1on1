import { decryptBlob } from '../crypto/anketaKey';
import { carryForwardBlob, carryForwardItems } from './blobSync';

/**
 * The shared "Topics to discuss" list (GitHub issue #206): what either
 * participant wants to talk about, visible to both at once, with no draft
 * phase — unlike answers, which stay private until published. Stored like
 * outcomes (outcomes.ts): one blob under anketaKey, the whole list rewritten
 * on every change, and a version conflict resolved by reapplying the same
 * change to the server's latest list (topicsSync.ts).
 *
 * Only the author edits or deletes a topic; either participant marks it as
 * discussed. Both rules are enforced here and in the UI, not by the server,
 * which can't read the list.
 */
export interface TopicItem {
  id: string;
  authorId: string;
  text: string;
  discussed: boolean;
  createdAt: string;
}

/**
 * What the topics card has open besides the list, as one value: at most one
 * of these at a time. Ticking "discussed" isn't in here: it needs no open
 * form, and several ticks can be waiting to be saved at once.
 */
export type TopicAction =
  | { kind: 'idle' }
  | { kind: 'adding' }
  | { kind: 'editing'; id: string; saving: boolean }
  | { kind: 'confirmingDelete'; id: string; deleting: boolean };

/** A topicsBlob's list; none yet is an empty list. */
export async function decryptTopics(
  blob: string | null,
  key: Uint8Array,
): Promise<TopicItem[]> {
  return blob ? (await decryptBlob<TopicItem[]>(blob, key)).data : [];
}

export function newTopic(authorId: string, text: string): TopicItem {
  return {
    id: crypto.randomUUID(),
    authorId,
    text,
    discussed: false,
    createdAt: new Date().toISOString(),
  };
}

/**
 * Appends `item`, unless the list already has it. The item is made once, by
 * newTopic(), and not inside this function: a save whose response was lost
 * may have landed, and adding the same item again must not duplicate it.
 */
export function addTopic(existing: TopicItem[], item: TopicItem): TopicItem[] {
  return existing.some((other) => other.id === item.id)
    ? existing
    : [...existing, item];
}

/**
 * Marks a topic as discussed or not, for either participant. Sets rather
 * than toggles: a save that hits a version conflict reapplies this to the
 * server's newer list, and if the counterpart ticked the same topic a moment
 * earlier, a toggle would untick it again. Throws if the topic is gone, like
 * editTopic().
 */
export function setTopicDiscussed(
  existing: TopicItem[],
  id: string,
  discussed: boolean,
): TopicItem[] {
  const current = existing.find((item) => item.id === id);
  if (!current) {
    throw new Error(`setTopicDiscussed: no item ${id} (deleted elsewhere?)`);
  }
  // The same list when there's nothing to change, which TopicsSync takes as
  // nothing to save.
  if (current.discussed === discussed) return existing;
  return existing.map((item) =>
    item.id === id ? { ...item, discussed } : item,
  );
}

/** Own topics only; throws on a miss, for the same reason as outcomes.ts's editOutcome(). */
export function editTopic(
  existing: TopicItem[],
  id: string,
  authorId: string,
  text: string,
): TopicItem[] {
  const current = existing.find(
    (item) => item.id === id && item.authorId === authorId,
  );
  if (!current) {
    throw new Error(
      `editTopic: no item ${id} by ${authorId} (deleted elsewhere?)`,
    );
  }
  // The same list for unchanged text: nothing to save (see setTopicDiscussed()).
  if (current.text === text) return existing;
  return existing.map((item) => (item.id === id ? { ...item, text } : item));
}

/** Own topics only; throws on a miss, like editTopic(). */
export function deleteTopic(
  existing: TopicItem[],
  id: string,
  authorId: string,
): TopicItem[] {
  const filtered = existing.filter(
    (item) => !(item.id === id && item.authorId === authorId),
  );
  if (filtered.length === existing.length) {
    throw new Error(
      `deleteTopic: no item ${id} by ${authorId} (deleted elsewhere already?)`,
    );
  }
  return filtered;
}

/**
 * The topics not yet discussed, re-encrypted for the pair's next meeting —
 * the same carry-forward as outcomes.ts's carryForwardOutcomes(), on archive
 * and on creating a meeting by hand.
 */
export function carryForwardTopics(
  blob: string | null,
  oldKey: Uint8Array,
  newKey: Uint8Array,
): Promise<string | undefined> {
  return carryForwardBlob(blob, oldKey, newKey, notDiscussed);
}

/** The same from a list already decrypted: the open meeting's, on archive. */
export function carryForwardTopicItems(
  items: TopicItem[],
  newKey: Uint8Array,
): Promise<string | undefined> {
  return carryForwardItems(items, newKey, notDiscussed);
}

function notDiscussed(item: TopicItem): boolean {
  return !item.discussed;
}
