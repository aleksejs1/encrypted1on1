# Swapping roles happens at archive, for the next 1:1

## Problem

A pair's next 1:1 is created at archive with the roles of the one before
(`AnketaLifecycleService::createNextAnketa()`). A chain that started with the manager and the
employee the wrong way round stays that way, and no endpoint could change it. Part of
[GitHub issue #250](https://github.com/aleksejs1/encrypted1on1/issues/250); the create form's side
of it is in [the role-selection record](2026-10-10-explicit-role-selection.md).

## Decision

`POST /api/anketas/{id}/archive` takes an optional `swapRolesNext` boolean
([GitHub issue #254](https://github.com/aleksejs1/encrypted1on1/issues/254)). With it, the
successor's employee is the archived 1:1's manager and the other way round. Absent, false or null,
nothing changes, so older clients keep working. No schema change.

- **The archived 1:1 keeps its roles.** Only the new row differs.
- **The sealed keys follow the people.** The request names them per person (`mySealedKey`,
  `counterpartSealedKey`), not per role, so the archiver's key lands on whichever side the archiver
  is on next:

  | archiver is | swap | `employeeSealedKey` | `managerSealedKey` |
  | --- | --- | --- | --- |
  | employee | no | `mySealedKey` | `counterpartSealedKey` |
  | employee | yes | `counterpartSealedKey` | `mySealedKey` |
  | manager | no | `counterpartSealedKey` | `mySealedKey` |
  | manager | yes | `mySealedKey` | `counterpartSealedKey` |

- **Ignored without a successor.** With `skipNextMeeting`, on a one-off, or for a pair with a
  blocked participant there is nothing to swap, and the flag is never looked at.
- **Nothing carried forward depends on a role.** Goals, outcomes and topics name their author
  by user id; the pair lookups (`findOpenForPair()`, `findMostRecentArchivedForPair()`) match an
  unordered pair; the "new 1:1" email goes to the person who didn't archive. The successor's
  questions are the ones for each person's new role, which is the point. The one query that
  matched the pair in a fixed order, the demo reset's cleanup (`ResetDemoDataCommand`), now
  matches it either way round.
- **No new concurrency.** The successor is a new row inserted in the same transaction as
  `markArchivedIfOpen()`, so of two concurrent archives one wins, with its own flag
  (see [archive exactly once](2026-09-25-archive-exactly-once.md)).
- **Either participant can send it,** like every other archive choice (the next date, the next
  meeting type). The other one sees the result in their next 1:1 and can swap back the same way.

`Anketa::counterpartOf()` is new: "the other participant" was spelled out as a ternary in the
service, the presenter and the password reset, and the swap made the service one branch too complex
for the PhpMetrics check. It throws for a user who isn't a participant.

## Known limitations (accepted)

- **The Report splits at the swap.** `Report.svelte` picks a person's 1:1s by my role in them, so
  the 1:1s from before the swap stay in the report they were in (the real manager's own "me"
  report, and the other way round) and the trends start again after it. Those 1:1s were answered
  with the wrong question sets, so merging them would mix two different forms; left as it is.
  For the same reason the person who was wrongly the manager keeps the other one in the Report's
  "report on" list, for those old 1:1s.
- **The list's mood and workload trend joins both people.** `AnketaList.svelte` plots the
  employee side's answers of every archived 1:1 with a colleague, whoever the employee was, so
  after a swap one line runs from one person's answers into the other's. This already happened for
  a pair with a hand-created 1:1 the other way round; a swap makes it routine. Not fixed here
  (backend only); it needs its own change on the list.
- **The other participant isn't told.** The "new 1:1" email and the archive response are the same
  with or without the swap; they see it when they open the next 1:1. What the archive form and the
  next 1:1 say about it is #255's.
- **The flag is dropped when no successor is created,** silently: the pair's next 1:1 then comes
  from the create form, which asks for the roles anyway. Its "your role in your most recent 1:1"
  line (#252) states the old roles in that case, and also when a one-off with the old roles is
  dated after the swapped 1:1.

Nothing in the UI sends the flag yet; the archive form's checkbox is
[GitHub issue #255](https://github.com/aleksejs1/encrypted1on1/issues/255).

## Alternatives considered

- **Swapping roles on the open 1:1 itself.** Rejected in #250. It needs a column swap in one
  `UPDATE`, which MySQL gets wrong (assignments are evaluated left to right, so both columns end up
  the same person, and SQLite, which follows the standard, would pass the tests). It races with
  `publish()` and `saveDraft()`, which check the role in memory and then write. And one participant
  could wipe the other's unpublished draft with one click.

## Tests

Not verified with real crypto yet: the sealed keys in these tests are placeholder strings, which
show where the server stores each one, not that a browser can open the result. That check, against
the real stack, comes with #255, when a browser first sends the flag.

- Unit (`AnketaLifecycleServiceTest`): the four rows of the table, asserting who is employee and
  manager, `sealedKeyFor()` for each person, who is emailed, and that the archived 1:1 is unchanged.
- Functional (`AnketaControllerTest`): the successor read from both participants' sessions
  (`myRole`, `counterpartId`, `mySealedKey`), the swapped roles inherited by the 1:1 after it, the
  manager archiving as "missed", a request without the flag (absent, false, null), and the flag
  with `skipNextMeeting`, on a one-off and for a pair with a blocked participant (each archives
  with no sealed keys and creates nothing).
- Functional (`ResetDemoDataCommandTest`): the reset removes a demo pair's 1:1 with the roles
  swapped.
