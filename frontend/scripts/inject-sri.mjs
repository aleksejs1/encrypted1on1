#!/usr/bin/env node
// Post-build step, chained after `vite build` (see package.json's "build" script).
// Vite has no built-in Subresource Integrity support and no plugin dependency
// was added for it — this is small and boring enough to write directly with
// Node's own fs/crypto, matching this project's preference for auditable
// custom code over a third-party bundler plugin of unclear maintenance (see
// CLAUDE.md's Phase 7b notes on turning down ESLint/Deptrac for the same
// reason). Generalizes to any number of <script type="module" src="...">/
// <link rel="stylesheet" href="..."> tags, in case the build ever splits
// into multiple chunks — today there's exactly one of each.
import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const distDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'dist');
const htmlPath = join(distDir, 'index.html');
let html = readFileSync(htmlPath, 'utf8');

function integrityFor(assetUrl) {
  const filePath = join(distDir, assetUrl.replace(/^\//, ''));
  const content = readFileSync(filePath);
  const digest = createHash('sha384').update(content).digest('base64');
  return `sha384-${digest}`;
}

function injectIntegrity(html, tagPattern, urlAttr) {
  return html.replace(tagPattern, (tag) => {
    if (tag.includes('integrity=')) return tag;
    const match = tag.match(new RegExp(`${urlAttr}="([^"]+)"`));
    if (!match) return tag;
    const integrity = integrityFor(match[1]);
    return tag.replace('>', ` integrity="${integrity}">`);
  });
}

html = injectIntegrity(html, /<script type="module"[^>]*><\/script>/g, 'src');
html = injectIntegrity(html, /<link rel="stylesheet"[^>]*>/g, 'href');

writeFileSync(htmlPath, html);
console.log('inject-sri: added integrity hashes to dist/index.html');
