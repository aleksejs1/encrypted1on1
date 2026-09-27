import { describe, expect, it } from 'vitest';
import { encryptBlob, generateAnketaKey } from '../crypto/anketaKey';
import {
  applyDiscussedIntents,
  decryptDiscussed,
  DISCUSSED_PADDING_BYTES,
  encodeDiscussed,
  parseDiscussed,
  setDiscussed,
} from './discussed';

describe('setDiscussed', () => {
  it('adds a question ID to an empty list', () => {
    expect(setDiscussed([], 'mood', true)).toEqual(['mood']);
  });

  it('appends a question ID to a non-empty list', () => {
    expect(setDiscussed(['mood'], 'workload', true)).toEqual([
      'mood',
      'workload',
    ]);
  });

  it('keeps an already discussed question once, not twice', () => {
    // The conflict-retry case: the counterpart ticked it first.
    expect(setDiscussed(['mood', 'workload'], 'mood', true)).toEqual([
      'mood',
      'workload',
    ]);
  });

  it('removes a question ID', () => {
    expect(setDiscussed(['mood', 'workload'], 'mood', false)).toEqual([
      'workload',
    ]);
  });

  it('leaves the list alone when removing an absent ID', () => {
    // The conflict-retry case: the counterpart unticked it first.
    expect(setDiscussed(['workload'], 'mood', false)).toEqual(['workload']);
  });

  it('does not mutate the original array', () => {
    const original = ['mood', 'workload'];
    setDiscussed(original, 'growth', true);
    setDiscussed(original, 'mood', false);
    expect(original).toEqual(['mood', 'workload']);
  });
});

describe('applyDiscussedIntents', () => {
  it('returns the list unchanged when nothing is queued', () => {
    const current = ['mood'];
    expect(applyDiscussedIntents(current, {})).toBe(current);
  });

  it('applies every queued intent', () => {
    expect(
      applyDiscussedIntents(['mood', 'workload'], {
        growth: true,
        mood: false,
      }),
    ).toEqual(['workload', 'growth']);
  });

  it('sets rather than toggles, so a newer server list keeps its state', () => {
    // The counterpart already ticked mood and unticked workload.
    expect(
      applyDiscussedIntents(['mood'], { mood: true, workload: false }),
    ).toEqual(['mood']);
  });
});

describe('encodeDiscussed', () => {
  const size = (ids: string[]) =>
    new TextEncoder().encode(JSON.stringify(encodeDiscussed(ids))).length;

  it('pads lists of different question IDs to the same size', () => {
    // The shortest and longest built-in IDs, and a whole template's worth.
    expect(size([])).toBe(DISCUSSED_PADDING_BYTES);
    expect(size(['mood'])).toBe(DISCUSSED_PADDING_BYTES);
    expect(size(['employeeAchievements'])).toBe(DISCUSSED_PADDING_BYTES);
    expect(
      size([
        'mood',
        'feelings',
        'workload',
        'growth',
        'friction',
        'achievements',
        'discuss',
        'periodSummary',
        'feedback',
        'support',
        'employeeAchievements',
        'managerDiscuss',
      ]),
    ).toBe(DISCUSSED_PADDING_BYTES);
  });

  it('grows in whole padding steps for a very long list', () => {
    const ids = Array.from(
      { length: 60 },
      (_, i) => `c_${String(i).padStart(10, '0')}`,
    );
    expect(size(ids) % DISCUSSED_PADDING_BYTES).toBe(0);
    expect(size(ids)).toBeGreaterThan(DISCUSSED_PADDING_BYTES);
  });

  it('round-trips through parseDiscussed', () => {
    const encoded = JSON.parse(
      JSON.stringify(encodeDiscussed(['mood', 'c_abc1234567'])),
    ) as unknown;
    expect(parseDiscussed(encoded)).toEqual(['mood', 'c_abc1234567']);
  });
});

describe('parseDiscussed', () => {
  it('keeps the list of question IDs', () => {
    expect(parseDiscussed({ ids: ['mood', 'workload'], pad: '' })).toEqual([
      'mood',
      'workload',
    ]);
  });

  it('drops entries that are not strings', () => {
    expect(parseDiscussed({ ids: ['mood', 1, null, { id: 'x' }] })).toEqual([
      'mood',
    ]);
  });

  it('reads any other shape as nothing discussed', () => {
    // A string would otherwise substring-match: 'moodNow'.includes('mood').
    expect(parseDiscussed({ ids: 'moodNow' })).toEqual([]);
    expect(parseDiscussed(['mood'])).toEqual([]);
    expect(parseDiscussed('moodNow')).toEqual([]);
    expect(parseDiscussed(null)).toEqual([]);
  });
});

describe('decryptDiscussed', () => {
  it('decrypts and parses a saved list', async () => {
    const key = await generateAnketaKey();
    const blob = await encryptBlob(encodeDiscussed(['mood']), key);
    expect(await decryptDiscussed(blob, key)).toEqual(['mood']);
  });

  it('reads no blob as nothing discussed', async () => {
    expect(await decryptDiscussed(null, await generateAnketaKey())).toEqual([]);
  });

  it('reads a blob that will not decrypt as nothing discussed', async () => {
    const blob = await encryptBlob(
      encodeDiscussed(['mood']),
      await generateAnketaKey(),
    );
    expect(await decryptDiscussed(blob, await generateAnketaKey())).toEqual([]);
  });
});
