# The 'career_growth' template's next cycle falls back to 'regular'

## Problem

[GitHub issue #105](https://github.com/aleksejs1/encrypted1on1/issues/105) (and the meeting-templates
proposal's §7.3 recurrence map, `private/anketa-meeting-templates-proposal.md`, not tracked in git) had
the new "Career growth" template repeat itself on auto-recreation (`'career_growth' =>
'career_growth'` in `Anketa::NEXT_CYCLE_TEMPLATE_KEY`). But `AnketaLifecycleService::createNextAnketa()`
schedules the next cycle at the pair's inherited `periodicityDays`, and `CreateAnketa.svelte` only
offers 7/14/30 days. Self-recurrence would therefore turn a quarterly career conversation into a
weekly/biweekly/monthly one, and permanently replace the pair's regular check-in until someone
noticed and recreated a regular anketa by hand.

## Decision

`'career_growth' => 'regular'`, the same one-off rule `'onboarding'` already uses (maintainer
decision, 2026-09-23). A career conversation is chosen from the creation-time picker when it's
wanted. The picker copy says "e.g. once a quarter" rather than promising a quarterly schedule,
since nothing schedules one.

## Alternatives considered

- **Repeat itself, as the issue sketched**: rejected for the cadence mismatch above.
- **A real quarterly cadence** (a per-template interval independent of `periodicityDays`):
  noticeably larger scope (new scheduling logic, and effectively a second anketa chain per pair
  alongside the regular one). Left for a separate issue if it's ever wanted.
- **Default the archive form's "skip next meeting" checkbox on for `career_growth`**: built, then
  reverted after review. It targeted the fork described below, but keyed off the wrong signal. It
  gave the wrong default for a pair whose only anketa is a career conversation (silently ending its
  cadence), and it silently applied to the overdue card's "cancel as missed" button too. It also
  did nothing for the identical fork via a second manual regular anketa.

## Known limitation (pre-existing, tracked separately)

For a pair that already meets, archiving its regular anketa auto-creates the next one, so a career
conversation is created by hand *next to* an already-open regular anketa. That duplicates
carried-forward goals/outcomes into both. Archiving the career anketa with the form's defaults then
auto-creates a second regular successor, forking the pair into two parallel chains unless someone
ticks "skip next meeting". A plain second regular anketa reaches the same state. The real condition
is "this pair already has another open anketa", not the template. Tracked as
[GitHub issue #111](https://github.com/aleksejs1/encrypted1on1/issues/111).

## Verification

`AnketaTest::testNextCycleTemplateKeyFor` (data-provider case per template, including
`career_growth`), `AnketaLifecycleServiceTest::testArchiveWithNextMeetingUsesNextCycleTemplateKeyMap`
(proves `archive()` goes through the map), plus `AnketaTest::testEveryTemplateKeyHasAnExplicitNextCycleEntry` so a future
template can't silently fall back to the default rule.
