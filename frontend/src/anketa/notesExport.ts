import {
  decryptNotes,
  notesAssociatedData,
  unwrapNotesKey,
} from '../crypto/privateNotes';

/**
 * Private notes in the data export (GitHub issue #132 §7): a separate
 * top-level `privateNotes` array, not part of the per-anketa loop. That loop
 * skips an anketa whose key won't unseal (after a password reset, until the
 * counterpart re-shares), but notes have their own key and may still open.
 */

/** The wire shape of GET /api/me/private-notes (AnketaController::listOwnPrivateNotes()). */
export interface OwnNotesRow {
  anketaId: string;
  encryptedNotesKey: string;
  notesBlob: string;
}

/** The anketa fields joined in, for anketas the export already includes. */
export interface ExportedAnketaInfo {
  id: string;
  meetingDate: string;
  counterpartEmail: string;
}

export type ExportedPrivateNotes = {
  anketaId: string;
  meetingDate?: string;
  counterpartEmail?: string;
} & ({ text: string } | { unreadable: true });

/** The notes' text, or null when the key or the blob won't open (a reset, a swap, corruption). */
export async function openNotesForExport(
  row: OwnNotesRow,
  userId: string,
  publicKey: Uint8Array,
  privateKey: Uint8Array,
): Promise<string | null> {
  try {
    const notesKey = await unwrapNotesKey(
      row.encryptedNotesKey,
      publicKey,
      privateKey,
    );
    return await decryptNotes(
      row.notesBlob,
      notesKey,
      notesAssociatedData(row.anketaId, userId),
    );
  } catch {
    return null;
  }
}

/**
 * The export entries, in the server's order. Meeting date and counterpart are
 * joined in only when that anketa was exported; otherwise only its id.
 */
export function privateNotesForExport(
  opened: { anketaId: string; text: string | null }[],
  exportedAnketas: ExportedAnketaInfo[],
): ExportedPrivateNotes[] {
  const anketas = new Map(exportedAnketas.map((a) => [a.id, a]));
  return opened.map(({ anketaId, text }) => {
    const anketa = anketas.get(anketaId);
    const info = anketa
      ? {
          anketaId,
          meetingDate: anketa.meetingDate,
          counterpartEmail: anketa.counterpartEmail,
        }
      : { anketaId };
    return text === null
      ? { ...info, unreadable: true as const }
      : { ...info, text };
  });
}
