# Private notes

## Problem

In a 1:1, each participant wants somewhere to jot things down that the other person won't see:
a reminder to raise something, a reaction they aren't ready to share. Before this, the only
private place was an unpublished draft, and it stops being private once published.
[GitHub issue #132](https://github.com/aleksejs1/encrypted1on1/issues/132) designed private
notes, and it shipped in four parts:

1. [#136](https://github.com/aleksejs1/encrypted1on1/issues/136), storage and API:
   [`2026-09-26-private-notes-storage-and-api.md`](2026-09-26-private-notes-storage-and-api.md).
2. [#137](https://github.com/aleksejs1/encrypted1on1/issues/137), the panel:
   [`2026-09-26-private-notes-panel.md`](2026-09-26-private-notes-panel.md).
3. [#138](https://github.com/aleksejs1/encrypted1on1/issues/138), the two-column layout:
   [`2026-09-26-private-notes-layout.md`](2026-09-26-private-notes-layout.md).
4. [#139](https://github.com/aleksejs1/encrypted1on1/issues/139), the export and docs: this
   record.

This record is the overview: the design's decisions (§10) and where the shipped code differs.
The details are in the three records above. The crypto is described for readers in
[`docs/encryption.md`](../encryption.md#private-notes).

## Decisions

1. **One plain-text pad per anketa per participant.** No per-question notes, no formatting.
2. **Crypto.** The notes key is wrapped in an authenticated self-box (`crypto_box_easy` from the
   author's keypair to itself), and the blob is bound to its anketa and author as AEAD associated
   data. A server-swapped key or a moved row reads as unreadable. Notes survive an in-app
   password change and are lost on a forgotten-password reset.
3. **A separate table and separate endpoints.** No shared payload changed, so the counterpart
   can't tell whether notes exist.
4. **Editable after archive.**
5. **No live sync.** Two tabs that change the same notes get an explicit conflict, with "Keep
   both" as the default.
6. **Layouts.** A sticky second column on wide screens, a card under the header on narrow ones,
   and Hide in both.
7. **A 256 KB cap on the encoded blob,** enforced by both server and client.
8. **The demo account** gets a different subtitle, saying other visitors can read the notes. The
   panel still works.
9. **A tab-local backup** of text not yet saved.

## Where the shipped code differs from the design

- **The version check is atomic.** The design accepted a read-then-write version check as not
  atomic. The server overwrites through one conditional `UPDATE … WHERE version = ?` instead, so
  two concurrent saves can't both succeed (#136).
- **Validation failures are 400, not 422,** the app's existing convention (#136).
- **The backup key comes from the private key,** not the session master key the design named
  (§10.9). A password change would otherwise strand text that exists only in the backup, the
  same bug class as [#129](https://github.com/aleksejs1/encrypted1on1/issues/129) (#137).
- **The breakpoint is 840px (52.5em), not 820px** (§10.6). The page gutter is 24px, not 16px
  (#138).
- **Destroyed panels, and the unload warning.** A destroyed panel makes one last save and hands
  its backup over to the next panel, instead of running a detached save chain. Closing the tab
  warns while any notes backup is left (#137).

## The export

`AccountSettings.svelte`'s export calls `GET /api/me/private-notes` once and adds a top-level
`privateNotes` list: `{ anketaId, meetingDate?, counterpartEmail?, text }` or
`{ …, unreadable: true }`.

- **Why it's separate.** It isn't nested in the per-anketa loop, which skips an anketa whose key
  won't unseal, for example after a reset and before the counterpart re-shares. Notes are under
  their own key, so they may still be exported.
- **Metadata.** The meeting date and counterpart are joined in only for an anketa the export
  includes. Otherwise the entry has only the anketa's id.
- **Code.** The decrypting (`openNotesForExport()`) and the mapping (`privateNotesForExport()`,
  pure) are in `frontend/src/anketa/notesExport.ts`.
- **The footer link.** The panel's footer gets the design's "Export keeps a copy." link to
  Account settings. It navigates in-app, so the panel is destroyed and makes its last save, and
  there's no page load that would ask about unsaved notes.

### Known limit: only saved notes

The export reads the server, so it holds the last saved version of each note. Text not yet
saved stays out of it: typed within the second before the link was clicked, or held during a
failed save. That text is only in the tab-local backup. The alternative, merging backups into
the export, would bring the backup's sent-texts and conflict handling into a second place, for a
window of about a second, or an outage the panel already reports. A save that lands later is in
the next export.

## Translations

Every private-notes string was written by Claude in all six locales and hasn't had a
native-speaker review. The privacy sentences need the closest look, because they make a
security claim: the subtitle, the demo subtitle, the footer and the reset warning.

## Verification

- `notesExport.test.ts` covers:
  - opening notes: its own, a key from an earlier keypair, a blob moved to another anketa, a
    corrupted blob;
  - the mapping: joined metadata, a skipped anketa, unreadable entries with no text, empty
    notes, and server order.
- `private-notes.spec.ts`'s reset test runs against the real stack.
  - Before the counterpart re-shares, the export has no anketas but lists the notes as
    unreadable with only the anketa's id.
  - After "Start new notes", it reaches Account settings through the footer link, and the
    export has the new text with the meeting date and counterpart.
