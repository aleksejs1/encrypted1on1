# The create form never preselects a role

## Problem

A pair ended up with inverted roles: the employee recorded as the manager and the other way
round, so each side got the other role's questions
([GitHub issue #250](https://github.com/aleksejs1/encrypted1on1/issues/250)). The create form
made that easy. Since [#198](https://github.com/aleksejs1/encrypted1on1/issues/198) it had no
default role for a first-time user, but it did preselect one from two other places:

- **The last role created with on this device** (`localStorage`, `e1o1:lastRole`). Someone who
  once created a 1:1 as a manager got "manager" preselected when creating one with their own
  manager.
- **The role in the pair's latest meeting.** Sensible for a pair whose roles are right, but for
  a pair with one inverted meeting it preselected the inverted role again.

A preselected radio is easy not to notice, and the mistake doesn't stay in one meeting: every
next 1:1 of the pair is created at archive with the roles of the one before.

## Decision

The form never carries a role over from another meeting. `myRole` is null until the user clicks
one, it is dropped when another colleague is chosen, and "Create 1:1" stays disabled until a role
is chosen ([GitHub issue #251](https://github.com/aleksejs1/encrypted1on1/issues/251), the first
part of #250). A role is a statement about one pair, so it is chosen for that pair.

- `readLastRole()`, `rememberLastRole()`, `defaultRole()` and `pairRole()` are deleted from
  `frontend/src/anketa/createDefaults.ts`, with the form's `rolePicked` flag that kept a default
  from overriding a click. This reverses the two defaults above, which #198 had introduced.
- **"Create another 1:1 with the same settings" no longer carries the role** (it keeps the
  meeting type and periodicity). #251 as written kept it, as the one exception. Review showed
  it is the same hidden default: the form reopens with the colleague empty, so the role lands on
  a different person, and with the history default gone nothing corrected it. Someone who
  created a 1:1 with their manager and then "another" with a direct report got "employee"
  selected (maintainer decision, 2026-10-10).
- **Choosing another colleague drops the role**, including one clicked by hand a moment ago.
  Before, a clicked role stayed when the colleague was replaced. The rule is `roleStandsFor()`:
  a role clicked before any colleague stands for the one chosen next, and typing in the
  colleague field and choosing the same person again keeps it. The first version cleared the
  role on every change of the field's value, which also undid a click made before the colleague
  was chosen, or while the pair link's colleague was still loading.
- **The pair link's "Schedule the next one"** (#203) preselects the colleague and no role.
- **The old `e1o1:lastRole` key is left in users' browsers, unread.** Removing it would need
  code that runs on every form load forever, to delete a value nothing reads. Don't give a
  later setting that key name: it would read values from before this change.

The cost is one more click per 1:1 created by hand, also for a manager setting up a whole team
through "Create another", and, until #252 shows the pair's last role as a fact, nothing on the
form of an established pair says which way round they were: a pair restarting its chain by hand
(after "Don't create the next meeting", or through the pair link) can now pick the wrong role
where the history default would have picked the right one.

## Alternatives considered

- **Keep the pair-history default and drop only the device one**: rejected. It is the default
  that keeps an inverted pair inverted, and the pairs it helps lose one click.
- **Show the pair's last role as a hint without selecting it**: not here. It belongs with the
  role cards that name both people
  ([#252](https://github.com/aleksejs1/encrypted1on1/issues/252)), where it can be worded as a
  fact ("in your last 1:1 you were the employee") and not as a suggestion.

## Verification

`frontend/e2e/dual-actor-anketa.spec.ts`: after the pair's first 1:1 exists, a freshly opened
form has neither role selected for either participant, before and after choosing the colleague,
and can't be submitted. Each of the two removed defaults would have selected a role there (the
device one for the creator, the history one for both). "Create another" opens with no role, a
clicked role survives choosing the same colleague again and choosing a colleague after it, and
the pair link's "Schedule the next one" opens with the colleague chosen and no role. Dropping
the role for another colleague is covered by `roleStandsFor()`'s unit tests, not in a browser.
