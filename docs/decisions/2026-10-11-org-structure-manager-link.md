# A company's org structure is one optional manager link per person

## Problem

A company was a flat list of accounts: nothing recorded who reports to whom. The create form
couldn't check the chosen role against a fact
([GitHub issue #250](https://github.com/aleksejs1/encrypted1on1/issues/250)), and an admin
couldn't see who had been left without a manager.
[GitHub issue #265](https://github.com/aleksejs1/encrypted1on1/issues/265) tracks the whole
feature; this record covers its first part,
[#266](https://github.com/aleksejs1/encrypted1on1/issues/266), the only one with a schema
change.

## Decision

`User::$manager`, a nullable reference to another user (`users.manager_id`), written only through
`PUT /api/admin/users/{id}/manager` with `{"managerId": "<id>" | null}`.

- **One manager per person.** No dotted lines, no teams.
- **Optional.** A company that never sets a manager works exactly as before.
- **It informs, it never restricts.** A 1:1 can still be created with anyone in the company, in
  either role, and the create form still preselects no role
  ([the role-selection record](2026-10-10-explicit-role-selection.md)).
- **Company admins only.** Anyone naming someone their manager, or claiming a report, without the
  other side's consent was rejected in #250.
- **No manager dashboard.** This adds none.

## Who can see it

It is plaintext the server stores, like display names and the roles on a 1:1; see the threat
model in [encryption.md](../encryption.md#threat-model--what-a-full-server-compromise-reveals).

An admin sees the whole company: `GET /api/admin/users` carries `managerId`. `GET /api/users`,
open to every user of the company, must never carry it, or any employee could rebuild the whole
tree. So the property and its getter have no serialization group, which
`SerializationBoundaryTest` checks, and `UserResourceTest` pins the exact fields of a
`GET /api/users` row. A person's own manager and direct
reports get their own endpoint in
[#268](https://github.com/aleksejs1/encrypted1on1/issues/268).

## The rules

All in `App\Org\OrgStructure`. The manager must be in the same company, not the person
themselves, not blocked, not deleted, and the assignment must not close a cycle. A deleted
account can't be given a manager.

- **One check for one assignment and for a batch.** `violations()` takes a list of assignments
  and checks each against the tree as it would be with all the valid ones applied; `assign()` is
  a batch of one. The CSV import
  ([#271](https://github.com/aleksejs1/encrypted1on1/issues/271)) can then swap two people in
  one file without a false cycle, and there is no second implementation to drift. A row rejected
  for its own sake (a blocked manager, say) counts as not applied when the other rows' cycles
  are checked.
- **A cycle rejects the whole batch.** Rows rejected for another reason can be skipped and the
  rest applied, but with a cycle in the answer nothing may be applied: applying the remaining
  rows could still store one.
- **Repeating the stored manager is always fine**, even if that manager has been blocked since:
  blocking leaves reports in place, so re-importing the current tree must not fail on them.
- **The cycle check is a walk up from the new manager.** Each person has at most one manager, so
  a cycle can only close through the person being assigned. No graph algorithm is needed.
- **No locking.** `SELECT … FOR UPDATE` doesn't exist in SQLite, and a lock taken after the users
  were loaded wouldn't stop a concurrent cycle anyway. Two admins changing the tree at the same
  moment can store a cycle; that is accepted for a rare, admin-only action. Every walk therefore
  stops at a person it has already seen, so a stored cycle can't hang a request.
  The same goes for an assignment racing an account deletion: it can leave a link to or from
  the deleted account. An admin clears it by hand: clearing is the one change a deleted
  account still accepts.
- **One exception type**, `OrgStructureException`, with an `OrgStructureError` reason whose value
  is the `errors.*` key. The controller answers 400 with the translated message.
- **Another company's user is a 404**, the same answer as an id that doesn't exist, for the
  manager as for the person.

## Blocking and deletion

- **Blocking clears nothing.** It is reversible. A blocked manager keeps their reports, but can't
  be given new ones.
- **Deletion clears both directions**, in `AccountDeleter`: the deleted person's reports lose
  their manager, and the deleted person loses theirs. An account is anonymized in place, not
  removed, so the foreign key's `ON DELETE SET NULL` never fires for it (and SQLite, as this app
  connects to it, doesn't enforce foreign keys at all). Done through the entities, so it lands
  in the same flush as the anonymization.

A person whose manager was deleted is then indistinguishable from one who never had a manager.
The admin panel's "No manager" filter
([#267](https://github.com/aleksejs1/encrypted1on1/issues/267)) lists both.

## Migrations

Generated with `app:make-dual-migration` and trimmed by hand. SQLite adds the nullable foreign
key column with a plain `ALTER TABLE … ADD COLUMN`, not the generated rebuild of `users`. The
MySQL column takes the table's `utf8mb4_unicode_ci`, the same as `users.id`. Both were run up,
down and up again against real databases (SQLite, MySQL 8.4).
