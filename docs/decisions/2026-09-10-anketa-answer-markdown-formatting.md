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
entries (a different, add/edit-in-place UI shape; added later, see the next section), comment
threads, the goal title/description/status plaintext exception. See
`private/anketa-markdown-formatting-proposal.md` (not tracked in git — local working document) for
the full library-selection rationale and phasing (Phase 2, pasted-rich-text-to-Markdown conversion
via `node-html-markdown`, deferred).

## Extension: inline Markdown in list entries (2026-09-30)

[GitHub issue #165](https://github.com/aleksejs1/encrypted1on1/issues/165): `field.type === 'list'`
entries (Achievements, Growth, What else to discuss, and any list question in a company template)
now render inline Markdown: bold, italic, `code`, links and `~~strikethrough~~`, in the entry rows
of `AnswerField.svelte` and the Achievements/Growth lists of `Report.svelte` (both through
`InlineMarkdown.svelte`, the one `{@html}` site). The input stays a single-line `<input>` with no
toolbar, and Edit shows the raw source.

`renderInlineMarkdown()` in `markdown.ts` runs `marked`'s `parseInline()` on its own `Marked`
instance, then the shared DOMPurify instance and hook, with the allowlist cut down to `strong`,
`em`, `a`, `code` and `del`. Entries are years of plain-text log lines, so that instance's job is
to show text exactly as typed apart from deliberate Markdown syntax. Review found the defaults
losing or changing text in several ways, and each override below fixes one:

- raw HTML and images render as escaped source text: DOMPurify would drop a mention like "moved
  inline `<style>` tags" together with everything after it, and an image would be removed;
- text escapes every `&`, `<` and `>` itself: `marked` keeps anything shaped like an entity, which
  the browser decodes (`R&D notes&notes;` would show "¬es;"), and leaves text after a typed
  `<code>`/`<kbd>`/`<pre>`/`<script>` unescaped;
- a hard line break becomes a space, so words never run together, and there is no `breaks: true`;
- strikethrough needs `~~x~~`: GFM's single-tilde form would strike through "~2h~3h";
- a link whose label is blank (whitespace or zero-width characters only) shows its Markdown source
  rather than an invisible, unnamed tab stop, and a link that isn't `http(s)://` or `mailto:` shows
  just its label, or its source when typed in angle brackets (`marked` reads `<xsl:template>` or
  `<std::vector>` as a link). A link target escapes every `&` too, so the browser follows the same
  string a bare URL's label shows.

Links deliberately follow free text's rules otherwise: a new tab for every link, and no check of
where an `http(s)://` link points. Several review rounds added host checks, mail-address checks and
Unicode-class heuristics, each then bypassed one case at a time; they were removed again in favour
of a rule short enough to audit, since the counterpart can already write any link text they like.

`parseInline()` never runs the block tokenizer, so an entry starting with `#`, `-`, `>` or
indentation keeps that text. What does change for stored entries, with the stored text untouched
and still shown by Edit:

- emphasis pairs format, including code-like text never meant as Markdown: `__init__` shows a bold
  "init" and `2*3*4` an italic "3" (`snake_case_name` is safe, GFM ignores intraword underscores);
- a backslash before punctuation disappears (`\_temp` shows as `_temp`);
- a bare URL, `www.` host or email address becomes a link;
- a relative or non-web link (`[wiki](/wiki)`, `tel:`) shows as plain text, and an entity in a link
  target stays literal, where a free-text answer's link would decode it;
- a label of a character that draws blank without being zero-width (U+2800, say) or of a lone
  `.`, or two links typed with nothing between them, can make a link that is hard to see or reads
  as one word;
- after a typed `<a` tag with attributes, a bare URL later in the entry isn't linked until a typed
  `</a>` (`marked` still tracks the tag as an open link).

Accepted: an entry is usually a short sentence, these lose at most a character or two of display,
and backticks show code exactly. In the entry row, words wrap whole as before and only a link or
code span may break anywhere, so a long URL or backticked path never widens the row.

Not changed: meeting outcomes, comments and goal fields stay plain text, and free-text rendering is
untouched. Review found three free-text gaps while checking this change, left for a separate fix:
it lacks `del` (`~~x~~` shows as plain text), it passes raw HTML to DOMPurify (text after a
`<style>`/`<script>` mention is dropped), and it keeps relative and blank-label links.

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

List entries (#165): `markdown.test.ts` covers `renderInlineMarkdown()`: formatting, literal block
syntax, escaped raw HTML (including `<style>`/`<script>` mid-sentence and text after a
`<code>`-style tag), images, entity-like text, line breaks, strikethrough, non-string entries,
link targets (relative, `javascript:`/`data:`/`tel:`, entities) and blank labels (zero-width
characters, emphasis, a blank code span). The list-entry edit test in
`frontend/e2e/dual-actor-anketa.spec.ts` saves a `**bold**` entry, checks that it renders as
`<strong>`, that Edit reopens on the raw source, and that the manager's separate session decrypts
and renders the same bold text after publish. The entry rows (at 360px and desktop width) and the
Report page were also checked by screenshot on the e2e stack. Went through 15 `code-review` rounds;
the last found no bugs.
