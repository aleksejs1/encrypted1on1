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
one, it is cleared whenever the colleague field changes, and "Create 1:1" stays disabled until a role
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
- **The role is asked after the colleague, and any change of the colleague field clears it.**
  The role radios are disabled, with a line saying why, until a colleague is chosen;
  `setCounterpart()` then clears the role whenever the field's value changes, typing included.
  Before, a clicked role stayed when the colleague was replaced. Three more forgiving versions
  were tried in review and dropped, each for a way a role could end up standing for someone it
  wasn't clicked for: keeping a role clicked before any colleague (a click made while the field
  was being retyped counted as one), a separate "colleague the role was chosen with" variable
  ('' couldn't tell "none yet" from "being replaced"), and remembering the role per colleague
  (going back to the first colleague brought it back checked, unasked). The price of the simple
  rule: fixing a typo in the colleague's name means clicking the role again.
- **The button still reads "Create another 1:1 with the same settings".** The settings it keeps
  are the meeting type and the periodicity. The role is no longer one of them: it belongs to
  the pair, and the colleague starts empty.
- **The pair link's "Schedule the next one"** (#203) preselects the colleague and no role.
- **The old `e1o1:lastRole` key is left in users' browsers, unread.** Removing it would need
  code that runs on every form load forever, to delete a value nothing reads. Don't give a
  later setting that key name: it would read values from before this change.

`handleSubmit()` now reads the whole form before its first await. The form stays editable while
a submit is in flight, and choosing another colleague then used to send the first colleague's
role, and possibly their sealed key, for the second. That predates this change, but it would
have broken the one-role-per-pair rule. Disabling the form while submitting was tried first and
dropped: it needed a wrapper every future control has to sit inside, and a failed submit lost
keyboard focus.

`UserTypeahead`'s list closes 150ms after blur, so that a click on a suggestion lands first.
The timer wasn't cancelled when the field was focused again, so choosing two colleagues in
quick succession closed the list just reopened. Found by this change's e2e scenario and fixed.

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

`frontend/e2e/dual-actor-anketa.spec.ts` has a scenario for a pair that already has a 1:1: a
freshly opened form has the role radios disabled, with the "choose the counterpart first" line,
and neither selected for either participant; after choosing the colleague they are enabled and
still neither is selected, and the form can't be submitted. Each of the two removed defaults
would have selected a role there (the device one for the creator, the history one for both). A
clicked role is gone as soon as the colleague field is typed in, for a third person, and for the
first colleague chosen again. "Create another" opens with no role, and the pair link's "Schedule
the next one" opens with the colleague chosen and no role.
