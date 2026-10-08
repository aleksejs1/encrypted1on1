// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import {
  inlineMarkdownToPlainText,
  renderAnswerMarkdown,
  renderInlineMarkdown,
  sanitizeAnswerHtml,
} from './markdown';

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

  it('renders an h1 as h2 — page-title size is reserved for page titles', () => {
    expect(renderAnswerMarkdown('# Big heading')).toBe(
      '<h2>Big heading</h2>\n',
    );
    // Each heading stays its own block rather than running into the next.
    expect(renderAnswerMarkdown('# Done\n# Next')).toBe(
      '<h2>Done</h2>\n<h2>Next</h2>\n',
    );
    expect(renderAnswerMarkdown('Summary\n=====')).toBe('<h2>Summary</h2>\n');
    expect(renderAnswerMarkdown('a\n\n#\n\nb')).toBe('<p>a</p>\n<p>b</p>\n');
    // Nothing visible: the content without the heading, never an empty heading.
    expect(renderAnswerMarkdown('a\n\n# \u200b\n\nb')).toBe(
      '<p>a</p>\n\u200b<p>b</p>\n',
    );
    expect(renderAnswerMarkdown('# ` `')).toBe('<code> </code>');
    // A heading of a link shown as its source keeps that text.
    expect(renderAnswerMarkdown('# [](https://example.com)')).toBe(
      '<h2>[](https://example.com)</h2>\n',
    );
    expect(renderAnswerMarkdown('[ ](foo)\n===')).toBe('<h2>[ ](foo)</h2>\n');
  });

  it('shows raw HTML as inert text: no tag, attribute or style survives', () => {
    // Raw HTML is escaped before it reaches the sanitizer, so a payload never becomes
    // an element (the allowlist stays as a second layer behind that).
    for (const source of [
      'before<script>alert(1)</script>after',
      '<img src="x" onerror="alert(1)">',
      '<div style="background-image:url(https://evil.example/pixel.gif)">text</div>',
      '<iframe src="https://evil.example"></iframe>',
      '<p class="x" data-foo="bar" aria-label="x" onclick="alert(1)">hi</p>',
    ]) {
      const container = document.createElement('div');
      container.innerHTML = renderAnswerMarkdown(source);
      expect(container.querySelectorAll('*').length, source).toBe(1);
      expect(container.querySelector('p')?.attributes.length, source).toBe(0);
      expect(container.textContent, source).toBe(`${source}\n`);
    }
  });

  it('keeps text after a raw-text tag mention, across paragraphs', () => {
    // DOMPurify drops a <style>/<script>/<textarea> and everything after it.
    expect(
      renderAnswerMarkdown(
        'Moved inline <style> tags into CSS.\n\nSecond paragraph',
      ),
    ).toBe(
      '<p>Moved inline &lt;style&gt; tags into CSS.</p>\n<p>Second paragraph</p>\n',
    );
    expect(renderAnswerMarkdown('We removed <script> tags and more')).toBe(
      '<p>We removed &lt;script&gt; tags and more</p>\n',
    );
    expect(renderAnswerMarkdown('use <textarea> for input, then')).toBe(
      '<p>use &lt;textarea&gt; for input, then</p>\n',
    );
    expect(renderAnswerMarkdown('Wrapped <code> around x<y & more')).toBe(
      '<p>Wrapped &lt;code&gt; around x&lt;y &amp; more</p>\n',
    );
  });

  it('keeps line breaks after a line that starts with a tag', () => {
    // As an HTML block, the <script> line would swallow the rest of the answer.
    expect(
      renderAnswerMarkdown('<script> line start\nsecond line\n\nthird'),
    ).toBe('<p>&lt;script&gt; line start<br>second line</p>\n<p>third</p>\n');
  });

  it('starts a new paragraph at a later line that starts with a tag', () => {
    // marked's paragraph rule; accepted, no text is lost.
    expect(renderAnswerMarkdown('first\n<div> second')).toBe(
      '<p>first</p>\n<p>&lt;div&gt; second</p>\n',
    );
  });

  it('shows typed inline HTML as source, inside Markdown structure too', () => {
    expect(renderAnswerMarkdown('a <strong>x</strong> <br class="y">')).toBe(
      '<p>a &lt;strong&gt;x&lt;/strong&gt; &lt;br class="y"&gt;</p>\n',
    );
    expect(renderAnswerMarkdown('- one **b**\n- two <i>')).toBe(
      '<ul>\n<li>one <strong>b</strong></li>\n<li>two &lt;i&gt;</li>\n</ul>\n',
    );
    expect(renderAnswerMarkdown('| a |\n|---|\n| <x> |')).toContain(
      '<td>&lt;x&gt;</td>',
    );
    expect(renderAnswerMarkdown('```\n<b>&amp;\n```')).toBe(
      '<pre><code>&lt;b&gt;&amp;amp;\n</code></pre>\n',
    );
  });

  it('shows entity-like text as typed', () => {
    expect(renderAnswerMarkdown('R&D notes&notes; &amp; a&#8203;b')).toBe(
      '<p>R&amp;D notes&amp;notes; &amp;amp; a&amp;#8203;b</p>\n',
    );
  });

  it('shows a Markdown image as its source text, never fetching it', () => {
    expect(renderAnswerMarkdown('![x](https://evil.example/p.gif)')).toBe(
      '<p>![x](https://evil.example/p.gif)</p>\n',
    );
  });

  it('strikes through only a double-tilde pair', () => {
    expect(renderAnswerMarkdown('~~gone~~ took ~2h~3h')).toBe(
      '<p><del>gone</del> took ~2h~3h</p>\n',
    );
  });

  it('shows a link that is not http(s) or mailto as its source', () => {
    for (const target of [
      '/account',
      '#top',
      'example.com',
      '//example.com',
      'javascript:alert(1)',
      'tel:1',
    ]) {
      expect(renderAnswerMarkdown(`[**x**](${target}) y`), target).toBe(
        `<p>[**x**](${target}) y</p>\n`,
      );
    }
    expect(renderAnswerMarkdown('[mail](mailto:a@example.com)')).toContain(
      '<a href="mailto:a@example.com"',
    );
  });

  it('shows a link with no visible text as its Markdown source', () => {
    for (const source of [
      '[](https://example.com)',
      '[ ](https://example.com)',
      '[\u200b](https://example.com)',
    ]) {
      expect(renderAnswerMarkdown(source), source).toBe(`<p>${source}</p>\n`);
    }
  });

  it('has an allowlist that drops payload tags and attributes on its own', () => {
    // The renderer escapes raw HTML before it gets here; this guards the second layer.
    const container = document.createElement('div');
    container.innerHTML = sanitizeAnswerHtml(
      '<script>alert(1)</script><img src="x" onerror="alert(1)">' +
        '<iframe src="https://evil.example"></iframe>' +
        '<p style="background:url(https://evil.example/p.gif)" class="x" data-foo="1" ' +
        'aria-label="x" onclick="alert(1)">hi</p><h1>t</h1>',
    );
    expect(container.innerHTML).toBe('<p>hi</p>t');
  });

  it('keeps the allowlist as a second layer for what the renderer emits', () => {
    // Raw HTML never reaches DOMPurify, but marked's own output does: a table's
    // align attribute is the part of it the allowlist removes (an h1 is covered by
    // the direct test above).
    expect(renderAnswerMarkdown('| a |\n|:--|\n| b |')).not.toContain('align');
    expect(renderAnswerMarkdown('| a |\n|:--|\n| b |')).toContain('<th>a</th>');
  });

  it('keeps bare URLs linked after a typed, unclosed <a> tag', () => {
    expect(
      renderAnswerMarkdown('Wrap it in <a href="#">\n\nSee https://z.com'),
    ).toContain('<a href="https://z.com"');
  });

  it('shows a line shaped like a link reference definition as typed', () => {
    expect(renderAnswerMarkdown('see [x][y]\n\n[y]: /account')).toBe(
      '<p>see [x][y]</p>\n<p>[y]: /account</p>\n',
    );
  });

  it('keeps a typed <br> as a line break, the only way to get one in a table cell', () => {
    expect(renderAnswerMarkdown('a<br>b<BR/>c')).toBe('<p>a<br>b<br>c</p>\n');
    expect(
      renderAnswerMarkdown('| Q3 |\n|---|\n| shipped<br>docs pending |'),
    ).toContain('<td>shipped<br>docs pending</td>');
    // A <br> shows nothing, so a link or heading of only that is blank.
    expect(renderAnswerMarkdown('[<br>](https://example.com)')).toBe(
      '<p>[&lt;br&gt;](https://example.com)</p>\n',
    );
    expect(renderAnswerMarkdown('[**<br/>**](https://example.com)')).toBe(
      '<p>[**&lt;br/&gt;**](https://example.com)</p>\n',
    );
    expect(renderAnswerMarkdown('a\n\n# <br>\n\nb')).toBe(
      '<p>a</p>\n<br><p>b</p>\n',
    );
    // List entries have no line breaks: there it shows as typed.
    expect(renderInlineMarkdown('a<br>b')).toBe('a&lt;br&gt;b');
  });

  it('keeps line breaks inside text shown as source', () => {
    expect(renderAnswerMarkdown('a <!--\nhidden\n-->\nb')).toBe(
      '<p>a &lt;!--<br>hidden<br>--&gt;<br>b</p>\n',
    );
    expect(renderAnswerMarkdown('[a\nb](/x) ![c\nd](y)')).toBe(
      '<p>[a<br>b](/x) ![c<br>d](y)</p>\n',
    );
  });

  it('links a label that is only an image, shown as its source', () => {
    expect(
      renderInlineMarkdown('[![](https://x.example/p.png)](https://e.example)'),
    ).toBe(
      '<a href="https://e.example" rel="noopener noreferrer" target="_blank">![](https://x.example/p.png)</a>',
    );
    expect(
      renderAnswerMarkdown('[![](https://x.example/p.png)](https://e.example)'),
    ).toBe(
      '<p><a href="https://e.example" rel="noopener noreferrer" target="_blank">![](https://x.example/p.png)</a></p>\n',
    );
  });

  it('links a label that only looks like an autolink, shown as text', () => {
    expect(renderAnswerMarkdown('[<xsl:template>](https://docs.example)')).toBe(
      '<p><a href="https://docs.example" rel="noopener noreferrer" target="_blank">&lt;xsl:template&gt;</a></p>\n',
    );
  });

  it('shows a link holding a link of its own as source', () => {
    expect(
      renderAnswerMarkdown('[<https://evil.example>](https://good.example)'),
    ).toBe('<p>[&lt;https://evil.example&gt;](https://good.example)</p>\n');
    expect(
      renderInlineMarkdown('[<https://evil.example>](https://good.example)'),
    ).toBe('[&lt;https://evil.example&gt;](https://good.example)');
  });

  it("keeps an ordered list's typed first number", () => {
    expect(renderAnswerMarkdown('3. third\n4. fourth')).toBe(
      '<ol start="3">\n<li>third</li>\n<li>fourth</li>\n</ol>\n',
    );
  });

  it('shows a task-list box as typed instead of dropping it', () => {
    expect(renderAnswerMarkdown('- [x] shipped\n- [ ] docs')).toBe(
      '<ul>\n<li>[x] shipped</li>\n<li>[ ] docs</li>\n</ul>\n',
    );
    expect(renderAnswerMarkdown('- [x] loose\n\n- [ ] two')).toContain(
      '<li><p>[x] loose</p>',
    );
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

  it('keeps bare URLs linked after a typed, unclosed <a> tag', () => {
    expect(
      renderInlineMarkdown('Wrap <a href="#"> then https://z.com'),
    ).toContain(' then <a href="https://z.com"');
  });

  it('strikes through only a double-tilde pair', () => {
    expect(renderInlineMarkdown('~~gone~~ ok')).toBe('<del>gone</del> ok');
    expect(renderInlineMarkdown('took ~2h~3h, not ~4h~')).toBe(
      'took ~2h~3h, not ~4h~',
    );
  });

  it('shows a link that is not http(s) or mailto as its source', () => {
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
        `[**x**](${target}) y`,
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
    // marked decodes numeric references in text tokens itself.
    expect(renderInlineMarkdown('a&#8203;b &#64; **&#x41;**')).toBe(
      'a&amp;#8203;b &amp;#64; <strong>&amp;#x41;</strong>',
    );
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

  it('never links a javascript: target', () => {
    expect(renderInlineMarkdown('[x](javascript:alert(1))')).toBe(
      '[x](javascript:alert(1))',
    );
  });
});

describe('inlineMarkdownToPlainText', () => {
  it('drops the Markdown syntax and a link target, keeping what is shown', () => {
    expect(
      inlineMarkdownToPlainText(
        'agree, see **point 2** and [the RFC](https://example.com/spec/long-path)',
      ),
    ).toBe('agree, see point 2 and the RFC');
    expect(inlineMarkdownToPlainText('`const x = 1` and ~~done~~')).toBe(
      'const x = 1 and done',
    );
    expect(inlineMarkdownToPlainText('[**bold** label](https://e.com)')).toBe(
      'bold label',
    );
  });

  it('keeps a bare URL, which is its own label', () => {
    expect(inlineMarkdownToPlainText('See https://example.com')).toBe(
      'See https://example.com',
    );
  });

  it('keeps text that is shown as typed', () => {
    for (const source of [
      'Non-web [doc](/wiki/page)',
      'R&D <script>alert(1)</script> notes&notes;',
      '![x](https://evil.example/p.gif) <b>y</b> &amp; a < b',
      '# not a heading',
      'plain text',
    ]) {
      expect(inlineMarkdownToPlainText(source), source).toBe(source);
    }
  });

  it('follows the rendered text for stored plain text read as Markdown', () => {
    expect(inlineMarkdownToPlainText('2*3*4')).toBe('234');
  });

  it('returns an empty string for a missing or empty text', () => {
    expect(inlineMarkdownToPlainText('')).toBe('');
    expect(inlineMarkdownToPlainText(null as unknown as string)).toBe('');
    expect(inlineMarkdownToPlainText(undefined as unknown as string)).toBe('');
  });

  it('matches the text content of the rendered HTML', () => {
    const container = document.createElement('div');
    for (const source of [
      'a **b** [c](https://example.com) `d` ~~e~~',
      'x <b>y</b> &copy; [z](/relative) https://example.com/?a=1&b=2',
    ]) {
      container.innerHTML = renderInlineMarkdown(source);
      expect(inlineMarkdownToPlainText(source), source).toBe(
        container.textContent,
      );
    }
  });
});
