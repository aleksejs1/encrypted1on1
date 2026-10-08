import { Marked, type MarkedExtension } from 'marked';
import DOMPurify from 'dompurify';

/**
 * The overrides both renderers share. Stored answers and entries are years of plain text written
 * before they were read as Markdown, so all text shows exactly as typed apart from the Markdown
 * syntax itself (a fenced code block's info string is a known exception, see
 * `docs/decisions/2026-09-10-anketa-answer-markdown-formatting.md`):
 * - raw HTML and images render back as escaped source text rather than being left for DOMPurify
 *   to remove (free text keeps a typed `<br>`, see below). Every other allowed tag has Markdown
 *   syntax of its own, and DOMPurify drops a mention
 *   like "moved inline <style> tags" together with all the text after it;
 * - text escapes every `&`, `<` and `>` itself. marked's own escaping keeps anything shaped like
 *   an entity, which the browser then decodes (`&notes;` would show as "¬es;"), and skips text
 *   after a typed `<code>`, `<kbd>`, `<pre>` or `<script>`, which it takes for a raw block. A
 *   text token's `raw` is used, not its `text`, where marked has already decoded numeric
 *   references (`&#8203;` would be an invisible character). A block-level text token (a tight
 *   list item's text) carries inline tokens of its own, rendered as usual;
 * - a typed tag is only text, so it never changes how the rest is read. marked's own `tag`
 *   tokenizer tracks an opened `<a …>` and turns off bare-URL links until a `</a>`, and tracks
 *   `<code>`/`<kbd>`/`<pre>`/`<script>` as a raw block; this one matches the same tag and records
 *   nothing;
 * - a task-list `[ ]`/`[x]` shows as typed rather than as a checkbox the allowlist would strip,
 *   which would lose which items the author marked done;
 * - strikethrough needs `~~x~~`: GFM also accepts `~x~`, which would strike through an estimate
 *   like "~2h~3h" (returning `false` hands a `~~` run back to the built-in tokenizer);
 * - a link shows its Markdown source, instead of being an invisible, unnamed tab stop, when its
 *   label renders blank (only whitespace, zero-width characters, a `<br>` or a blank code span)
 *   or renders a link of its own (marked splits an autolink out of it, leaving the outer link
 *   empty). Both are judged on the rendered label, so they follow the other overrides. It also
 *   shows its source when its target isn't `http(s)://` or `mailto:`: a relative target would
 *   otherwise open an app page or GET endpoint, signed in, from text the counterpart wrote, and
 *   the source keeps a mistyped target like `example.com` visible, and `<xsl:template>` or
 *   `<std::vector>`, which marked reads as an autolink, as typed. A link's title is dropped (a
 *   known exception, like a fenced code block's info string).
 *
 * `source` escapes what is shown as source (a tag, an image, a link, a task-list box), which can
 * span lines. Source shows as typed throughout, a `<br>` inside it included.
 */
function showAsTyped(source: (raw: string) => string): MarkedExtension {
  return {
    gfm: true,
    renderer: {
      // `false` falls back to marked's own rendering of a block-level text token's inline tokens.
      text: (token) =>
        'tokens' in token && token.tokens
          ? false
          : escapeAll(token.type === 'text' ? token.raw : token.text),
      html: ({ text }) => source(text),
      image: ({ raw }) => source(raw),
      checkbox: ({ raw }) => source(raw),
      link(token) {
        const label = this.parser.parseInline(token.tokens);
        if (
          isBlank(label) ||
          /<a\s/.test(label) ||
          !/^(?:https?:\/\/|mailto:)/i.test(token.href)
        ) {
          return source(token.raw);
        }
        return `<a href="${escapeAll(token.href)}">${label}</a>`;
      },
    },
    tokenizer: {
      del: (src) => (src.startsWith('~~') ? false : undefined),
      tag(src) {
        const match = this.rules.inline.tag.exec(src);
        return match
          ? {
              type: 'html',
              raw: match[0],
              text: match[0],
              block: false,
              inLink: false,
              inRawBlock: false,
            }
          : undefined;
      },
    },
  };
}

