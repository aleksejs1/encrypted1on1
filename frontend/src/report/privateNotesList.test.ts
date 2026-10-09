import { describe, expect, it } from 'vitest';
import {
  ALL_COLLEAGUES,
  DELETED_COLLEAGUES,
  extractColleagues,
  filterNotes,
  joinAndSortNotes,
  type NoteEntry,
  type NoteLabels,
  type NoteMeeting,
} from './privateNotesList';

const LABELS: NoteLabels = {
  formatDate: (meetingDate) => `on ${meetingDate.slice(0, 10)}`,
  deletedColleague: 'Deleted user',
};

function meeting(
  overrides: Partial<NoteMeeting> & { id: string },
): NoteMeeting {
  return {
    meetingDate: '2026-09-01T00:00:00+00:00',
    archivedAt: null,
    counterpartId: 'alice-id',
    counterpartName: 'Alice Smith',
    counterpartEmail: 'alice@example.com',
    counterpartDeleted: false,
    ...overrides,
  };
}

const BOB = {
  counterpartId: 'bob-id',
  counterpartName: '',
  counterpartEmail: 'bob@example.com',
};

const GONE = {
  counterpartName: '',
  counterpartEmail: 'deleted-1@deleted.invalid',
  counterpartDeleted: true,
};

describe('joinAndSortNotes', () => {
  it('joins a note with its meeting', () => {
    expect(
      joinAndSortNotes(
        [{ anketaId: 'm1', text: 'Promotion plan' }],
        [meeting({ id: 'm1', archivedAt: '2026-09-02T10:00:00+00:00' })],
        LABELS,
      ),
    ).toEqual([
      {
        anketaId: 'm1',
        text: 'Promotion plan',
        meeting: {
          displayDate: 'on 2026-09-01',
          archived: true,
          colleagueKey: 'alice-id',
          colleagueLabel: 'Alice Smith (alice@example.com)',
        },
      },
    ]);
  });

  it('marks an open meeting as not archived, and names a colleague without a display name by email', () => {
    const [entry] = joinAndSortNotes(
      [{ anketaId: 'm1', text: 'x' }],
      [meeting({ id: 'm1', ...BOB })],
      LABELS,
    );
    expect(entry.meeting).toMatchObject({
      archived: false,
      colleagueKey: 'bob-id',
      colleagueLabel: 'bob@example.com',
    });
  });

  it('leaves out a note with no text, and keeps one that cannot be opened', () => {
    const entries = joinAndSortNotes(
      [
        { anketaId: 'm1', text: '' },
        { anketaId: 'm2', text: ' \n\t ' },
        { anketaId: 'm3', text: null },
        { anketaId: 'm4', text: ' kept ' },
      ],
      ['m1', 'm2', 'm3', 'm4'].map((id) => meeting({ id })),
      LABELS,
    );
    expect(entries.map(({ anketaId, text }) => ({ anketaId, text }))).toEqual([
      { anketaId: 'm4', text: ' kept ' },
      { anketaId: 'm3', text: null },
    ]);
  });

  it('lists the newest meeting first, whatever order the server sent', () => {
    const entries = joinAndSortNotes(
      [
        { anketaId: 'old', text: 'a' },
        { anketaId: 'new', text: 'b' },
        { anketaId: 'mid', text: 'c' },
      ],
      [
        meeting({ id: 'mid', meetingDate: '2026-08-01T00:00:00+00:00' }),
        meeting({ id: 'old', meetingDate: '2025-12-31T00:00:00+00:00' }),
        meeting({ id: 'new', meetingDate: '2026-09-01T00:00:00+00:00' }),
      ],
      LABELS,
    );
    expect(entries.map((entry) => entry.anketaId)).toEqual([
      'new',
      'mid',
      'old',
    ]);
  });

  it('orders two meetings on the same day by id, the same way for either input order', () => {
    const meetings = [meeting({ id: 'a' }), meeting({ id: 'b' })];
    const notes = [
      { anketaId: 'a', text: 'one' },
      { anketaId: 'b', text: 'two' },
    ];
    const ids = (entries: NoteEntry[]) => entries.map((e) => e.anketaId);
    expect(ids(joinAndSortNotes(notes, meetings, LABELS))).toEqual(['b', 'a']);
    expect(
      ids(joinAndSortNotes([...notes].reverse(), meetings, LABELS)),
    ).toEqual(['b', 'a']);
  });

  it('lists a note whose meeting is unknown last, with its text and no meeting', () => {
    const entries = joinAndSortNotes(
      [
        { anketaId: 'orphan-b', text: 'still mine' },
        { anketaId: 'm1', text: 'known' },
        { anketaId: 'orphan-a', text: null },
      ],
      [meeting({ id: 'm1', meetingDate: '2020-01-01T00:00:00+00:00' })],
      LABELS,
    );
    expect(entries).toEqual([
      expect.objectContaining({ anketaId: 'm1' }),
      { anketaId: 'orphan-a', text: null, meeting: null },
      { anketaId: 'orphan-b', text: 'still mine', meeting: null },
    ]);
  });

  it("gives every deleted colleague one key and the page's label, not the placeholder address", () => {
    const entries = joinAndSortNotes(
      [
        { anketaId: 'm1', text: 'a' },
        { anketaId: 'm2', text: 'b' },
      ],
      [
        meeting({ id: 'm1', ...GONE, counterpartId: 'gone-1' }),
        meeting({ id: 'm2', ...GONE, counterpartId: 'gone-2' }),
      ],
      LABELS,
    );
    for (const entry of entries) {
      expect(entry.meeting).toMatchObject({
        colleagueKey: DELETED_COLLEAGUES,
        colleagueLabel: 'Deleted user',
      });
    }
  });
});

