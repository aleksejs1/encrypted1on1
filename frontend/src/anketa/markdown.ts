import { Marked } from 'marked';
import DOMPurify from 'dompurify';

/**
 * `breaks: true` (GFM line-break extension, a lone `\n` becomes `<br>`) is required, not a style
 * choice: every anketa free-text answer already stored today relies on every line break the
 * author typed being preserved (the previous plain-`<textarea>`+`white-space: pre-wrap` display
 * kept them all). CommonMark's default treats a lone `\n` inside a paragraph as a soft break
 * (rendered as a space), which would visibly flatten every multi-line answer already stored the
 * first time this renders it as Markdown. A local `Marked` instance (rather than the global
 * `marked.setOptions()` singleton) keeps this module's config isolated from anything else that
 * might import `marked`.
 */
const renderer = new Marked({ breaks: true, gfm: true });

/**
 * CommonMark's 4-space-indented-code-block rule is disabled: a freeform answer that happens to
 * have a line indented for visual structure (common in pasted outline/notes text) would otherwise
 * reformat into a bordered monospace block on first render — a much more jarring, surprising
 * change than the heading/list-marker coincidence already accepted below, and with no toolbar
 * button that produces indentation for a user to have intentionally reached for. Fenced code
 * blocks (` ``` `) are a distinct tokenizer rule and are unaffected — still reachable by anyone
 * who types them deliberately.
 */
renderer.use({ tokenizer: { code: () => undefined } });

/**
 * A DOMPurify instance scoped to this module, not the default-export global singleton — so the
 * `afterSanitizeAttributes` hook below only ever affects `renderAnswerMarkdown`'s own output,
 * never any unrelated future `dompurify` import elsewhere in the app that wouldn't expect its
 * sanitized links to be silently rewritten.
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
 * with `href` the only entry, no tag — allowed or not — can retain `style`, `class`, or anything
 * else covered by that list. `data-*`/`aria-*` attributes are a separate DOMPurify allowance
 * (`ALLOW_DATA_ATTR`/`ALLOW_ARIA_ATTR`, on by default regardless of `ALLOWED_ATTR`) and are
 * turned off explicitly below — nothing in this feature's own markup ever needs either, so there's
 * no reason to leave the door open for attacker-controlled markdown source to attach arbitrary
 * `data-*` values to elements a future script elsewhere in the app might trust.
 *
 * `h1` is deliberately excluded (a `# heading` in an answer degrades to `h2`-sized text, DOMPurify
 * unwraps the disallowed tag rather than dropping its text): `components.css` reserves the page
 * `<h1>`'s large display-font treatment for top-level page titles specifically, "never for dense,
 * sensitive content" (its own comment) — an anketa answer is exactly that dense/sensitive content,
 * so it shouldn't be able to render at page-title size and weight.
 */
const SANITIZE_CONFIG = {
  ALLOWED_TAGS: [
    'p',
    'strong',
    'em',
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
  ALLOWED_ATTR: ['href'],
  ALLOW_DATA_ATTR: false,
  ALLOW_ARIA_ATTR: false,
};

/**
 * Renders a `field.type === 'text'` anketa answer's Markdown source to sanitized, safe-to-inject
 * HTML. Pure function (no Svelte/component dependency) — used identically by the readonly display
 * and the editor's own live preview, so an author always sees exactly what their counterpart will
 * see. See the constants above for why `breaks: true` and the allowlist are both load-bearing,
 * not incidental choices.
 */
export function renderAnswerMarkdown(source: string): string {
  const html = renderer.parse(source, { async: false }) as string;
  return purifier.sanitize(html, SANITIZE_CONFIG);
}
