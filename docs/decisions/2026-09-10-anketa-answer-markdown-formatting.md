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
  than page-title-sized display type; since #178 it renders as an `h2`, since unwrapped headings
  ran together on one line). Not mitigated for `h2`-level `#` or `-`/`1.` list markers:
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
  tokenizer rule (`code: () => undefined` in the free-text `Marked` instance in `markdown.ts`) while
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
  `<std::vector>` as a link). #178 changed this to the source in every case, see the next section.
  A link target escapes every `&` too, so the browser follows the same string a bare URL's label
  shows.

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
- a relative or non-web link (to `/wiki`, or `tel:`) shows as plain text (its source since #178),
  and an entity in a link target stays literal, where a free-text answer's link decoded it (until
  #178 made free text keep it literal too);
- a label of a character that draws blank without being zero-width (U+2800, say) or of a lone
  `.`, or two links typed with nothing between them, can make a link that is hard to see or reads
  as one word;
- after a typed `<a` tag with attributes, a bare URL later in the entry wasn't linked until a typed
  `</a>` (`marked` tracked the tag as an open link); fixed by #178, see the next section.

Accepted: an entry is usually a short sentence, these lose at most a character or two of display,
and backticks show code exactly. In the entry row, words wrap whole as before and only a link or
code span may break anywhere, so a long URL or backticked path never widens the row.

Not changed: meeting outcomes, comments and goal fields stay plain text, and free-text rendering was
left untouched. Review found three free-text gaps while checking this change: it lacked `del`
(`~~x~~` showed as plain text), it passed raw HTML to DOMPurify (text after a `<style>`/`<script>`
mention was dropped), and it kept relative and blank-label links. They were fixed separately; see
the next section.

## Extension: free text follows the same rules (2026-10-01)

[GitHub issue #178](https://github.com/aleksejs1/encrypted1on1/issues/178): free-text answers now
use the same renderer overrides as list entries. `markdown.ts` builds them in one `showAsTyped()`
extension shared by both `Marked` instances, so the two pipelines can't drift apart again: inline
text renders the same way in both. The differences are block structure, which only free text has,
and line breaks, which free text keeps (`breaks: true`, now also inside text shown as source) and a
list entry turns into a space. The free-text instance also turns off indented code blocks (as
before), the block-HTML rule and the link-reference-definition rule, and keeps a typed `<br>`. Its
allowlist gains `del`, and `start` so an ordered list keeps its typed first number. DOMPurify and
the allowlist stay as a second layer: no other raw HTML reaches them any more, only `marked`'s own
output, from which they still strip a table's `align` attribute (and an `h1`, which the free-text
renderer now turns into an `h2` itself).

The block-HTML rule is off because, as a block, a line starting with `<script>` or `<style>` runs to
the matching end tag or the end of the answer, and its line breaks would be lost. With it off, the
line is an ordinary paragraph and its tags are escaped inline. The link-reference-definition rule
is off because a line like `[1]: https://example.com` rendered as nothing.

The shared overrides changed too, so list entries change slightly as well:

- a typed tag is matched by a tokenizer of our own that records nothing, so an unclosed `<a …>` no
  longer stops later bare URLs from linking;
- a link that isn't `http(s)://` or `mailto:` shows its whole Markdown source, not just its label.
  Free text has a link button that inserts `[link text](url)`, so a target typed without a scheme
  (`example.com`) or a forgotten placeholder would otherwise disappear from view;
- a link whose label holds a link of its own (`[<https://a.example>](https://b.example)`) shows its
  source: `marked` splits the inner autolink out, which left the outer link empty and unnamed.

The link checks (a blank label, a link inside the label) and the heading check look at the rendered
HTML, not at the tokens, so they follow what the overrides actually show: a label of an image or a
non-web link shows text and isn't blank, a `<br>` shows nothing and is, and a label like
`<xsl:template>` that `marked` reads as an autolink but the override shows as text is no nested
link. Text shown as source stays as typed throughout, so a `<br>` inside it shows as `<br>`. As
for list entries, a label of a character that draws blank without being zero-width (U+2800, say)
still makes a link that is hard to see.

A link shown as its source is plain text, so a long target (`[spec](/wiki/a/long/path)`) is one
unbroken word that can widen a list-entry row or a free-text table cell at phone width, as any long
plain word, like a pasted path, already does; only a real link or code span may break anywhere.
Left as is: `min-width: 0` on the entry text was tried and fixed the row (measured at 360px), but
it lets every long word break mid-word instead of moving the date and buttons below the text, which
is the rule above.

What changes for stored free-text answers, with the stored text untouched and shown as typed by the
editor:

- raw HTML shows as its source text. Text that DOMPurify used to cut off after a `<style>`,
  `<script>`, `<title>` or `<textarea>` mention now shows, and so does a `<strong>` or other tag
  typed on purpose, as source. That is the accepted cost: every other allowed tag has Markdown
  syntax, and no toolbar button produces HTML. A typed `<br>` (or `<br/>`) is the one exception and
  stays a line break: inside a GFM table cell, where a row is a single line, it is the only way to
  break one;
- entity-like text shows as typed (`R&D notes&notes;` no longer shows "¬es;"), and so does a numeric
  reference (`&#64;` stays `&#64;`), including in a code block, where it was already literal;
- `~~x~~` strikes through; `~x~` stays as typed;
- an image shows as its Markdown source. Before, DOMPurify removed it, alt text and all;
- a link that isn't `http(s)://` or `mailto:` shows its Markdown source (a link to `/account`
  opened that app page in a new tab, signed in), and so does a link whose label renders blank (only
  whitespace, zero-width characters, a typed `<br>` or a blank code span), rather than an
  invisible, unnamed tab stop. A link target keeps its entities literal;
