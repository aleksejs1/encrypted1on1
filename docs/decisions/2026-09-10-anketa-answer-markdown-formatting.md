# Markdown formatting for anketa free-text answers

## Problem

Free-text anketa answers (`field.type === 'text'` in `frontend/src/anketa/questions.ts`) supported
no formatting — plain text in, plain text (with line breaks preserved via `white-space: pre-wrap`)
out. [GitHub issue #55](https://github.com/aleksejs1/encrypted1on1/issues/55) asked for basic
formatting (bold, lists, links, headings).

## Decision

`text`-type answers are now Markdown source, rendered via `marked` + `DOMPurify` (an explicit
tag/attribute allowlist, not a denylist — see `frontend/src/anketa/markdown.ts`'s own comments for
why an `img`-only denylist isn't sufficient). No backend/schema/encryption-boundary change: an
`AnswerValue` string's contents follow a new convention, not a new type or storage location.

## Accepted trade-off: existing plain-text answers are retroactively reinterpreted as Markdown

Every answer already stored before this shipped was authored as plain text, with no expectation
that any character in it carried special meaning. Treating that same string as Markdown source
from now on necessarily changes how a few pre-existing shapes of content display, with no
migration possible (the server never sees plaintext to migrate — encryption is client-side only,
`docs/adr/0001`). Each case below was found, and none was judged worth blocking on, for the reason
given:

- **A line starting with `#`, `-`, `*`, or `1.`** renders as a heading or list item instead of the
  literal character. Mitigated where cheaply possible (`h1` — the biggest, most visually jarring
  case — is excluded from the render allowlist entirely, degrading to plain unwrapped text rather
  than page-title-sized display type). Not mitigated for `h2`-level `#` or `-`/`1.` list markers:
  doing so would mean detecting "was this meant as Markdown" from the string alone, which isn't
  reliably possible. Judged rare (a line starting with a literal `#`/`-`/`*`/digit-period is
  uncommon in freeform prose) and low-severity (the output is still a readable, if
  differently-styled, line — not data loss).
- **Runs of repeated internal whitespace** (e.g. `"Score:   85 / 100"`, or a habitual double space
  after a sentence) visually collapse to a single space. This is standard, universal Markdown/HTML
  rendering behavior — every Markdown renderer (GitHub comments included) behaves identically,
  since normal (non-`pre`) HTML whitespace collapses multiple spaces on render regardless of what
  the source string contains. Not something `breaks: true` (which only preserves line breaks, a
  different whitespace category) fixes or was ever meant to fix. Accepted as an inherent property
  of moving from a raw-text display to a rendered one.
- **A 4-space-indented line** (CommonMark's indented-code-block rule) is the one case that *was*
  actively fixed rather than accepted: unlike the two cases above, it's a much more jarring visual
  change (a bordered monospace block appearing where plain text was), plausible in pasted
  outline/notes content, and has no counterpart affordance in the toolbar (no button produces
  indentation, so a user can't have "meant" it via this UI). Fixed by disabling that specific
  tokenizer rule (`renderer.use({ tokenizer: { code: () => undefined } })` in `markdown.ts`) while
  leaving fenced ` ``` ` code blocks — a deliberate, unambiguous choice — untouched.

- **Multiple consecutive blank lines** collapse to the same single paragraph gap as one blank
  line (verified: `"Para1\n\n\n\n\nPara2"` renders identically to `"Para1\n\nPara2"`). Previously
  preserved via `white-space: pre-wrap`; a pre-existing answer that used several blank lines for
  deliberate extra visual separation loses that distinction. Same category and same reasoning as
  the horizontal-whitespace-run case above — standard CommonMark paragraph-boundary behavior, not
  something `breaks: true` (line breaks *within* a paragraph, a different mechanism) touches.
  Accepted for the same reason: universal, inherent to block-structured rendering, not a defect
  specific to this implementation.

Every stored line break survives (`breaks: true`, load-bearing — see `markdown.ts`'s own comment);
that was judged the one case where "silently reformats already-typed content" would have been an
actual regression rather than an accepted consequence of adopting Markdown, since every existing
answer depended on it and nothing about typing plain text signals an *intent* to keep line breaks
the way a `#`/`-`/indentation coincidence would need signaling to distinguish accidental Markdown
syntax from intentional plain characters.

## Scope

In scope: `field.type === 'text'` answers only. Out of scope, deliberately: `field.type === 'list'`
entries (a different, add/edit-in-place UI shape), comment threads, the goal
title/description/status plaintext exception. See
`private/anketa-markdown-formatting-proposal.md` (not tracked in git — local working document) for
the full library-selection rationale and phasing (Phase 2, pasted-rich-text-to-Markdown conversion
via `node-html-markdown`, deferred).

## Alternatives considered

An in-browser editor library (EasyMDE/Tiptap) was rejected in favor of a hand-written toolbar —
consistent with `docs/adr/0007` (no third-party UI component dependency); only the genuinely
security-critical parsing/sanitizing step (`marked`+`DOMPurify`) uses off-the-shelf libraries. An
`img`-only sanitizer denylist was tried first and rejected once it became clear a `style` attribute
on an allowed tag reaches the same unsolicited-network-fetch outcome via CSS — see `markdown.ts`.

## Verification

`frontend/src/anketa/markdown.test.ts` and `markdownEditing.test.ts` (Vitest, one under a jsdom
pragma) cover backward-compat rendering, the `breaks: true`/indented-code-block regression guards,
and a battery of sanitizer-bypass payloads (`<script>`, `<img onerror>`, a `style`-attribute CSS
fetch, disallowed tags/attributes). Verified end-to-end against the real dev stack with real
crypto (not a placeholder): the demo employee account typed a Markdown answer, published it, and
the demo manager account — a fully independent session — correctly decrypted and rendered the
same sanitized HTML. Demo data reset afterward via `app:reset-demo-data`.