/** For text and the href attribute alike. */
function escapeAll(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * Whether rendered HTML shows nothing: only tags (a `<br>`, an empty `<code>`), whitespace or
 * zero-width characters. Judged on the renderers' own output, so it follows what they show. In
 * that output text never holds a raw `<` and an attribute never a raw `>`, so stripping tags with
 * a regex is exact there, and every entity left stands for a visible character.
 */
function isBlank(html: string): boolean {
  return !html
    .replace(/<[^>]*>/g, '')
    .replace(/[\s\p{Default_Ignorable_Code_Point}]/gu, '');
}

/**
 * The free-text instance: the shared overrides above, plus the free-text-only rules below, and a
 * line break inside text shown as source kept as a `<br>` like any other.
 *
 * `breaks: true` (GFM line-break extension, a lone `\n` becomes `<br>`) is required, not a style
 * choice: every anketa free-text answer already stored today relies on every line break the
 * author typed being preserved (the previous plain-`<textarea>`+`white-space: pre-wrap` display
 * kept them all). CommonMark's default treats a lone `\n` inside a paragraph as a soft break
 * (rendered as a space), which would visibly flatten every multi-line answer already stored the
 * first time this renders it as Markdown. A local `Marked` instance (rather than the global
 * `marked.setOptions()` singleton) keeps this module's config isolated from anything else that
 * might import `marked`.
 *
 * CommonMark's 4-space-indented-code-block rule is disabled: a freeform answer that happens to
 * have a line indented for visual structure (common in pasted outline/notes text) would otherwise
 * reformat into a bordered monospace block on first render — a much more jarring, surprising
 * change than the heading/list-marker coincidence already accepted below, and with no toolbar
 * button that produces indentation for a user to have intentionally reached for. Fenced code
 * blocks (` ``` `) are a distinct tokenizer rule and are unaffected — still reachable by anyone
 * who types them deliberately.
 *
 * The block-HTML rule is disabled too, so a line starting with a tag is an ordinary paragraph
 * whose tags the shared `html` override escapes inline. As a block, a line starting with
 * `<script>` or `<style>` would run to the matching end tag or the end of the answer, and its
 * line breaks would be lost. Such a line still ends the paragraph before it (marked's paragraph
 * rule), so the line break before it shows as a paragraph gap.
 *
 * The link-reference-definition rule is disabled as well: a line like `[1]: https://example.com`
 * would otherwise render as nothing.
 *
 * A `# heading` renders as an `h2`: see the `h1` note on the allowlist below. DOMPurify would
 * unwrap an `h1` to bare text outside any block, running consecutive headings together. `#` and
 * `##` then look the same; shifting every level down instead would change how every stored `##`
 * answer looks. A heading with nothing visible (a bare `#` line, a `<br>`, a blank code span)
 * renders its content without the heading, as DOMPurify's unwrapping of an `h1` did, rather than
 * an empty, unnamed heading.
 *
 * A typed `<br>` stays a line break, the one raw tag kept: inside a GFM table cell it is the only
 * way to break a line, and stored answers use it there.
 */
const renderer = new Marked(
  { breaks: true },
  showAsTyped((raw) => escapeAll(raw).replace(/\n/g, '<br>')),
  {
    tokenizer: {
      code: () => undefined,
      html: () => undefined,
      def: () => undefined,
    },
    renderer: {
      heading({ tokens, depth }) {
        const text = this.parser.parseInline(tokens);
        const level = Math.max(depth, 2);
        return isBlank(text) ? text : `<h${level}>${text}</h${level}>\n`;
      },
      // `false` hands any other tag to the shared override, which shows it as source.
      html: ({ text }) => (/^<br\s*\/?>$/i.test(text) ? '<br>' : false),
    },
  },
);

/**
 * A DOMPurify instance scoped to this module, not the default-export global singleton — so the
 * `afterSanitizeAttributes` hook below only ever affects `renderAnswerMarkdown`'s and
 * `renderInlineMarkdown`'s own output, never any unrelated future `dompurify` import elsewhere in
 * the app that wouldn't expect its sanitized links to be silently rewritten.
 */
const purifier = DOMPurify(window);

/**
 * Forces every rendered link to open in a new tab without granting it access to
 * `window.opener` — fixed values this code adds itself after sanitization, not attributes
 * parsed out of the (attacker-controlled, from the reader's perspective) markdown source.
 * `afterSanitizeAttributes` runs after DOMPurify's own attribute allowlist filtering, so these
 * survive rather than being stripped by it.
 */
purifier.addHook('afterSanitizeAttributes', (node) => {
  if (node.tagName === 'A') {
    node.setAttribute('rel', 'noopener noreferrer');
    node.setAttribute('target', '_blank');
  }
});

/**
 * A second layer behind the renderers, which already escape raw HTML and images themselves.
 *
 * An explicit tag/attribute allowlist, not a denylist. Markdown image syntax (or a raw
 * inline-HTML `<img>`/`<div style="background-image:...">`) pointing at a remote URL would make
 * the reader's browser fetch that URL the instant a published anketa is opened, with no click
 * required — an unsolicited third-party network call of exactly the kind this app otherwise
 * avoids (see `docs/adr/0007`), and one that leaks the reader's IP/read-timing to whoever
 * controls the URL. Blocking only `<img>` isn't sufficient: DOMPurify's *default* attribute
 * allowlist still permits `style` on any tag, so `<div style="background-image:url(...)">`
 * (typed directly as inline HTML inside the markdown source) would survive an `img`-only
 * denylist untouched and trigger the same fetch via CSS instead. Passing an explicit
 * `ALLOWED_ATTR` replaces DOMPurify's default attribute allowlist rather than extending it, so
 * with `href` and `start` the only entries, no tag — allowed or not — can retain `style`,
 * `class`, or anything else covered by that list. `data-*`/`aria-*` attributes are a separate
 * DOMPurify allowance (`ALLOW_DATA_ATTR`/`ALLOW_ARIA_ATTR`, on by default regardless of
 * `ALLOWED_ATTR`) and are turned off explicitly below — nothing in this feature's own markup
 * ever needs either, so there's no reason to leave the door open for attacker-controlled markdown
 * source to attach arbitrary `data-*` values to elements a future script elsewhere in the app
 * might trust.
 *
 * `h1` is deliberately excluded (the free-text renderer turns a `# heading` into an `h2`, so this
 * is a second layer): `components.css` reserves the page `<h1>`'s large display-font treatment
 * for top-level page titles specifically, "never for dense, sensitive content" (its own comment)
 * — an anketa answer is exactly that dense/sensitive content, so it shouldn't be able to render
 * at page-title size and weight.
 *
 * `start` keeps an ordered list's typed first number (`3. third` would otherwise show as "1.").
 * The renderers escape raw HTML, so only marked's own `<ol start="3">` can carry it, though the
 * allowlist itself permits it on any tag.
 */
const SANITIZE_CONFIG = {
  ALLOWED_TAGS: [
    'p',
    'strong',
    'em',
    'del',
    'a',
    'ul',
    'ol',
    'li',
    'code',
    'pre',
    'blockquote',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    'br',
    'hr',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
  ],
  ALLOWED_ATTR: ['href', 'start'],
  ALLOW_DATA_ATTR: false,
  ALLOW_ARIA_ATTR: false,
};

/**
 * Renders a `field.type === 'text'` anketa answer's Markdown source to sanitized, safe-to-inject
 * HTML. Pure function (no Svelte/component dependency) — used identically by the readonly display
 * and the editor's own live preview, so an author always sees exactly what their counterpart will
 * see. See the constants above for why `breaks: true`, the shared overrides and the allowlist
 * are all load-bearing, not incidental choices.
 */
export function renderAnswerMarkdown(source: string): string {
  return sanitizeAnswerHtml(renderer.parse(source, { async: false }) as string);
}

/**
 * The free-text sanitizer on its own, exported only so tests can guard the second layer directly.
 * Not a way to render anything: on raw HTML it drops a `<style>`/`<script>` mention together with
 * all the text after it. Render answers with `renderAnswerMarkdown()`.
 */
export function sanitizeAnswerHtml(html: string): string {
  return purifier.sanitize(html, SANITIZE_CONFIG);
}

/**
 * The inline instance (list entries, topics, comments): the shared overrides, with a hard line break (two trailing spaces or
 * a backslash before a newline) as a space, so the words on either side never run together. No
 * `breaks: true`: a lone `\n` stays a soft break.
 */
const inlineRenderer = new Marked(showAsTyped(escapeAll), {
  renderer: { br: () => ' ' },
});

/**
 * The free-text allowlist cut down to phrasing content, a second layer behind the renderer above:
 * `parseInline()` never emits block tags, so the output is always valid inside a `<span>` or
 * `<li>`.
 */
const INLINE_SANITIZE_CONFIG = {
  ...SANITIZE_CONFIG,
  ALLOWED_TAGS: ['strong', 'em', 'a', 'code', 'del'],
  ALLOWED_ATTR: ['href'],
};

/**
 * Inline Markdown as unsanitized HTML: bold, italic, code, links and strikethrough, with no
 * wrapping `<p>`. Block syntax (`# `, `- `, `> `) stays literal text, since `parseInline()` never
 * runs the block tokenizer.
 */
function parseInlineMarkdown(source: string): string {
  // Decrypted content isn't shape-checked, and marked throws on a non-string, which would take
  // the whole page down with it. Stringified the way `{entry.text}` showed it before.
  const text = String((source as unknown) ?? '');
  return inlineRenderer.parseInline(text, { async: false }) as string;
}

/**
 * Renders one line of user-typed text (a list entry, a shared topic, a comment) as inline
 * Markdown, to sanitized HTML.
 */
export function renderInlineMarkdown(source: string): string {
  return purifier.sanitize(parseInlineMarkdown(source), INLINE_SANITIZE_CONFIG);
}

/**
 * The text a reader sees for `renderInlineMarkdown(source)`, for a screen-reader announcement:
 * no asterisks or brackets, and a link's label without its URL. DOMPurify hands back the
 * sanitized nodes themselves, so this adds no place of its own that parses a string as HTML.
 */
export function inlineMarkdownToPlainText(source: string): string {
  const fragment = purifier.sanitize(parseInlineMarkdown(source), {
    ...INLINE_SANITIZE_CONFIG,
    RETURN_DOM_FRAGMENT: true,
  });
  return fragment.textContent ?? '';
}
