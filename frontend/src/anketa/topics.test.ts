import { describe, expect, it } from 'vitest';
import {
  decryptBlob,
  encryptBlob,
  generateAnketaKey,
} from '../crypto/anketaKey';

import {
  addTopic,
  newTopic,
  carryForwardTopicItems,
  carryForwardTopics,
  decryptTopics,
  deleteTopic,
  editTopic,
  setTopicDiscussed,
  type TopicItem,
} from './topics';

/** A new topic by `authorId`, appended. */
function add(existing: TopicItem[], authorId: string, text: string) {
  return addTopic(existing, newTopic(authorId, text));
}

function twoTopics(): TopicItem[] {
  return add(add([], 'user-1', 'first'), 'user-2', 'second');
}

describe('addTopic', () => {
  it('does not add the same topic twice', () => {
    const item = newTopic('user-1', 'once');
    const once = addTopic([], item);

    expect(addTopic(once, item)).toBe(once);
  });

  it('appends a new topic that is not discussed yet', () => {
    const result = add([], 'user-1', 'budget for the offsite');

    expect(result).toHaveLength(1);
    expect(result[0].discussed).toBe(false);
    expect(result[0].authorId).toBe('user-1');
    expect(result[0].text).toBe('budget for the offsite');
    expect(Number.isNaN(Date.parse(result[0].createdAt))).toBe(false);
  });

  it('leaves the existing topics untouched and gives each its own id', () => {
    const existing = add([], 'user-1', 'first');
    const result = add(existing, 'user-2', 'second');

    expect(result[0]).toBe(existing[0]);
    expect(result[1].id).not.toBe(result[0].id);
    expect(existing).toHaveLength(1);
  });
});

describe('setTopicDiscussed', () => {
  it('marks the matching topic only', () => {
    const items = twoTopics();
    const result = setTopicDiscussed(items, items[0].id, true);

    expect(result[0].discussed).toBe(true);
    expect(result[1]).toBe(items[1]);
    expect(items[0].discussed).toBe(false);
  });

  it('lets either participant mark a topic, whoever wrote it', () => {
    // No author argument at all: unlike edit and delete, this isn't own-only.
    const items = twoTopics();

    expect(setTopicDiscussed(items, items[1].id, true)[1].discussed).toBe(true);
  });

  it('sets rather than toggles, so reapplying it after a conflict changes nothing', () => {
    const items = twoTopics();
    const once = setTopicDiscussed(items, items[0].id, true);
    const twice = setTopicDiscussed(once, items[0].id, true);

    expect(twice[0].discussed).toBe(true);
    expect(setTopicDiscussed(twice, items[0].id, false)[0].discussed).toBe(
      false,
    );
  });

  it('throws when the topic id does not match', () => {
    expect(() => setTopicDiscussed(twoTopics(), 'no-such-id', true)).toThrow();
  });

  it('returns the same list when the topic is already in that state', () => {
    const items = twoTopics();

    expect(setTopicDiscussed(items, items[0].id, false)).toBe(items);
  });
});

describe('editTopic', () => {
  it('replaces the text of the matching topic by the same author', () => {
    const items = twoTopics();
    const result = editTopic(items, items[0].id, 'user-1', 'edited');

    expect(result[0].text).toBe('edited');
    expect(result[0].id).toBe(items[0].id);
    expect(result[0].createdAt).toBe(items[0].createdAt);
  });

  it('leaves other topics and the discussed flag untouched', () => {
    const fresh = twoTopics();
    const items = setTopicDiscussed(fresh, fresh[0].id, true);
    const result = editTopic(items, items[0].id, 'user-1', 'edited');

    expect(result[0].discussed).toBe(true);
    expect(result[1]).toBe(items[1]);
  });

  it('returns the same list when the text is unchanged', () => {
    const items = twoTopics();

    expect(editTopic(items, items[0].id, 'user-1', 'first')).toBe(items);
  });

  it('throws when the author does not match', () => {
    const items = twoTopics();

    expect(() => editTopic(items, items[0].id, 'user-2', 'edited')).toThrow();
  });

  it('throws when the topic id does not match', () => {
    expect(() =>
      editTopic(twoTopics(), 'no-such-id', 'user-1', 'edited'),
    ).toThrow();
  });
});

describe('deleteTopic', () => {
  it('removes the matching topic by the same author', () => {
    const items = twoTopics();
    const result = deleteTopic(items, items[0].id, 'user-1');

    expect(result).toEqual([items[1]]);
  });

  it('leaves other topics untouched', () => {
    const items = twoTopics();

    expect(deleteTopic(items, items[1].id, 'user-2')[0]).toBe(items[0]);
  });

  it('throws when the author does not match', () => {
    const items = twoTopics();

    expect(() => deleteTopic(items, items[0].id, 'user-2')).toThrow();
  });

  it('throws when the topic id does not match', () => {
    expect(() => deleteTopic(twoTopics(), 'no-such-id', 'user-1')).toThrow();
  });
});

describe('decryptTopics', () => {
  it('reads back an encrypted list, and no blob as an empty one', async () => {
    const key = await generateAnketaKey();
    const items = twoTopics();

    expect(await decryptTopics(await encryptBlob(items, key), key)).toEqual(
      items,
    );
    expect(await decryptTopics(null, key)).toEqual([]);
  });

  it('fails under another key', async () => {
    const blob = await encryptBlob(twoTopics(), await generateAnketaKey());

    await expect(
      decryptTopics(blob, await generateAnketaKey()),
    ).rejects.toThrow();
  });
});

describe('carryForwardTopicItems', () => {
  it('encrypts only the topics not discussed, and nothing when there are none', async () => {
    const newKey = await generateAnketaKey();
    const items = twoTopics();
    const ticked = setTopicDiscussed(items, items[0].id, true);

    const carried = await carryForwardTopicItems(ticked, newKey);

    expect(
      (await decryptBlob<TopicItem[]>(carried as string, newKey)).data,
    ).toEqual([items[1]]);
    expect(
      await carryForwardTopicItems(
        setTopicDiscussed(ticked, items[1].id, true),
        newKey,
      ),
    ).toBeUndefined();
  });
});

describe('carryForwardTopics', () => {
  it('re-encrypts only the topics not discussed under the new key', async () => {
    const oldKey = await generateAnketaKey();
    const newKey = await generateAnketaKey();
    const items = twoTopics();
    const blob = await encryptBlob(
      setTopicDiscussed(items, items[0].id, true),
      oldKey,
    );

    const carried = await carryForwardTopics(blob, oldKey, newKey);

    expect(carried).toBeDefined();
    const { data } = await decryptBlob<TopicItem[]>(carried as string, newKey);
    expect(data).toEqual([items[1]]);
    await expect(decryptBlob(carried as string, oldKey)).rejects.toThrow();
  });

  it('carries nothing when every topic was discussed, or there is no list', async () => {
    const oldKey = await generateAnketaKey();
    const newKey = await generateAnketaKey();
    const items = add([], 'user-1', 'only');
    const blob = await encryptBlob(
      setTopicDiscussed(items, items[0].id, true),
      oldKey,
    );

    expect(await carryForwardTopics(blob, oldKey, newKey)).toBeUndefined();
    expect(await carryForwardTopics(null, oldKey, newKey)).toBeUndefined();
  });
});
