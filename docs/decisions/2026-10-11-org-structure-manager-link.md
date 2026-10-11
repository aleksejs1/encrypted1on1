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
reports have their own endpoint, `GET /api/me/org`
([#268](https://github.com/aleksejs1/encrypted1on1/issues/268), below).

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

## The admin panel's column

[GitHub issue #267](https://github.com/aleksejs1/encrypted1on1/issues/267), frontend only: a
"Manager" column in the admin user table, and an "Only people without a manager" checkbox over
it. The rules for the options and the filter are `frontend/src/admin/managerColumn.ts`.

- **Edited with an explicit Save, one row at a time.** A row shows the manager's name and a
  "Change" button, which turns the cell into a select with Save and Cancel. The issue asked for
  a select that saves on change; that was built first and dropped in review. Where a closed
  select changes value on each arrow-key press (Chrome and Firefox on Windows and Linux), every
  press would have written an assignment, and a refused option couldn't be arrowed past. It
  also rendered every row's select at once: rows × users option elements.
- **One thing at a time.** While a row's editor is open or any row action (block, admin, delete)
  is in flight, every other row action, every "Change" and the filter are disabled: one `busy`
  flag. So the picked manager can't be blocked or deleted under the editor, a row can't be
  deleted under its own save, and the filter can't hide a row being edited. Blunt, but there is
  no second state to reconcile.
- **Save always sends what the select shows**, unchanged or not. The table is loaded once, so
  "unchanged" can't be told from here; after a Save the row shows what the server holds.
- **The server decides.** The options are everyone who is not blocked or deleted, minus the row's
  own user. They are not narrowed to "who wouldn't make a cycle": a client-side guess would be
  wrong for deeper cycles, and the server refuses those anyway. On a refusal the row stays in
  edit mode with the server's message under the select (`role="alert"`).
- **A blocked manager is shown as the manager**, marked "(blocked)", and stays an option in
  their own reports' rows. Nobody else is offered them.
- **A deleted account's row has no "Change".** A link left on one by the deletion race is shown
  (marked "deleted account" on the other side) but can't be cleared from the panel; the endpoint
  accepts the clearing.
- **Deleting an account clears its links in the table at once**, both ways, as the server did.
- **The filter leaves deleted accounts out**: they aren't people to find a manager for. It does
  list a person whose link still points at a deleted account.
- **Focus** follows each step through `anketa/keepFocus.ts`, like the meeting page: to the select
  on "Change" and after a refusal, back to the row's button after Save or Cancel, to the filter
  checkbox when the filter has just removed the saved row, and nowhere if the admin has moved on
  while the request ran.
- **Accepted.** The table is loaded once, like the rest of the panel: a manager another admin
  blocked meanwhile is still offered, and the server's refusal is the answer. Blocked accounts
  with no manager are listed by the filter, since blocking is reversible.

## My own links: `GET /api/me/org`

[GitHub issue #268](https://github.com/aleksejs1/encrypted1on1/issues/268), backend only
(`OrgController`). It answers with the caller's `manager` (or null) and `directReports`, each as
id, display name and email, the reports in email order (only so the answer is stable; a caller that shows them sorts by
what it shows).

- **One level each way.** Not the manager's manager, not a report's reports, not the manager's
  other reports. Nobody can walk the tree with it.
- **A separate endpoint, not fields on `GET /api/me`**, which is polled often; this needs a query
  for the reports, and only the create form asks for it.
- **Blocked and deleted people are left out on both sides**, so a blocked manager reads as no
  manager. The endpoint exists for the create form's role warning
  ([#269](https://github.com/aleksejs1/encrypted1on1/issues/269)), and a 1:1 with a blocked
  person can't be created at all, so there is nothing to warn about. The stored link is
  untouched.

## The create form

[GitHub issue #269](https://github.com/aleksejs1/encrypted1on1/issues/269), frontend only: the
form reads `GET /api/me/org` once, badges my manager and my direct reports in the colleague
picker, and warns when the clicked role contradicts the reporting line. It never selects a role
and never blocks creating a 1:1. The reasoning is in
[the role-selection record](2026-10-10-explicit-role-selection.md#the-reporting-line-a-badge-and-a-warning-not-a-default).