/** Entries for the filter tests: Alice (two meetings), Bob, a deleted colleague, an orphan. */
function sampleEntries(): NoteEntry[] {
  return joinAndSortNotes(
    [
      { anketaId: 'alice-1', text: 'Discussed the Promotion timeline' },
      { anketaId: 'alice-2', text: null },
      { anketaId: 'bob-1', text: 'Asked about alice and mentoring' },
      { anketaId: 'gone-1', text: 'Handover notes' },
      { anketaId: 'orphan', text: 'Unknown meeting, promotion again' },
    ],
    [
      meeting({ id: 'alice-1', meetingDate: '2026-09-01T00:00:00+00:00' }),
      meeting({ id: 'alice-2', meetingDate: '2026-08-01T00:00:00+00:00' }),
      meeting({
        id: 'bob-1',
        meetingDate: '2026-07-01T00:00:00+00:00',
        ...BOB,
      }),
      meeting({
        id: 'gone-1',
        meetingDate: '2026-06-01T00:00:00+00:00',
        counterpartId: 'gone-id',
        ...GONE,
      }),
    ],
    LABELS,
  );
}

function ids(entries: NoteEntry[]): string[] {
  return entries.map((entry) => entry.anketaId);
}

describe('extractColleagues', () => {
  it('lists each colleague once, by label, including one with only an unreadable note', () => {
    expect(extractColleagues(sampleEntries())).toEqual([
      { key: 'alice-id', label: 'Alice Smith (alice@example.com)' },
      { key: 'bob-id', label: 'bob@example.com' },
      { key: DELETED_COLLEAGUES, label: 'Deleted user' },
    ]);
    expect(
      extractColleagues(
        sampleEntries().filter((entry) => entry.anketaId === 'alice-2'),
      ),
    ).toEqual([{ key: 'alice-id', label: 'Alice Smith (alice@example.com)' }]);
  });

  it('has no option for a note whose meeting is unknown', () => {
    expect(
      extractColleagues([{ anketaId: 'orphan', text: 'x', meeting: null }]),
    ).toEqual([]);
  });
});

