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

The question itself was abstract too: "Are you leading this 1:1 as the manager? Yes / No" named
nobody, so it was easy to answer for the wrong person. See "Role cards with names" below.

A preselected radio is easy not to notice, and the mistake doesn't stay in one meeting: every
next 1:1 of the pair is created at archive with the roles of the one before.

## Decision

The form never carries a role over from another meeting. `myRole` is null until the user clicks
one, it is cleared whenever the colleague field changes, and "Create 1:1" stays disabled until a role
is chosen ([GitHub issue #251](https://github.com/aleksejs1/encrypted1on1/issues/251), the first
part of #250). A role is a statement about one pair, so it is chosen for that pair.

- `readLastRole()`, `rememberLastRole()`, `defaultRole()` and `pairRole()` are deleted from
  `frontend/src/anketa/createDefaults.ts` (`pairRole()` came back in #252 for the caption
  below, in `pairChain.ts`), with the form's `rolePicked` flag that kept a default
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
through "Create another". With #251 alone nothing on the form of an established pair said which
way round they were; the caption below is the answer to that.

## Role cards with names

[GitHub issue #252](https://github.com/aleksejs1/encrypted1on1/issues/252), the second part of
#250. The Yes / No radios became two options of the same radio group, under "Who leads this
1:1?":

- "I lead this 1:1": "I answer as the manager, {colleague} answers as the employee."
- "{colleague} leads this 1:1": "{colleague} answers as the manager, I answer as the employee."

Points that were decided:

- **The text says who has which role, not which questions each gets.** It has to be right for
  every meeting type, company templates included.
- **Before a colleague is chosen** the options are shown disabled, as in #251, with "The
  counterpart leads this 1:1" and no descriptions. Hiding them would hide that a role is asked
  at all.
- **A pair with a 1:1 gets one line of fact** under the option matching my role in their
  latest meeting: "Your role in your most recent 1:1 together: employee." It is `pairRole()`
  in `frontend/src/anketa/pairChain.ts` (latest by meeting date then id, one-offs included,
  open or archived, hence no verb: that meeting may be over, open or still ahead). A one-off
  dated after the chain's meetings is therefore the one quoted. It selects
  nothing and isn't worded as "same as before": for an inverted pair that would push toward
  the wrong role again.
- **Only the colleague is named.** The issue also put my own name in "I lead this 1:1 (…)";
  it added a second way of building an option's text and a no-name variant in 6 locales for
  the moment before the page has loaded, and "I" is already unambiguous.
- **The name is the display name, with the email only as its fallback.** Two colleagues with
  the same display name look the same in the options; the colleague field just above still
  shows the chosen one's email.
- **They are plain radios with a description**, laid out like the meeting-type picker on the
  same form, not a new card component.
- **What "Create 1:1" is waiting for is said under it** once a colleague is chosen (the role,
  then the date), in a `role="status"` line, so it is announced as the form is filled in.
  With no role ever preselected, a disabled button is now everyone's first sight of the form.
  Before a colleague is chosen the role group's own "choose the counterpart first" line says
  it. Each radio is also described (`aria-describedby`) by the lines under it, which a
  screen reader in forms mode would otherwise skip.

A `code-review` pass over #251 after its merge found one regression in it, fixed here: after a
`template_unavailable` answer, a company template chosen while the request was in flight was
kept without checking it against the reloaded list (`isOffered()` in `CreateAnketa.svelte` now
serves both that path and the mount-time one).

## The reporting line: a badge and a warning, not a default

Since [GitHub issue #269](https://github.com/aleksejs1/encrypted1on1/issues/269) the form knows
the company's reporting line between me and the chosen colleague, where an admin has recorded
one ([the org-structure record](2026-10-11-org-structure-manager-link.md)). It uses it in two
ways, and neither is a role default:

- **A badge in the colleague picker**: "Your manager" beside my manager, "Reports to you"
  beside my direct reports. A fact about the person, like the "most recent 1:1" line above. The
  same badge stays under the field once the colleague is chosen: the list is closed by then, and
  never opens for a colleague preselected by a pair link.
- **A warning under the role options** when the clicked role is the opposite of the reporting
  line: I lead a 1:1 with my own manager, or my own report leads one with me. It appears only
  after the click, and only then.

The role still starts empty, is still cleared when the colleague changes, and the warning never
disables "Create 1:1". The reporting line could have preselected the role, and for most pairs it
would be right. It isn't used that way for the reason the rest of this record gives: a
preselected radio is easy not to notice, a reporting line can be out of date or simply wrong,
and a 1:1 the other way round (a skip-level, a mentoring pair, a peer review) is legitimate. A
wrong fact shown as a warning costs one glance; a wrong fact turned into a default repeats in
every 1:1 of the pair.

The rule is `frontend/src/anketa/reportingLine.ts`, two pure functions with unit tests. Someone
stored as both my manager and my report (two admins at once can store such a loop) gets no badge
and no warning. `GET /api/me/org` is loaded apart from the rest of the form's data, so a slow or
failed answer leaves the form as it was before #269 and never blocks creating a 1:1. A company
with no reporting lines sees no change.

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

For #252 the same scenario checks the options' names and descriptions for both participants, the
caption under the right option for a pair with history and its absence for a new pair, and the
"what is missing" line. `pairRole()` has unit tests in `pairChain.test.ts`.
