# The 'support_checkin' template's next cycle falls back to 'regular'

## Problem

[GitHub issue #106](https://github.com/aleksejs1/encrypted1on1/issues/106) adds a "Support & workload
check-in" template and, like #105 before it, sketched it as repeating itself on auto-recreation
(`'support_checkin' => 'support_checkin'` in `Anketa::NEXT_CYCLE_TEMPLATE_KEY`).

[The career-growth decision](2026-09-23-career-growth-template-does-not-recur.md) rejected
self-recurrence for two reasons. The first, a cadence mismatch (a quarterly conversation landing at
the pair's 7/14/30-day periodicity), doesn't apply here. Weekly or biweekly support check-ins are a
reasonable cadence. The second does apply: a self-recurring template keeps the pair on it
indefinitely, replacing its regular check-in until someone notices and switches back by hand. For
this template that also means the regular check-in's feelings, growth, friction and achievements
questions stay gone long after the support phase ended.

## Decision

`'support_checkin' => 'regular'`, the same rule `'onboarding'` and `'career_growth'` use (maintainer
decision, 2026-09-23, following the career-growth precedent). A support check-in is chosen from the
creation-time picker each time it's wanted.

In practice, for a pair that already meets, this changes little. A support check-in created by hand
next to the pair's open regular anketa is a one-off under
[the one-open-anketa-chain decision](2026-09-23-one-open-anketa-chain-per-pair.md) and never
auto-recreates regardless of this map. The rule only matters when the support check-in is the pair's
chain anketa, e.g. the pair's first anketa.

## Related decisions made with this template

Both are maintainer decisions, 2026-09-23, made during review of the #106 implementation.

- **The plaintext `templateKey` is accepted for this template too.** Unlike the other templates,
  `'support_checkin'` hints at why a pair met, and anyone who can read the database can see it.
  It is never shown in any admin report or notification email. `docs/encryption.md`'s threat model
  now says this explicitly. Hiding the template choice from the server would need a different
  design, since the server uses it for validation and the next-cycle rule.
- **No achievements or growth questions.** The form stays focused on relief, and the next regular
  cycle picks them up again. The manager side likewise drops `employeeAchievements`. The cost:
  when a support check-in is the pair's chain anketa, wins from that stretch have nowhere to go,
  and the employee's Report has a gap for it.

## Alternatives considered

- **Repeat itself, as the issue sketched**: rejected for the reason above. Switching back would mean
  ticking "don't create the next meeting" and creating a regular anketa by hand, a step that is easy
  to forget.

## Verification

`AnketaTest::testNextCycleTemplateKeyFor` has a `support_checkin` case, and
`AnketaTest::testEveryTemplateKeyHasAnExplicitNextCycleEntry` fails if the map entry is missing.
The question set itself, including the omitted achievements/growth questions, is pinned by the
`'support_checkin'` block in `frontend/src/anketa/questions.test.ts`.
