import { describe, expect, it } from 'vitest';
import { generateKeyPair } from '../crypto/keypair';
import {
  encryptNotes,
  generateNotesKey,
  notesAssociatedData,
  wrapNotesKey,
} from '../crypto/privateNotes';
import {
  openNotesForExport,
  privateNotesForExport,
  type OwnNotesRow,
} from './notesExport';

async function rowFor(
  text: string,
  anketaId: string,
  userId: string,
  keypair: { publicKey: Uint8Array; privateKey: Uint8Array },
): Promise<OwnNotesRow> {
  const notesKey = await generateNotesKey();
  return {
    anketaId,
    encryptedNotesKey: await wrapNotesKey(
      notesKey,
      keypair.publicKey,
      keypair.privateKey,
    ),
    notesBlob: await encryptNotes(
      text,
      notesKey,
      notesAssociatedData(anketaId, userId),
    ),
  };
}

describe('openNotesForExport', () => {
  it('opens my own notes', async () => {
    const me = await generateKeyPair();
    const row = await rowFor('remember the budget', 'a1', 'u1', me);

    expect(
      await openNotesForExport(row, 'u1', me.publicKey, me.privateKey),
    ).toBe('remember the budget');
  });

  it('is null for a key wrapped by another keypair (a password reset since)', async () => {
    const before = await generateKeyPair();
    const now = await generateKeyPair();
    const row = await rowFor('old notes', 'a1', 'u1', before);

    expect(
      await openNotesForExport(row, 'u1', now.publicKey, now.privateKey),
    ).toBeNull();
  });

  it("is null for a blob moved onto another anketa's row", async () => {
    const me = await generateKeyPair();
    const row = await rowFor('notes', 'a1', 'u1', me);

    expect(
      await openNotesForExport(
        { ...row, anketaId: 'a2' },
        'u1',
        me.publicKey,
        me.privateKey,
      ),
    ).toBeNull();
  });

  it('is null for a corrupted blob', async () => {
    const me = await generateKeyPair();
    const row = await rowFor('notes', 'a1', 'u1', me);

    expect(
      await openNotesForExport(
        { ...row, notesBlob: 'not base64 at all' },
        'u1',
        me.publicKey,
        me.privateKey,
      ),
    ).toBeNull();
  });
});

describe('privateNotesForExport', () => {
  const exported = [
    { id: 'a1', meetingDate: '2026-09-01', counterpartEmail: 'boss@x.test' },
    { id: 'a2', meetingDate: '2026-09-15', counterpartEmail: 'boss@x.test' },
  ];

  it('joins the meeting date and counterpart of an exported anketa', () => {
    expect(
      privateNotesForExport([{ anketaId: 'a2', text: 'hi' }], exported),
    ).toEqual([
      {
        anketaId: 'a2',
        meetingDate: '2026-09-15',
        counterpartEmail: 'boss@x.test',
        text: 'hi',
      },
    ]);
  });

  it('keeps only the id for an anketa the export skipped, and still includes the text', () => {
    expect(
      privateNotesForExport([{ anketaId: 'gone', text: 'kept' }], exported),
    ).toEqual([{ anketaId: 'gone', text: 'kept' }]);
  });

  it('flags notes that would not open, with no text field', () => {
    const [entry] = privateNotesForExport(
      [{ anketaId: 'a1', text: null }],
      exported,
    );

    expect(entry).toEqual({
      anketaId: 'a1',
      meetingDate: '2026-09-01',
      counterpartEmail: 'boss@x.test',
      unreadable: true,
    });
    expect(entry).not.toHaveProperty('text');
  });

  it('keeps empty notes as text, not unreadable', () => {
    expect(privateNotesForExport([{ anketaId: 'x', text: '' }], [])).toEqual([
      { anketaId: 'x', text: '' },
    ]);
  });

  it("keeps the server's order and every row", () => {
    const entries = privateNotesForExport(
      [
        { anketaId: 'a2', text: 'second' },
        { anketaId: 'gone', text: null },
        { anketaId: 'a1', text: 'first' },
      ],
      exported,
    );

    expect(entries.map((entry) => entry.anketaId)).toEqual([
      'a2',
      'gone',
      'a1',
    ]);
  });
});
