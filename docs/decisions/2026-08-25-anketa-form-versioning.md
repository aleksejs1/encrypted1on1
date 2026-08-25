# Version the anketa question set, so it can change without breaking old anketas

## Problem

`frontend/src/anketa/questions.ts` described the whole employee/manager
question set as one global, unversioned array (`QUESTIONS_BY_SIDE`) — every
anketa, regardless of when it was created, rendered against whatever the
current code happened to define. That's fine as long as the question set
never changes, but the first real change (adding six more options to the
"feelings" checkbox field, twelve total instead of six) exposed the gap: with
no versioning, changing the array would have silently changed what an
*already-published* anketa's answers mean — a checkbox an employee left
unchecked because the option didn't exist yet would look, after the change,
indistinguishable from one they saw and declined. There's also a known
near-term product direction (per-company, eventually per-user, custom forms)
that will need *some* notion of "which form definition does this anketa use"
regardless — this was a chance to introduce the minimal version of that
concept now, without building the full customization feature.

## Decision

Added `Anketa::CURRENT_FORM_VERSION` (`backend/src/Entity/Anketa.php`, an int
constant, currently `2`) and a matching `formVersion` column, set once at
construction time and never changed afterward. Every new anketa — manual
creation and `archive()`'s auto-recreation alike — gets stamped with
whatever `CURRENT_FORM_VERSION` is at creation time; there is no API to
create an anketa at an older version. A migration
(`Version20260825140000`) backfills every pre-existing row to `1` (the
original six-option question set), since those anketas were created before
this concept existed.

`GET /api/anketas` and `GET /api/anketas/{id}` now include `formVersion` in
their response (`AnketaController::summarize()`). The frontend mirrors the
constant as `CURRENT_ANKETA_FORM_VERSION` in `questions.ts` and replaced the
static `QUESTIONS_BY_SIDE` export with `getQuestionsForSide(side,
formVersion)`, which is the only thing that actually varies by version today
— it picks between `feelingsOptionsV1` (six options) and `feelingsOptionsV2`
(those six plus six more: grateful, proud, calm, stressed, bored, lonely)
for the employee side's "feelings" field; every other field, and the entire
manager side, is identical across versions. `Anketa.svelte` (the only page
that renders answer fields, for both the viewer's own side and the
counterpart's) passes the loaded anketa's `formVersion` through; `Anketa
List.svelte`'s mood/workload option lookups use `CURRENT_ANKETA_FORM_VERSION`
explicitly since those two fields have never varied by version.

This is deliberately the simplest thing that satisfies "old anketas keep
their original questions, new ones get the new ones" — a single global
version number, not a per-company or per-user one, and not a stored
form-definition document. The eventual per-company/per-user customization
this is a step toward will most likely replace this whole mechanism with a
real form-definition reference rather than build on top of this int; nothing
here was built to anticipate that shape beyond picking a name
(`formVersion`, not e.g. `questionSetHash`) that won't be misleading if it
sticks around as a fallback for "no custom form set".

## Alternatives considered

- **Bump the version and update the six-option array in place, no versioning
  at all.** Rejected for the reason above — it would retroactively change
  the meaning of every already-published anketa's feelings answers.
- **Store the question set itself (or a hash of it) on the anketa, not a
  version number.** More future-proof against non-additive changes, but
  meaningfully more code (needs a real snapshot format,
  serialization/migration story) for a feature this narrow. Rejected as
  speculative for a two-version, additive-only change; revisit when the
  actual per-company custom-forms work starts.

## Verification

Backend: `Anketa::CURRENT_FORM_VERSION`/`getFormVersion()` unit-tested
(`AnketaTest::testNewAnketaIsCreatedAtTheCurrentFormVersion`); functional
tests confirm `POST /api/anketas` + `GET` both surface the current version
(`AnketaControllerTest::testCreateAndGetReturnTheCurrentFormVersion`) and
that `archive()`'s auto-recreated anketa also gets the current version, not
whatever the archived one had
(`testAutoRecreatedAnketaGetsTheCurrentFormVersionRegardlessOfThePreviousOnes`).
Ran the migration against the dev container's real SQLite database
(`doctrine:migrations:migrate`) and confirmed `doctrine:schema:validate`
showed no *new* drift beyond a pre-existing, unrelated cosmetic mismatch
(confirmed present on `main` before this change too, via `git stash`).
Full backend suite (`composer test`, 240 tests), `composer stan`, and
`composer cs` all clean.

Frontend: new `questions.test.ts` covers that version 1 returns the original
six feelings options, version 2 returns all twelve in the expected order,
every other employee question and the entire manager side are unaffected by
version, and `CURRENT_ANKETA_FORM_VERSION` itself resolves to the 12-option
set. Extended `src/i18n/locales.test.ts` coverage (already generic) confirmed
all four locale files (`en`/`ru`/`lv`/`es`) have the six new
`questions.options.feelingsList.*` keys with non-empty values. Full frontend
suite (`npm test`, `npm run check`, `npm run knip`, `npm run format`) all
clean.