describe('filterNotes', () => {
  it('keeps everything, in order, for everyone and no query', () => {
    const entries = sampleEntries();
    expect(filterNotes(entries, ALL_COLLEAGUES, '')).toEqual(entries);
    expect(filterNotes(entries, ALL_COLLEAGUES, '   ')).toEqual(entries);
  });

  it('keeps an unreadable note with an unknown meeting when there is no query', () => {
    const entries: NoteEntry[] = [
      { anketaId: 'orphan', text: null, meeting: null },
    ];
    expect(filterNotes(entries, ALL_COLLEAGUES, '')).toEqual(entries);
    expect(filterNotes(entries, ALL_COLLEAGUES, 'a')).toEqual([]);
  });

  it("filters by colleague, unreadable notes included, and never lists an unknown meeting's note under one", () => {
    const entries = sampleEntries();
    expect(ids(filterNotes(entries, 'alice-id', ''))).toEqual([
      'alice-1',
      'alice-2',
    ]);
    expect(ids(filterNotes(entries, 'bob-id', ''))).toEqual(['bob-1']);
    expect(ids(filterNotes(entries, DELETED_COLLEAGUES, ''))).toEqual([
      'gone-1',
    ]);
    expect(filterNotes(entries, 'nobody-id', '')).toEqual([]);
  });

  it('matches the note text ignoring case, an unknown meeting included', () => {
    expect(
      ids(filterNotes(sampleEntries(), ALL_COLLEAGUES, ' PROMOTION ')),
    ).toEqual(['alice-1', 'orphan']);
  });

  it('matches an accented word whether the note or the query has it decomposed', () => {
    const composed = 'caf\u00e9';
    const decomposed = 'cafe\u0301';
    const withText = (text: string): NoteEntry[] => [
      { anketaId: 'm1', text, meeting: null },
    ];
    expect(
      filterNotes(
        withText(`Met at the ${decomposed}`),
        ALL_COLLEAGUES,
        composed,
      ),
    ).toHaveLength(1);
    expect(
      filterNotes(
        withText(`Met at the ${composed}`),
        ALL_COLLEAGUES,
        decomposed,
      ),
    ).toHaveLength(1);
    expect(
      filterNotes(withText('Met at the cafe'), ALL_COLLEAGUES, composed),
    ).toEqual([]);
  });

  it('matches across a non-breaking space and a ligature in pasted text', () => {
    const entries: NoteEntry[] = [
      {
        anketaId: 'm1',
        text: 'billing\u00a0migration, \ufb01nal step',
        meeting: null,
      },
    ];
    expect(
      filterNotes(entries, ALL_COLLEAGUES, 'billing migration'),
    ).toHaveLength(1);
    expect(filterNotes(entries, ALL_COLLEAGUES, 'final')).toHaveLength(1);
  });

  it("matches the colleague's name and email, so an unreadable note is found by them", () => {
    const entries = sampleEntries();
    expect(ids(filterNotes(entries, ALL_COLLEAGUES, 'smith'))).toEqual([
      'alice-1',
      'alice-2',
    ]);
    // Bob's note mentions Alice in its text; Alice's own are found by her address.
    expect(ids(filterNotes(entries, ALL_COLLEAGUES, 'alice@'))).toEqual([
      'alice-1',
      'alice-2',
    ]);
    expect(ids(filterNotes(entries, ALL_COLLEAGUES, 'alice'))).toEqual([
      'alice-1',
      'alice-2',
      'bob-1',
    ]);
  });

  it('matches the meeting date as the page shows it', () => {
    expect(
      ids(filterNotes(sampleEntries(), ALL_COLLEAGUES, 'on 2026-07')),
    ).toEqual(['bob-1']);
  });

  it('finds a deleted colleague by the label, never by the placeholder address', () => {
    const entries = sampleEntries();
    expect(ids(filterNotes(entries, ALL_COLLEAGUES, 'deleted user'))).toEqual([
      'gone-1',
    ]);
    expect(filterNotes(entries, ALL_COLLEAGUES, 'deleted.invalid')).toEqual([]);
    expect(filterNotes(entries, ALL_COLLEAGUES, 'deleted-1')).toEqual([]);
  });

  it('applies the colleague and the query together', () => {
    const entries = sampleEntries();
    expect(ids(filterNotes(entries, 'bob-id', 'alice'))).toEqual(['bob-1']);
    expect(filterNotes(entries, 'bob-id', 'promotion')).toEqual([]);
  });
});
