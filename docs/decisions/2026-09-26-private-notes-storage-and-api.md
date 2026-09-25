# Private notes: storage and API

## Problem

A user asked for private notes during a 1:1: text that the other participant never sees. Every
piece of text on an anketa page today is encrypted with the shared anketa key, so both
participants can read all of it. [GitHub issue #132](https://github.com/aleksejs1/encrypted1on1/issues/132)
designs one private pad per anketa per participant, readable only by its author, and splits the
work into four issues. This record covers the first,
[#136](https://github.com/aleksejs1/encrypted1on1/issues/136): the backend storage and API (§5 of
the design). The panel (#137), the two-column layout (#138) and the export and threat-model docs
(#139) follow. #139 also writes the decision record for the product decisions in the design's §10.

## Decision

Implemented as designed in #132 §5:

- **Separate table.** A new entity, `App\Entity\AnketaPrivateNote`, is stored in
  `anketa_private_notes` and holds `encryptedNotesKey`, `notesBlob`, `version` and `updatedAt`.
  It has a unique index on (anketa, author) and a `company_id` denormalized from the anketa, so
  `CompanyFilter` scopes it (`docs/architecture-invariants.md` §3). Both ciphertext columns pass
  the plaintext rule by name, with no `#[AllowPlaintext]`.
- **Own endpoints, nothing added to shared payloads.** `AnketaPresenter` is unchanged. The
  endpoints are `GET` and `PUT /api/anketas/{id}/private-notes`, and `GET /api/me/private-notes`
  for the export. Every read is by anketa and the requester as author. The endpoints live in
  `AnketaController`, so they share its `findAccessible()`, the one place anketa access is
  decided.
- **The session user must be the author.** A `PUT` whose `authorId` isn't the session user gets
  403 `errors.notes_wrong_account` and writes nothing. `AnketaPrivateNote`'s constructor also
  rejects an author who isn't a participant, so that rule doesn't rest on the controller alone.
- **Allowed while archived**, unlike every other anketa write.
- **Allowed on the shared demo accounts.** Anyone who logs into the same demo account before the
  hourly reset can read them. That's the design's decision 8 (§10): the panel works there, and #137
  shows a demo-specific subtitle saying other visitors can read the notes.
- **Versioning.** Inserting needs `expectedVersion: 0` and creates version 1, and each overwrite
  increments it. Any other mismatch is a 409 carrying the current row, or all nulls when there's
  no row. An overwrite is a conditional `UPDATE`, which is where this differs from the design
  (below). When two tabs insert at once, the loser's flush hits the unique index. That's caught,
  reported to Sentry, and returned as a 409 with nulls, so the client re-reads the row. The
  failed flush closes the EntityManager, so it isn't used afterwards, the same as
  `ActivationController`.
- **Deletion.** `AccountDeleter` removes the user's rows in the same unit of work as the
  anonymization. The demo reset deletes every note the demo accounts wrote, with one bulk `DELETE`
  right before its flush. That covers the pair's anketas it's about to delete, since only a
  participant can author notes. It also covers any other anketa, such as one a visitor created
  with the roles swapped. That anketa survives the reset, and the demo keypair is restored on
  every run, so its notes would otherwise stay readable to every later visitor. There's no anonymization to stay atomic with there. A visitor's
  autosave landing between an earlier `SELECT` and the flush would otherwise block the anketa
  delete. The doc-screenshot script's own account reset also deletes notes rows first.

### Where the implementation differs from the design

- **Validation failures are 400, not 422.** The design's client contract (#132 §6.3) expects
  "422 for cap or validation". This app's `JsonExceptionListener` turns every validation failure
  into **400** with `violations: [{ property, message }]`. The message is already translated, not
  an `errors.*` key, so #137's client can't match on the key. It should treat a 400 whose
  violations include `property: 'notesBlob'` as `tooLarge`. That's the only notesBlob rule the
  client can break, because it always sends a non-empty string. Any other 400 is `invalid`. The
  cap stays an `#[Assert\Callback]` with `DtoViolation::add()`, as the design says, so its message
  is translated in all six locales.
- **The version check is atomic.** The design (§5.2) keeps the other blobs' approach: load the
  row, compare in memory, then write. Two overwrites landing at the same instant could then both
  succeed. Here an overwrite is one conditional `UPDATE … WHERE version = :expected`
  (`AnketaPrivateNoteRepository::overwriteIfVersion()`, the same approach as
  `markArchivedIfOpen()`), so the database lets only one match. It also means an autosave doesn't
  read the notes row, blob included, just to compare one integer. Only a 409 reads it. The anketa
  row is still loaded for the access check, as on every anketa endpoint. Review showed this was
  cheap enough to take.
- **The MySQL migration pins its collation.** Every earlier MySQL table was created as
  `utf8mb4_unicode_ci`. This migration's generated `CREATE TABLE` carried no `COLLATE`. MySQL
  8.4's default, `utf8mb4_0900_ai_ci`, then made all three foreign keys fail as incompatible
  (error 3780, reproduced against a real MySQL 8.4). The collation is pinned by hand in the
  migration. The next generated MySQL migration that creates a table needs the same check, until
  the Doctrine config sets a default table collation. That's a separate change, not made here.

### Known limits

- **A save racing account deletion can re-insert a row.** Suppose a save request passed
  authentication before the deletion committed and found no row. It then inserts one for the
  now-anonymized user. Drafts share this race class. Notes autosave often while someone types, so
  it's likelier here, but it needs the account deleted mid-typing.
- **A demo visitor's first save can still race the hourly demo reset.** If it lands in the moment
  between the reset's notes `DELETE` and its flush, MySQL rejects the anketa delete. That hour's
  reset then fails, and the next run succeeds. On SQLite the reset succeeds and leaves an orphaned
  row.

## Alternatives considered

- **A DQL `DELETE` for account deletion.** `remove()` loads each row, blob included, so a user
  with hundreds of near-cap notes costs tens of megabytes. A DQL `DELETE` would avoid that, but it
  runs at once rather than in the caller's flush. The design's atomicity with the anonymization
  was kept.
- **Paging `GET /api/me/private-notes`.** It returns every row in one response, as the design
  specifies, with the same memory order as deletion. It's left for #139, which builds the only
  consumer, to revisit if it matters.
- **A separate `PrivateNotesController`.** It would keep the privacy-critical endpoints in one
  short file, and `GET /api/me/private-notes` next to the other `/api/me/*` routes in
  `AuthController`. But it would need its own copy of `findAccessible()`.

## Verification

- `PrivateNotesTest` covers:
  - insert (with the anketa's company on the row), update, and the 409 shapes (stale version,
    reinsert at 0, no row);
  - the first-insert race, simulated with a `preFlush` listener that inserts the other tab's row;
  - saving while archived;
  - `authorId` mismatch;
  - non-participant (403), unknown (404) and another company's (404) anketa;
  - the cap, with its translated message and the boundary accepted;
  - malformed keys, and each DTO field rule;
  - authentication for all three endpoints.
- The same file has the §5.3 counterpart invariant test. After the author saves twice, the
  counterpart gets `null` from the notes endpoint and `[]` from `/api/me/private-notes`. Detail,
  bulk, list and live-state contain neither the author's key or blob nor the notes field names.
  The counterpart's own save creates its own row and leaves the author's untouched.
- `AccountDeleterTest`, `ResetDemoDataCommandTest` and `SerializationBoundaryTest` each gained a
  case, and `AnketaPrivateNoteTest` unit-tests the entity's initial version and participant guard.
  The overwrite itself is covered by the functional tests. The demo reset test seeds notes by both
  demo accounts, plus one on a swapped-role anketa outside the pair. Dropping the delete-by-author
  condition made it fail.
- Migrations ran up, down and up on SQLite and on a throwaway MySQL 8.4. After them, neither
  engine's schema diff touches `anketa_private_notes`.
- The notes, deletion and demo-reset tests pass against MySQL too. With the notes removal taken
  out of the demo reset, MySQL fails with the foreign-key violation. On SQLite, which runs without
  foreign keys, the reset itself then succeeds, and only the test's own check for leftover rows
  catches it.
- Mutation checks, each of which made its test fail:
  - removing the `authorId` check;
  - removing the race `catch`;
  - dropping `PositiveOrZero`, once the test environment's cached validator metadata was cleared.
