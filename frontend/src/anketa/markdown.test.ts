// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { renderAnswerMarkdown, renderInlineMarkdown } from './markdown';

describe('renderAnswerMarkdown', () => {
  it('renders plain text with no markdown syntax unchanged (backward-compat case)', () => {
    expect(renderAnswerMarkdown('Just a plain sentence.')).toBe(
      '<p>Just a plain sentence.</p>\n',
    );
  });

  it('preserves a single line break as a visible break (breaks:true regression guard)', () => {
    // Every already-stored answer relied on every line break surviving (the old plain
    // <textarea> + white-space: pre-wrap display kept them all) — a lone `\n` must not
    // collapse into a soft-wrap space under CommonMark's default behavior.
    const html = renderAnswerMarkdown('First line\nSecond line');
    expect(html).toContain('<br>');
    expect(html).toContain('First line');
    expect(html).toContain('Second line');
  });

  it('renders bold, italic, links, lists and headings to their expected tags', () => {
    expect(renderAnswerMarkdown('**bold**')).toContain('<strong>bold</strong>');
    expect(renderAnswerMarkdown('*italic*')).toContain('<em>italic</em>');
    expect(renderAnswerMarkdown('[text](https://example.com)')).toBe(
      '<p><a href="https://example.com" rel="noopener noreferrer" target="_blank">text</a></p>\n',
    );
    expect(renderAnswerMarkdown('- one\n- two')).toContain('<li>one</li>');
    expect(renderAnswerMarkdown('## Heading')).toContain('<h2>Heading</h2>');
  });

  it('strips h1 down to unwrapped text — reserved for page titles, not answer content', () => {
    const html = renderAnswerMarkdown('# Big heading');
    expect(html).not.toContain('<h1');
    expect(html).toContain('Big heading');
  });

  it('strips a raw <script> tag', () => {
    const html = renderAnswerMarkdown('before<script>alert(1)</script>after');
    expect(html).not.toContain('<script');
    expect(html).not.toContain('alert(1)');
  });

  it('strips an <img> tag entirely, even with an onerror handler', () => {
    const html = renderAnswerMarkdown('<img src="x" onerror="alert(1)">');
    expect(html).not.toContain('<img');
    expect(html).not.toContain('onerror');
  });

  it('strips a style attribute that would trigger an unsolicited network fetch via CSS', () => {
    // The gap an img-only denylist would have missed: a `style` attribute survives
    // DOMPurify's own defaults unless explicitly excluded from ALLOWED_ATTR.
    const html = renderAnswerMarkdown(
      '<div style="background-image:url(https://evil.example/pixel.gif)">text</div>',
    );
    expect(html).not.toContain('style=');
    expect(html).not.toContain('evil.example');
    expect(html).toContain('text');
  });

  it('strips a disallowed tag (e.g. iframe) and a disallowed attribute (e.g. class)', () => {
    const html = renderAnswerMarkdown(
      '<iframe src="https://evil.example"></iframe>',
    );
    expect(html).not.toContain('<iframe');
    expect(renderAnswerMarkdown('<p class="x">text</p>')).not.toContain(
      'class=',
    );
  });

  it('strips data-* and aria-* attributes, which survive DOMPurify defaults regardless of ALLOWED_ATTR', () => {
    const html = renderAnswerMarkdown(
      '<p data-foo="bar" aria-label="x" onclick="alert(1)">hi</p>',
    );
    expect(html).not.toContain('data-foo');
    expect(html).not.toContain('aria-label');
    expect(html).not.toContain('onclick');
  });

  it('forces rel and target on every link regardless of markdown source', () => {
    const html = renderAnswerMarkdown('[click me](https://example.com)');
    expect(html).toContain('rel="noopener noreferrer"');
    expect(html).toContain('target="_blank"');
  });

  it('does not reformat a 4-space-indented line into a code block', () => {
    // A pre-existing plain-text answer with a line indented for visual structure (common in
    // pasted outline/notes text) must not suddenly become a bordered monospace block — no
    // toolbar button produces indentation, so this can only be pre-existing/pasted content.
    const html = renderAnswerMarkdown('    indented note');
    expect(html).not.toContain('<pre');
    expect(html).not.toContain('<code');
    expect(html).toContain('indented note');
  });

  it('still renders a fenced code block when typed deliberately', () => {
    const html = renderAnswerMarkdown('```\nfenced code\n```');
    expect(html).toContain('<pre><code>fenced code');
  });

  it('renders empty or whitespace-only input to nothing meaningful', () => {
    expect(renderAnswerMarkdown('')).toBe('');
    expect(renderAnswerMarkdown('   ')).toBe('');
  });
});

