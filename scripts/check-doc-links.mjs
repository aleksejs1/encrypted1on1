#!/usr/bin/env node
// Checks that every relative markdown link (and its #anchor, if any) in this
// repo's own docs actually resolves to a real file/heading — the machine
// equivalent of the "every ADR cross-reference verified to resolve" manual
// check docs/history.md already describes doing by hand at least once. No
// dependency: plain fs/path, same "boring custom script" precedent as
// backend/bin/check-coverage.php and frontend/scripts/inject-sri.mjs.
//
// Scope: this repo's own hand-written docs (root *.md + docs/**/*.md) — not
// backend/frontend source comments, not private/ (gitignored, not part of
// the public repo), not vendored/node_modules content.

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function findMarkdownFiles(dir) {
  const results = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      results.push(...findMarkdownFiles(full));
    } else if (entry.endsWith('.md')) {
      results.push(full);
    }
  }
  return results;
}

const targets = [join(repoRoot, 'README.md'), join(repoRoot, 'CLAUDE.md'), ...findMarkdownFiles(join(repoRoot, 'docs'))].filter(
  existsSync,
);

function slugify(heading) {
  // GitHub's own heading-to-anchor algorithm (github-slugger): lowercase,
  // strip anything that isn't a letter/number/space/hyphen, then convert
  // each space to a hyphen individually — NOT a collapsing `\s+` → `-`.
  // Punctuation removed from between two spaces (e.g. an em dash) leaves
  // adjacent spaces behind, which is why real GitHub anchors sometimes have
  // doubled hyphens (see docs/user-flow.md's link to methodology.md's
  // "Outcomes vs. goals — tactical vs. strategic" heading) — collapsing
  // would silently "fix" a link that already correctly matches GitHub.
  return heading
    .trim()
    .toLowerCase()
    .replace(/[^\w\s-]/g, '')
    .replace(/ /g, '-');
}

function headingSlugsOf(filePath) {
  const text = readFileSync(filePath, 'utf8');
  const slugs = new Set();
  const seen = new Map();
  for (const line of text.split('\n')) {
    const match = /^#{1,6}\s+(.+)$/.exec(line);
    if (!match) continue;
    let slug = slugify(match[1]);
    // GitHub disambiguates repeated headings with a -1, -2, ... suffix.
    const count = seen.get(slug) ?? 0;
    seen.set(slug, count + 1);
    if (count > 0) slug = `${slug}-${count}`;
    slugs.add(slug);
  }
  return slugs;
}

// Strips fenced code blocks (```...```) so example markdown/code inside
// them is never mistaken for a real link to check.
function stripCodeFences(text) {
  return text.replace(/```[\s\S]*?```/g, (block) => block.replace(/[^\n]/g, ' '));
}

const linkPattern = /\[([^\]]*)\]\(([^)]+)\)/g;
const errors = [];

for (const file of targets) {
  const text = stripCodeFences(readFileSync(file, 'utf8'));
  const lines = text.split('\n');
  let match;
  while ((match = linkPattern.exec(text)) !== null) {
    const target = match[2].trim();
    if (/^(https?:|mailto:)/.test(target)) continue; // external — not this script's concern
    if (target.startsWith('<') || target === '') continue; // not a real path (rare malformed match)

    const lineNo = text.slice(0, match.index).split('\n').length;
    const [rawPath, anchor] = target.split('#');

    let resolvedFile = file;
    if (rawPath !== '') {
      resolvedFile = resolve(dirname(file), rawPath);
      if (!existsSync(resolvedFile)) {
        errors.push(`${relative(repoRoot, file)}:${lines[lineNo - 1] ? lineNo : lineNo}: broken link target "${target}" — no such file: ${relative(repoRoot, resolvedFile)}`);
        continue;
      }
    }

    if (anchor && resolvedFile.endsWith('.md')) {
      const slugs = headingSlugsOf(resolvedFile);
      if (!slugs.has(anchor)) {
        errors.push(`${relative(repoRoot, file)}:${lineNo}: broken anchor "#${anchor}" in link "${target}" — no matching heading in ${relative(repoRoot, resolvedFile)}`);
      }
    }
  }
}

if (errors.length > 0) {
  console.error(`Found ${errors.length} broken internal doc link(s):\n`);
  for (const err of errors) console.error(`  ${err}`);
  process.exit(1);
}

console.log(`Checked ${targets.length} markdown files — all internal links resolve.`);
