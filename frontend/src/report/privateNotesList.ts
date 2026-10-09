import { nameWithEmail } from '../userDisplay';

/**
 * The "My private notes" list in Reports (GitHub issue #243): every private
 * note of mine, joined with its meeting, newest meeting first, filtered by
 * colleague and by text. All of it runs in the browser on notes the page has
 * already decrypted; the server never sees a filter or a search word.
 */

/** One of my notes rows, opened: `null` when the key or the blob won't open. */
export interface OpenedNote {
  anketaId: string;
  text: string | null;
}

/** The fields of a GET /api/anketas row the list needs. */
export interface NoteMeeting {
  id: string;
  meetingDate: string;
  archivedAt: string | null;
  counterpartId: string;
  counterpartName: string;
  counterpartEmail: string;
  counterpartDeleted: boolean;
}

/** What the page supplies, so nothing here depends on the locale or the date preference. */
export interface NoteLabels {
  formatDate: (meetingDate: string) => string;
  deletedColleague: string;
}

export interface NoteEntry {
  anketaId: string;
  /** `null`: the note can't be opened (most likely a password reset). */
  text: string | null;
  /** `null`: the note's meeting isn't in my meeting list. */
  meeting: {
    displayDate: string;
    archived: boolean;
    colleagueKey: string;
    colleagueLabel: string;
  } | null;
}

export interface ColleagueOption {
  key: string;
  label: string;
}

/** The colleague filter's "Everyone". Not a user id, which is a UUID. */
export const ALL_COLLEAGUES = 'all';

/**
 * One filter option for every deleted colleague: their names are gone, so
 * separate options would all read the same.
 */
export const DELETED_COLLEAGUES = 'deleted';

/**
 * The list's entries: notes with no text left out, unreadable ones kept,
 * newest meeting first (two meetings on one day in a fixed order), and notes
 * whose meeting is unknown last.
 */
export function joinAndSortNotes(
  notes: OpenedNote[],
  meetings: NoteMeeting[],
  labels: NoteLabels,
): NoteEntry[] {
  const byId = new Map(meetings.map((meeting) => [meeting.id, meeting]));
  return notes
    .filter((note) => note.text === null || note.text.trim() !== '')
    .map((note) => ({ note, meeting: byId.get(note.anketaId) }))
    .sort((a, b) => {
      if (!a.meeting || !b.meeting) {
        if (a.meeting) return -1;
        if (b.meeting) return 1;
        return compareStrings(a.note.anketaId, b.note.anketaId);
      }
      return (
        compareStrings(b.meeting.meetingDate, a.meeting.meetingDate) ||
        compareStrings(b.meeting.id, a.meeting.id)
      );
    })
    .map(({ note, meeting }) => ({
      anketaId: note.anketaId,
      text: note.text,
      meeting: meeting
        ? {
            displayDate: labels.formatDate(meeting.meetingDate),
            archived: meeting.archivedAt !== null,
            colleagueKey: meeting.counterpartDeleted
              ? DELETED_COLLEAGUES
              : meeting.counterpartId,
            // A deleted account's name and address are placeholders, never shown.
            colleagueLabel: meeting.counterpartDeleted
              ? labels.deletedColleague
              : nameWithEmail(
                  meeting.counterpartName,
                  meeting.counterpartEmail,
                ),
          }
        : null,
    }));
}

/** meetingDate is one fixed-width ISO format from the server, so it sorts as text. */
function compareStrings(a: string, b: string): number {
  if (a === b) return 0;
  return a < b ? -1 : 1;
}

/** The colleague filter's options: everyone with an entry in the list, by label. */
export function extractColleagues(entries: NoteEntry[]): ColleagueOption[] {
  const labels = new Map<string, string>();
  for (const { meeting } of entries) {
    if (meeting) labels.set(meeting.colleagueKey, meeting.colleagueLabel);
  }
  return [...labels]
    .map(([key, label]) => ({ key, label }))
    .sort((a, b) => a.label.localeCompare(b.label));
}

/**
 * The entries for one colleague (or ALL_COLLEAGUES) whose text, colleague or
 * meeting date contains the query, ignoring case. A note whose meeting is
 * unknown belongs to no colleague, so it's listed only under ALL_COLLEAGUES.
 */
export function filterNotes(
  entries: NoteEntry[],
  colleague: string,
  query: string,
): NoteEntry[] {
  const needle = forSearch(query.trim());
  return entries.filter(({ text, meeting }) => {
    if (colleague !== ALL_COLLEAGUES && meeting?.colleagueKey !== colleague) {
      return false;
    }
    if (needle === '') return true;
    return [text, meeting?.colleagueLabel, meeting?.displayDate].some(
      (value) => value != null && forSearch(value).includes(needle),
    );
  });
}

/**
 * Case-insensitive, and the same for the forms pasted text comes in and a
 * typed query doesn't: an accented letter as a letter plus a combining mark,
 * a non-breaking space, an "fi" ligature.
 */
function forSearch(value: string): string {
  return value.normalize('NFKC').toLowerCase();
}