describe('renderInlineMarkdown', () => {
  it('renders plain text unchanged, with no wrapping <p>', () => {
    expect(renderInlineMarkdown('Shipped the release')).toBe(
      'Shipped the release',
    );
  });

  it('renders bold, italic, code, links and strikethrough', () => {
    expect(renderInlineMarkdown('**bold** *italic* `code` ~~gone~~')).toBe(
      '<strong>bold</strong> <em>italic</em> <code>code</code> <del>gone</del>',
    );
    expect(renderInlineMarkdown('[RFC](https://example.com)')).toBe(
      '<a href="https://example.com" rel="noopener noreferrer" target="_blank">RFC</a>',
    );
  });

  it('forces rel and target on an autolinked bare URL too', () => {
    const html = renderInlineMarkdown('see https://example.com');
    expect(html).toContain('href="https://example.com"');
    expect(html).toContain('rel="noopener noreferrer"');
    expect(html).toContain('target="_blank"');
  });

  it('leaves block syntax as literal text', () => {
    expect(renderInlineMarkdown('# not a heading')).toBe('# not a heading');
    expect(renderInlineMarkdown('- not a list')).toBe('- not a list');
    expect(renderInlineMarkdown('> not a quote')).toBe('&gt; not a quote');
    expect(renderInlineMarkdown('    not code')).toBe('    not code');
  });

  it('escapes literal angle brackets and ampersands', () => {
    expect(renderInlineMarkdown('a < b & c')).toBe('a &lt; b &amp; c');
  });

  it('shows tag-like text as typed, including raw-text tags mid-sentence', () => {
    // DOMPurify drops a <style>/<script> and everything after it, so raw HTML
    // must be escaped before it ever reaches the sanitizer.
    expect(renderInlineMarkdown('Moved inline <style> tags into CSS')).toBe(
      'Moved inline &lt;style&gt; tags into CSS',
    );
    expect(renderInlineMarkdown('Dropped <script> blocking, 2x')).toBe(
      'Dropped &lt;script&gt; blocking, 2x',
    );
    expect(renderInlineMarkdown('x <b>y</b> <!-- z -->')).toBe(
      'x &lt;b&gt;y&lt;/b&gt; &lt;!-- z --&gt;',
    );
  });

  it('escapes text after a <code>, <kbd>, <pre> or <script> mention too', () => {
    // marked treats these as the start of a raw block and flags the text after
    // them as already escaped.
    expect(renderInlineMarkdown('Wrapped <code> around x<y and more')).toBe(
      'Wrapped &lt;code&gt; around x&lt;y and more',
    );
    expect(renderInlineMarkdown('Used <kbd> then <style/x> text after')).toBe(
      'Used &lt;kbd&gt; then &lt;style/x&gt; text after',
    );
    expect(renderInlineMarkdown('Used <pre> then <a/b>click')).toBe(
      'Used &lt;pre&gt; then &lt;a/b&gt;click',
    );
    expect(renderInlineMarkdown('Dropped <script> for a&b')).toBe(
      'Dropped &lt;script&gt; for a&amp;b',
    );
  });

  it('keeps a raw <img onerror> as inert text, never a tag', () => {
    const html = renderInlineMarkdown('<img src="x" onerror="alert(1)">');
    expect(html).not.toContain('<img');
    expect(html).toContain('&lt;img src=');
    expect(html).toContain('onerror=');
  });

  it('shows a Markdown image as its source text, never fetching it', () => {
    expect(renderInlineMarkdown('![x](https://evil.example/p.gif)')).toBe(
      '![x](https://evil.example/p.gif)',
    );
    const linked = renderInlineMarkdown(
      '[![x](https://evil.example/p.gif)](https://example.com)',
    );
    expect(linked).not.toContain('<img');
    expect(linked).toContain('href="https://example.com"');
  });

  it('strikes through only a double-tilde pair', () => {
    expect(renderInlineMarkdown('~~gone~~ ok')).toBe('<del>gone</del> ok');
    expect(renderInlineMarkdown('took ~2h~3h, not ~4h~')).toBe(
      'took ~2h~3h, not ~4h~',
    );
  });

  it('turns a link that is not http(s) or mailto into its text', () => {
    for (const target of [
      '/account',
      '#top',
      'https:/account',
      '',
      'data:text/html,hi',
      'javascript:alert(1)',
      'tel:123',
      '//example.com',
    ]) {
      expect(renderInlineMarkdown(`[**x**](${target}) y`), target).toBe(
        '<strong>x</strong> y',
      );
    }
  });

  it('keeps angle-bracketed text that marked reads as a non-web link', () => {
    expect(
      renderInlineMarkdown(
        'Fixed <xsl:template> in <std::vector>, <JIRA:ABC-1>',
      ),
    ).toBe(
      'Fixed &lt;xsl:template&gt; in &lt;std::vector&gt;, &lt;JIRA:ABC-1&gt;',
    );
    expect(renderInlineMarkdown('<https://example.com>')).toContain(
      '<a href="https://example.com"',
    );
  });

  it('keeps web and mail links, with the shared rel/target', () => {
    expect(renderInlineMarkdown('[mail](MAILTO:a@example.com)')).toBe(
      '<a href="MAILTO:a@example.com" rel="noopener noreferrer" target="_blank">mail</a>',
    );
    expect(renderInlineMarkdown('mail me@example.com')).toBe(
      'mail <a href="mailto:me@example.com" rel="noopener noreferrer" target="_blank">me@example.com</a>',
    );
  });

  it('links to the target as typed, entities included', () => {
    // Every & is escaped, so the browser follows the same string a bare
    // URL's label shows rather than decoding an entity into another host.
    expect(
      renderInlineMarkdown('[x](https://example.com/?a=1&amp;b=2&c=3)'),
    ).toContain('href="https://example.com/?a=1&amp;amp;b=2&amp;c=3"');
    const container = document.createElement('div');
    container.innerHTML = renderInlineMarkdown(
      'see https://evil.example&#64;localhost/account',
    );
    const link = container.querySelector('a');
    expect(link).not.toBeNull();
    expect(link?.textContent).toBe(link?.getAttribute('href'));
  });

  it('shows entity-like text as typed, everywhere', () => {
    // marked's own escaping would keep these for the browser to decode, and
    // the browser decodes a legacy entity even without its semicolon.
    expect(renderInlineMarkdown('R&D notes&notes; tips&copyedit; &amp;')).toBe(
      'R&amp;D notes&amp;notes; tips&amp;copyedit; &amp;amp;',
    );
    expect(
      renderInlineMarkdown('`a&amp;b` [x&amp;y](https://example.com)'),
    ).toBe(
      '<code>a&amp;amp;b</code> <a href="https://example.com" rel="noopener noreferrer" target="_blank">x&amp;amp;y</a>',
    );
    expect(
      renderInlineMarkdown('![Tom &amp; Jerry](x) <b title="&amp;">'),
    ).toBe('![Tom &amp;amp; Jerry](x) &lt;b title=&quot;&amp;amp;&quot;&gt;');
  });

  it('shows a link with no visible text as its Markdown source', () => {
    for (const source of [
      '[](https://example.com)',
      '[ ](https://example.com)',
      '[\u200b](https://example.com)',
      '[**\u200b**](https://example.com)',
      '[` `](https://example.com)',
      '[\u034f](https://example.com)',
      '[\ufe0f](https://example.com)',
      '[\u3164](https://example.com)',
      '[](mailto:me@example.com?subject=hi)',
      '[](https://example.com/a%2Fb%E2%80%AE)',
    ]) {
      expect(renderInlineMarkdown(`${source} tail`), source).toBe(
        `${source} tail`,
      );
    }
    // Even when the sanitizer would have removed the target.
    expect(renderInlineMarkdown('[](javascript:alert(1)) tail')).toBe(
      '[](javascript:alert(1)) tail',
    );
    expect(renderInlineMarkdown('[](tel:+371) call')).toBe('[](tel:+371) call');
  });

  it('keeps a link whose label is only an entity-like or escaped character', () => {
    expect(renderInlineMarkdown('[&#8203;](https://example.com)')).toBe(
      '<a href="https://example.com" rel="noopener noreferrer" target="_blank">&amp;#8203;</a>',
    );
    expect(renderInlineMarkdown('[<](https://example.com)')).toContain(
      '>&lt;</a>',
    );
  });

  it('shows a non-string entry as text instead of throwing', () => {
    // Decrypted answers aren't shape-checked.
    expect(renderInlineMarkdown(42 as unknown as string)).toBe('42');
    expect(renderInlineMarkdown(null as unknown as string)).toBe('');
    expect(renderInlineMarkdown(undefined as unknown as string)).toBe('');
  });

  it('never runs words together across a line break', () => {
    expect(renderInlineMarkdown('a\nb')).toBe('a\nb');
    expect(renderInlineMarkdown('a  \nb')).toBe('a b');
    expect(renderInlineMarkdown('a\\\nb')).toBe('a b');
  });

  it('drops a javascript: link target', () => {
    const html = renderInlineMarkdown('[x](javascript:alert(1))');
    expect(html).not.toContain('javascript:');
    expect(html).toContain('x');
  });
});
