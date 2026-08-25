# Fix dark-mode badge colors and add an automated WCAG contrast check

## Problem

Reported: in dark mode, the "archived"/"published" anketa badges
(`.tag-neutral`/`.tag-accent`/`.tag-accent-2`) didn't change color at all —
they kept rendering their light-theme cream/pale fills on a dark page.

Root cause: `tokens.css`'s dark-theme blocks (`:root[data-theme='dark']` and
the `prefers-color-scheme` media query) redeclared the plain `--color-bg`/
`--color-text`/`--color-accent`/etc. tokens for dark mode, but never the
tonal ramps (`--color-neutral-100..900`, `--color-accent-100..900`,
`--color-accent-2-100..900`) those badges actually read (`background:
var(--color-*-100)`, `color: var(--color-*-800)`). With no dark override,
those ramp variables fell through to `:root`'s light values unconditionally.

The user also asked to add automated contrast checking, generally — this
project's own history (`docs/history.md`'s "Two accent-contrast tokens
added..." entry) already did real numeric WCAG 2.1 AA verification once,
by hand, with a throwaway Python script, not as a standing, repeatable
check.

## Decision

**The badge fix**: added the missing tonal-ramp declarations to both dark
blocks in `tokens.css`, each ramp reversed step-for-step (100↔900, 200↔800,
300↔700, 400↔600, 500 unchanged) from its light-theme values — so "lightest
step = *-100" (used as a badge's background fill) and "step 800" (used as
its text) keep their relative roles in whichever theme is active, per the
file's own pre-existing "same step of any role carries the same visual
weight" comment.

**A regression the reversal itself introduced, caught by an independent
review**: dark theme's own hand-picked `--color-accent`/`--color-accent-2`
values happen to equal light theme's own `*-400` ramp step exactly. The
mechanical reversal therefore put that same value back at the `*-600` slot
— making `.btn-primary`'s resting and `:hover` backgrounds identical (hover
became invisible) in dark mode. Fixed by having `--color-accent-600/700`
and `--color-accent-2-600/700` reuse the already-distinct, already-reversed
`500`/`400` values instead of the mirrored `400`/`300` ones, documented
inline in `tokens.css` as the one deliberate exception to the mirror rule.

**The contrast checker**: `frontend/src/design/contrast.test.ts`, a Vitest
suite (runs as part of `npm test`, so it's part of the existing CI/pre-push
checks — no new script or npm command to remember to run). It reads the
*real* `tokens.css`/`components.css` files at test time (not a hand-copied
palette) and: computes real WCAG 2.1 relative-luminance/contrast-ratio math
for every solid foreground/background pair actually painted together
somewhere in the app (badges, links, buttons, banners), including
resolving this codebase's one "translucent color" pattern
(`color-mix(in srgb, X P%, transparent)`) by compositing it onto whichever
solid background it's actually rendered over; asserts `.btn-primary`'s
resting/hover/active fills are pairwise distinct (the exact shape of the
hover-collision regression above, which pure contrast-ratio checks can't
catch — two identical fills are equally "readable"); and asserts the
`[data-theme='dark']` block and the `prefers-color-scheme` media block
declare byte-identical tokens (this codebase's only real drift risk,
since there's no build-time theming system — the two blocks exist purely
because a browser can't apply a media-query fallback conditionally on an
*absent* attribute any other way, so they're maintained by hand in
parallel).

Running the checker for the first time surfaced two further, genuinely
pre-existing, unrelated bugs (not part of the reported dark-mode issue):
`.text-muted`/`.card-meta`/`.table th` used opacity percentages (55%/50%/
60%) that never reached 4.5:1 against this palette in light mode at all —
raised to 68% (matching the already-compliant `h4` rule) and de-duplicated
into one new `--color-text-muted` token in `tokens.css` (all four selectors
had been carrying the identical value as a literal, independently-editable
`color-mix(...)` — a second independent review flagged that duplication as
its own drift risk, since the token is what the test now actually reads).
`.banner-error`'s 14% mix reached 4.5:1 against `--color-bg` but not
`--color-surface` — and it's routinely rendered nested inside a `.card`
(surface background) on Login/Signup/AccountSettings/Activate/
ResetPassword/ForgotPassword/CreateCompany/Anketa. Lowered to 12%, which
clears both backgrounds in both themes.

## Alternatives considered

- **Only fix the reported badge bug, skip the general contrast checker.**
  Explicitly not what was asked — the user asked for both the fix and a
  standing automated check.
- **A separate CLI script (`scripts/check-contrast.mjs`) instead of a
  Vitest test.** Tried first; abandoned because it would need its own
  npm-script entry and a reminder to actually run it, whereas a Vitest test
  runs automatically as part of the existing `npm test` this project's own
  CI/pre-push already invoke.
- **A `?raw` Vite import for the CSS files instead of `node:fs`.** Tried
  first; resolves to an empty string under this project's standalone
  `vitest.config.ts` (no Vite/Svelte plugins wired into the Vitest config,
  unlike the app's own `vite.config.ts`), so reverted to `readFileSync` +
  `fileURLToPath` with a `/// <reference types="node" />` (scoped to this
  one file, not a `tsconfig.app.json`-wide change) rather than complicating
  the Vitest config for one test file.

## Verification

Confirmed the actual bug and the fix visually in a real browser (Playwright
against the real running dev stack, demo-mode login), not just numerically:
screenshotted the anketa list's "archived"/"published" badges in dark mode
before the fix (pale, light-theme colors, visually stranded on the dark
page) and after (properly dark-toned fills with light text) — a genuine
before/after comparison, done by temporarily `git stash`-ing just the two
CSS files, not by trusting the math alone. Separately confirmed live in a
browser that `.btn-primary`'s resting vs. `:hover` background in dark mode
are now genuinely different computed colors (`rgb(246,160,107)` vs.
`rgb(214,127,72)`), and that `.banner-error` nested inside a real `.card`
(a wrong-password error on the login page) reads clearly in light mode.

`contrast.test.ts` itself was verified to be a real regression guard, not a
tautology: reverting only `components.css`/`tokens.css` (keeping the new
test) makes 8 of its assertions genuinely fail; restoring the fix makes all
39 pass again.

Three independent review passes (forked reviewer agents with no
implementation stake) were run in sequence, each finding real, distinct
issues the previous pass had missed — the hover-collision regression, then
the banner-error/surface gap and the token duplication — until a pass
returned no findings. Full frontend suite (`npm test`: 154 tests),
`npm run check`, `npm run knip`, and `npm run format` all clean throughout.
