# A built-in "Quick check-in" template that repeats itself

## Problem

[GitHub issue #208](https://github.com/aleksejs1/encrypted1on1/issues/208), part of the
product-adoption work (#213): in early pilots teams stopped meeting after the first cycle, and
the regular check-in's questionnaire was one of the frictions compared with a shared document.
At form version 3 it asks the employee 6 questions with 11 inputs and the manager 4, and "all
fields are optional" (#199) doesn't make the page look shorter.

The issue deferred the decision until a no-code experiment: a company admin builds a light
template in the template editor and moves pairs onto it. That experiment was never run, and it
asks for the one thing a new team doesn't do: a manager who isn't an admin can't create a
company template, nobody builds one before a first meeting, and a company template isn't
translated into the six UI languages. This is reasoning, not pilot data (maintainer decision,
2026-10-10, to go ahead without it).

## Decision

A fifth built-in template, `lightweight`, shown as "Quick check-in":

- **Employee:** the built-in `mood` block, unchanged, and one new free-text question,
  `periodNotes` ("Highlights and where I need help").
- **Manager:** the built-in `support` block.
- **No "topics" question.** The issue sketched one, but the shared topics list (#206) now sits
  above the questions of every meeting, and form version 3 dropped the built-in "What else to
  discuss" blocks for that reason.
- **It repeats itself:** `'lightweight' => 'lightweight'` in `Anketa::NEXT_CYCLE_TEMPLATE_KEY`.
  [`career_growth`](2026-09-23-career-growth-template-does-not-recur.md) and
  [`support_checkin`](2026-09-23-support-checkin-template-does-not-recur.md) fall back to
  `regular` because a self-recurring template keeps a pair on it "until someone notices and
  switches back by hand". For those two that is a bug: they are a phase. This template is the
  pair's regular check-in in a shorter form, so staying on it is the point. The archive form's
  "Next meeting type" picker (#140) shows the choice and lets either side change it.

`periodNotes` is not added to the built-in ids a company template may reuse
(`EMPLOYEE_BUILTIN_QUESTION_IDS`), like the questions of every other non-regular template.
No schema change: `templateKey` is a 40-character string column.

## The create form's one-off note

A 1:1 created next to the pair's open one is a one-off, and the form's note ended with "to use
this type for the pair's next regular meeting, choose it as the next meeting type when
archiving", shown for every type but Regular check-in, on the assumption that a chain's next
meeting is Regular anyway. A pair on Quick check-ins (or on a company template, which that
rule already got wrong) is the case where someone picking Regular needs the sentence. It is
now shown for every type, reworded without "instead" so that it reads right when the type is
already the default. Showing it only when the type differs from the chain's real default
was tried three ways and dropped: comparing with the open meeting's own type is wrong after a
one-meeting type, a client copy of `NEXT_CYCLE_TEMPLATE_KEY` can't know a company template or
whether it's archived, and asking the server for the open meeting's detail adds a request and
loading and failure states to the form for one sentence.

## Accepted consequences

- **The Report has no achievements or growth entries for a pair that stays on it.** For
  `support_checkin` that was a gap of one meeting; here it lasts as long as the pair keeps the
  template. Goals and their checkpoints are unaffected.
- **A workload trend line can freeze.** The "By person" list builds its mood and workload lines
  from the pair's whole history, with no time window. A pair that moves from a regular check-in
  to this one keeps its workload line at the old points, next to a mood line that keeps growing.
  Left as it is: the line is the pair's real history, and hiding it would need a rule for when
  history stops counting. A pair that starts on this template has no workload line at all.
- **The choice lives in the previous meeting only, not in the pair.** The next meeting's default
  comes from the meeting being archived. A Quick check-in pair that picks Career growth (or
  another one-meeting type) for one meeting gets a Regular check-in after it, and has to pick
  Quick check-in again on that archive form. The create form doesn't read the pair's history
  for the template either, unlike role and periodicity: a pair that archived with "Don't create
  the next meeting" and later starts again is offered Regular check-in. Remembering a pair's
  template would need a per-pair setting, or a walk back through the chain; neither is built.
- **A tab opened before the deploy doesn't know the key.** It shows such a meeting with the
  regular questions (`templateFor()`'s fallback), and answers published there to questions the
  template doesn't have are stored but not shown by an up-to-date page. Every new built-in
  template had this. New here: the archive form's default next type can be a built-in key the
  old page's "Next meeting type" select has no option for. Old code can't be fixed by new
  code; a reload ends it.
- **The whole `mood` block is reused, three inputs, not only "how are you now".** A one-input
  version would need a second question object sharing the `moodNow` field id. The block is the
  same object as the regular check-in's, so the trend line and comment threads keep working.

## Alternatives considered

- **Make it the default for new meetings**: rejected. It changes what every company gets,
  including those that want the full questionnaire.
- **A per-company "default template" setting**: not built. It would only change the create
  form's preselection. The next meeting in a chain comes from
  `Anketa::nextCycleTemplateKeyFor()`, where `onboarding`, `career_growth`, `support_checkin`
  and an archived company template all fall back to `regular`, so a company whose default is
  the Quick check-in would still get a regular one after an onboarding meeting. Making the
  setting real means changing that fallback, a migration on `companies` and the company
  settings API. To be reconsidered once there is usage data for this template.
- **Fall back to `regular`, like the other templates**: rejected, see above. A pair would have
  to re-pick the short form at every archive.

## Verification

`AnketaTest::testNextCycleTemplateKeyFor` and
`AnketaLifecycleServiceTest::testDefaultNextTemplateUsesNextCycleTemplateKeyMap` have a
`lightweight` case, and `AnketaControllerTest::testArchivingAQuickCheckInCreatesAnotherByDefault`
goes through the create and archive endpoints. The question set is pinned by the `'lightweight'` block in
`frontend/src/anketa/questions.test.ts`, whose existing per-template checks (the cross-check
against `Anketa::TEMPLATE_KEYS`, unique field ids, i18n keys present, the list label) cover the
new key by iterating `ANKETA_TEMPLATES`. `frontend/e2e/dual-actor-anketa.spec.ts` has a
dual-actor scenario: the template reaches both sides, an answer to its own question decrypts in
the counterpart's browser, and an archive with an untouched form creates a successor on the
same template.