- a task-list item keeps its typed `[ ]` or `[x]`; before, the allowlist stripped the checkbox, so
  done and not-done items looked the same;
- an ordered list keeps its first number (`3. third` showed as "1. third");
- a `# heading` renders as an `h2` (it was unwrapped to bare text, so `# Done` and `# Next` on two
  lines ran together as "Done Next"), so `#` and `##` now look the same. A heading with nothing
  visible (a bare `#` line, a `<br>`, a blank code span) renders its content without the heading,
  as the unwrapped `h1` did, rather than an empty, unnamed heading;
- a line shaped like a link reference definition shows as typed (its URL autolinked if it's a web
  link), and a reference link like `[x][y]` shows as its source;
- a line starting with an HTML tag that interrupts a paragraph in CommonMark (`</div>`, `<script>`,
  `<!--` and the like) still starts a new paragraph, so the line break before it shows as a
  paragraph gap. No text is lost.

The list-entry trade-offs above (emphasis in code-like text, a dropped backslash before
punctuation, bare URLs linked) already applied to free text since #55.

Known gaps, not changed (they predate #178): a link's title (`[x](https://… "title")`) is dropped,
and a fenced code block's info string disappears, since
`marked` turns it into a `class` the allowlist strips. A stored line like ```` ```Notes ```` loses
"Notes", and a plain-text separator like `~~~ thoughts ~~~` opens a code block that runs to the end
of the answer, losing that line. It is the same kind of coincidence as a line starting with `#`:
rare, and fenced blocks stay available to anyone who types them on purpose.

## Extension: inline Markdown in comments (2026-10-08)

[GitHub issue #241](https://github.com/aleksejs1/encrypted1on1/issues/241): a comment's text
renders as inline Markdown, through the same `InlineMarkdown.svelte` and `renderInlineMarkdown()`
as list entries and shared topics, with no rule of its own. Frontend and display only: the stored
text, the encrypted comments blob, the backend and the export are unchanged. The comment field
stays a single-line `<input>` with no toolbar, and Edit shows the Markdown source.

Comments already stored are read as Markdown from now on, with the list-entry trade-offs above
(emphasis in code-like text, a dropped backslash before punctuation, bare URLs linked).

The `aria-live` announcement of a comment arriving through the live-update poll used the raw text,
so it would read out asterisks, brackets and a link's URL. `inlineMarkdownToPlainText()` in
`markdown.ts` gives the text as shown instead. It takes the text content of the nodes DOMPurify
returns (`RETURN_DOM_FRAGMENT`) rather than assigning the rendered HTML to an element's
`innerHTML`: the result is the same, and the code gains no new place that parses a string as HTML.
The announcement follows the rendered text, so a stored `2*3*4` is announced as "234".

Layout: a long string with no spaces (a link shown as source, say) used to widen the comment's
row, and that was already so with the plain span. The comment's span now has
`overflow-wrap: anywhere`, as a shared topic does, so such a string breaks where it must. The
issue's other option, `min-width: 0` with `.inline-markdown`'s own `overflow-wrap: break-word`,
fixed an isolated row but not the page: with `break-word` the string still counts as one long word
for the minimum width, so every flex column above the row grew to fit it and the page scrolled
sideways (629px wide in a 360px viewport, measured on the e2e stack). An ordinary sentence wraps at
spaces as before. No `flex: 1`: it would move a short comment's Edit and Delete buttons to the
right edge.

Not changed: meeting outcomes and goal checkpoints, titles and descriptions, and private notes stay
plain text. Outcomes are not the same one-line swap: a done outcome is struck through, and so is
`~~text~~` once Markdown renders, which needs a decision of its own.

## Extension: emoji shortcodes (2026-10-08)

[GitHub issue #240](https://github.com/aleksejs1/encrypted1on1/issues/240): text pasted from Slack
or GitHub carries emoji as shortcodes (`:tada:`, `:+1:`). A known shortcode now shows as the emoji
character wherever Markdown renders: free-text answers and the editor's preview, list entries,
shared topics and comments. It is one function, `withEmoji()` in `markdown.ts`, called by the
text renderer both `Marked` instances already share (`showAsTyped()`), with no component change.

Display only. The stored text keeps the shortcode, so the encrypted payload, the backend and the
export are unchanged, and Edit reopens the text as typed. The emoji is a Unicode character, never
an image: an image set would be the unsolicited network fetch the allowlist above exists to
prevent. Plain-text fields (outcomes, goal fields, private notes) don't render Markdown and are
not covered.

Stored text is full of colons, so a shortcode renders only when it stands on its own:

- The name must be in the table, in lowercase. Slack and GitHub both write lowercase, so nothing
  from a paste is lost, and `Status :OK: done` stays as typed.
- The character before it must not be a letter, a digit, `_`, `:`, a backtick, `*` or `~`. The
  character after it must not be a letter, a digit or `_`, nor a colon that opens no shortcode.
  So `10:30:45`, `std::time::now`, `user:id:int`, `:id:int` and `:id::int` stay as typed. Other
  punctuation on either side doesn't block it (`a/:tada:/b`, `x=:tada:`).
- A shortcode directly after another one still renders (`:tada::tada:`), also when the first is
  unknown, such as a Slack workspace's own emoji (`:partyparrot::tada:` shows `:partyparrot:🎉`).
- Inline code and fenced code blocks keep the shortcode, and so do a link's target, a bare URL and
  text shown as source. `\:tada:` opts out through Markdown's own escape.

The table (`frontend/src/anketa/emojiShortcodes.ts`) is generated by
`frontend/scripts/generate-emoji-shortcodes.mjs` from the `gemoji` package: 1,913 GitHub names,
plus 58 Slack names that differ from GitHub's (`:thinking_face:`, `:man-shrugging:`,
`:star-struck:`), kept by hand in the script as `slack name: GitHub name` pairs. `gemoji` is a
devDependency (MIT), so nothing is added to what the app ships except the table itself, about
15 KB gzipped. CI runs the generator with `--check`, which fails if the committed file differs
from what the script writes. The table is a `Map`, so `:constructor:` finds nothing.

Decisions the issue left open:

- **Full table, not a hand-kept list of about 100.** A paste renders almost in full. The cost is a
  1,980-line file that is audited by re-running the generator, not by reading it. A test holds
  every value to emoji code points only.
- **Short and word-like names are kept** (`:x:`, `:a:`, `:id:`, `:ok:`, `:key:`, the country
  codes), as on GitHub. `Option A :x: Option B` shows a ❌. The checks on the neighbouring
  characters cover the cases that are not meant as emoji.
- **Skin tones are not supported**, and `:skin-tone-3:` is not consumed: `:wave::skin-tone-3:`
  shows as `👋:skin-tone-3:`. Appending a modifier is wrong for much of the set (it replaces a
  variation selector, and in a ZWJ sequence it belongs after the first person), and dropping it
  silently would lose what the author typed.

Known limits:

- Most of Slack's other names are not in the table: about 520 more differ from GitHub's, 261 of
  them the `flag-xx` country flags (GitHub's `:de:` form works). Add a pair to `SLACK_ALIASES`
  when one is asked for.
- Four names mean different emoji on the two (`satellite`, `umbrella`, `snowman`, `email`).
  GitHub's meaning is used.
- The rule reads one piece of text at a time (a `marked` text token), so it starts fresh after a
  code span, bold text, a link (a bare URL or address included), a typed tag or an escaped
  character, and inside a link's label: `` `done`:tada: ``, `**Done**:tada:` and
  `bob@example.com:x:` show the emoji. This differs from the issue, which also skips a
  shortcode after the backtick or `*` that closes code or emphasis. Its own examples
  (`` `user`:id:int ``, `**key**:id:x`) still stay as typed, through the check on the character
  after. That check stops at the same boundaries: `:id:*x*` and `:tada:www.example.com` show the
  emoji, though `:id:int` doesn't. See "Tried and replaced" below for why.
- A link's label is text like any other, so a URL typed as a label
  (`[https://example.com/:tada:](https://example.com)`) shows the emoji in it. A bare URL doesn't.
- A combining mark next to a shortcode blocks it like the letter it belongs to (words in many
  scripts end in one), except the variation selectors and the keycap mark that end many emoji, so
  `❤️:tada:` renders.
- The table is part of the main bundle, so every page load carries its 15 KB, not only pages that
  render Markdown. The app has no lazily loaded code, and none was added for this.

Tried and replaced: the issue proposed a `marked` inline extension, and the first version was one.
`marked` asks an extension where its next token may start (`start()`) once per token it produces
and cuts the text there. Reporting every colon stopped a bare `https://…` from linking; reporting
only known shortcodes fixed that but rescanned the rest of the text each time, and the check on
the preceding text had to be rebuilt from the tokens so far for every shortcode. A 75 KB answer
of `:name: ` took 3 to 4 seconds to render. Replacing shortcodes inside the text renderer needs
neither: the text is never cut, so everything around a shortcode tokenizes exactly as before, and
the same 75 KB renders in about 12 ms. `withEmoji()` is one left-to-right pass over the text; a
single regex with a lookbehind for the preceding run was tried first and was itself quadratic on
a long run of glued shortcodes. The price is the first limit above.

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
fetch, disallowed tags/attributes; since #178 these render as inert text before reaching the
sanitizer, see below). Verified end-to-end against the real dev stack with real
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

Free text (#178): `markdown.test.ts`'s `renderAnswerMarkdown()` tests were rewritten for the new
pipeline. The old sanitizer-bypass payloads (`<script>`, `<img onerror>`, a `style` attribute,
`class`/`data-*`/`aria-*`/`onclick`) now check that the output, parsed into a DOM, holds no element
or attribute beyond the paragraph and shows the payload as typed. New tests cover each case from
the issue (text after `<style>`/`<script>`/`<textarea>`/`<code>`, a line starting with `<script>`,
typed inline HTML inside lists, tables and fenced code, entity-like text, images, strikethrough,
non-web and blank-label links), an unclosed `<a>`, link reference definitions, task lists, and the
allowlist removing a table's `align`, plus line breaks inside text shown as source, a link inside a
link's label, and an ordered list's `start`. A separate test runs the old payloads straight through
the free-text sanitizer (`sanitizeAnswerHtml()`, exported for it), so the second layer stays
guarded on its own.

Comments (#241): `markdown.test.ts` covers `inlineMarkdownToPlainText()` (dropped syntax and link
targets, text shown as typed, a missing text, and agreement with the rendered HTML's text content).
In `frontend/e2e/dual-actor-anketa.spec.ts` the live-arrival test posts a comment with bold text
and a link, checks both on both sides and the announcement's text on the receiving side, and checks
at 360px that a long unbroken comment doesn't widen its thread (it fails with `min-width: 0`
alone); the comment-edit test checks that Edit
shows the Markdown source.

Emoji shortcodes (#240): `markdown.test.ts` runs every rule through both renderers (known and
unknown names, Slack names, lowercase only, each character that blocks a shortcode before or after
it, a known name after an unknown one or a literal emoji, code, the escape, link targets and bare
URLs, text shown as source, the fresh start after code and emphasis), plus links, escaping and
emphasis next to a shortcode, rendered or left as typed, and colon-heavy text with no shortcode at
all. It also holds every name in the table to the renderer's own name pattern, the only copy of
it. `emojiShortcodes.test.ts` checks the generated table: every value consists of emoji code
points only. In `frontend/e2e/dual-actor-anketa.spec.ts` the employee saves an answer ending in
`:tada:`, the manager's separate session shows 🎉, and Edit reopens on `:tada:`. The Slack names
were checked against Slack's own list (`emoji-datasource` 16.0.0), not from memory.
