// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import { renderAnswerMarkdown } from './markdown';

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
