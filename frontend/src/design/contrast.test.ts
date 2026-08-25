/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Automatic WCAG 2.1 AA contrast check over the real palette in tokens.css
 * and the real component rules in components.css — added after two real
 * bugs this same check now guards against:
 *
 * 1. A dark-mode bug where .tag-accent/.tag-accent-2/.tag-neutral (the
 *    "archived"/"published" anketa badges) kept rendering light-theme
 *    colors in dark mode: tokens.css's dark blocks redeclared the plain
 *    --color-* tokens but never the tonal ramps (--color-neutral-100..900
 *    etc.) those components actually use. A pure "is this hex pair
 *    readable" check wouldn't have caught this on its own (the stale
 *    light-theme colors were still readable against each other, just
 *    visually wrong for the theme) — the "dark blocks match each other"
 *    check below is what actually would have caught the ramps being
 *    forgotten in one block but not the other.
 * 2. Three light-theme text colors (.text-muted, .card-meta, .table th)
 *    that never reached 4.5:1 against this palette's backgrounds at all —
 *    a genuine pre-existing contrast failure this check found the first
 *    time it ran, unrelated to (1). Fixed by raising their color-mix
 *    percentage to match the already-compliant h4 rule (68%).
 *
 * Reads both real CSS files (not a hand-copied palette or hardcoded
 * percentages) so a future edit to the actual values is what gets checked,
 * not a snapshot of them.
 */

function stripComments(css: string): string {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Finds `${selector} {...}` (the selector followed by its own opening brace, not a substring match inside some other selector) and returns the block's inner content. */
function extractBlock(css: string, selector: string): string {
  const needle = `${selector} {`;
  const start = css.indexOf(needle);
  if (start === -1) {
    throw new Error(`selector not found: ${needle}`);
  }
  const braceStart = start + needle.length - 1;
  let depth = 0;
  for (let i = braceStart; i < css.length; i++) {
    if (css[i] === '{') depth++;
    else if (css[i] === '}') {
      depth--;
      if (depth === 0) return css.slice(braceStart + 1, i);
    }
  }
  throw new Error(`unterminated block for selector: ${selector}`);
}

function parseCustomProperties(block: string): Record<string, string> {
  const props: Record<string, string> = {};
  for (const m of block.matchAll(/--([a-zA-Z0-9-]+):\s*([^;]+);/g)) {
    props[`--${m[1]}`] = m[2].trim();
  }
  return props;
}

/** Reads one declaration's raw value (e.g. `color`) out of a simple, non-nested selector's rule block. */
function extractDeclaration(
  css: string,
  selector: string,
  property: string,
): string {
  const block = extractBlock(css, selector);
  const match = new RegExp(`(?:^|;)\\s*${property}:\\s*([^;]+);`).exec(block);
  if (!match) {
    throw new Error(`"${selector}" has no "${property}" declaration`);
  }
  return match[1].trim();
}

function normalizeHex(hex: string): string {
  const h = hex.replace('#', '');
  const full =
    h.length === 3
      ? h
          .split('')
          .map((c) => c + c)
          .join('')
      : h;
  return `#${full.toLowerCase()}`;
}

function hexToRgb(hex: string): [number, number, number] {
  const n = normalizeHex(hex).slice(1);
  return [
    parseInt(n.slice(0, 2), 16),
    parseInt(n.slice(2, 4), 16),
    parseInt(n.slice(4, 6), 16),
  ];
}

/**
 * Resolves a raw CSS value to a solid hex color. `theme` is the
 * fully-merged (`:root` + that theme's overrides) raw custom-property map
 * for one theme, used to follow `var(--x)` references. `paintedOn` is the
 * solid hex background a `color-mix(in srgb, X P%, transparent)` value
 * (this codebase's only "translucent color" pattern) is actually rendered
 * over — required only for that case, since such a value has no color of
 * its own until composited onto something.
 */
function resolveToHex(
  raw: string,
  theme: Record<string, string>,
  paintedOn?: string,
): string {
  const varMatch = /^var\((--[a-zA-Z0-9-]+)\)$/.exec(raw);
  if (varMatch) {
    const resolved = theme[varMatch[1]];
    if (!resolved) throw new Error(`Unknown custom property: ${varMatch[1]}`);
    return resolveToHex(resolved, theme, paintedOn);
  }

  if (/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(raw)) return normalizeHex(raw);

  const mixMatch = /^color-mix\(in srgb, (.+?) (\d+)%, transparent\)$/.exec(
    raw,
  );
  if (mixMatch) {
    if (!paintedOn) {
      throw new Error(
        `"${raw}" has no color of its own — pass the solid background it's painted on`,
      );
    }
    const fgHex = resolveToHex(mixMatch[1].trim(), theme, paintedOn);
    const alpha = Number(mixMatch[2]) / 100;
    const [fr, fgc, fb] = hexToRgb(fgHex);
    const [br, bg, bb] = hexToRgb(paintedOn);
    const mix = (f: number, b: number) =>
      Math.round(f * alpha + b * (1 - alpha));
    return `#${[mix(fr, br), mix(fgc, bg), mix(fb, bb)]
      .map((c) => c.toString(16).padStart(2, '0'))
      .join('')}`;
  }

  throw new Error(`Don't know how to resolve to a solid color: "${raw}"`);
}

/** WCAG 2.1 relative luminance (0 = black, 1 = white). */
function relativeLuminance(hex: string): number {
  const [r, g, b] = hexToRgb(hex).map((c) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2.1 contrast ratio between two solid colors — always >= 1. */
function contrastRatio(hexA: string, hexB: string): number {
  const l1 = relativeLuminance(hexA);
  const l2 = relativeLuminance(hexB);
  const [lighter, darker] = l1 >= l2 ? [l1, l2] : [l2, l1];
  return (lighter + 0.05) / (darker + 0.05);
}

const tokensCss = stripComments(
  readFileSync(fileURLToPath(new URL('tokens.css', import.meta.url)), 'utf-8'),
);
const componentsCss = stripComments(
  readFileSync(
    fileURLToPath(new URL('components.css', import.meta.url)),
    'utf-8',
  ),
);

const lightRaw = parseCustomProperties(extractBlock(tokensCss, ':root'));
const darkAttrRaw = parseCustomProperties(
  extractBlock(tokensCss, ":root[data-theme='dark']"),
);
const darkMediaRaw = parseCustomProperties(
  extractBlock(
    tokensCss,
    ":root:not([data-theme='light']):not([data-theme='dark'])",
  ),
);

const THEMES = {
  light: lightRaw,
  dark: { ...lightRaw, ...darkAttrRaw },
};

interface Pair {
  name: string;
  fg: string;
  bg: string;
  /** WCAG 2.1: 4.5 for normal text, 3 for large text/UI components. */
  min: number;
}

/** Every solid (non color-mix) foreground/background token pair actually painted together somewhere in components.css/*.svelte. */
const SOLID_PAIRS: Pair[] = [
  {
    name: 'body text on page background',
    fg: '--color-text',
    bg: '--color-bg',
    min: 4.5,
  },
  {
    name: 'body text on card surface',
    fg: '--color-text',
    bg: '--color-surface',
    min: 4.5,
  },
  {
    name: 'links / .btn-ghost / .card-kicker / .tag-outline text on page background',
    fg: '--color-accent-ink',
    bg: '--color-bg',
    min: 4.5,
  },
  {
    name: 'links / .btn-ghost / .card-kicker / .tag-outline text on card surface',
    fg: '--color-accent-ink',
    bg: '--color-surface',
    min: 4.5,
  },
  {
    name: '.btn-primary / checked .seg-opt text on its accent fill',
    fg: '--color-on-accent',
    bg: '--color-accent',
    min: 4.5,
  },
  {
    name: '.tag-accent text on its fill (e.g. the "published" anketa badge)',
    fg: '--color-accent-800',
    bg: '--color-accent-100',
    min: 4.5,
  },
  {
    name: '.tag-accent-2 / .banner-success text on its fill',
    fg: '--color-accent-2-800',
    bg: '--color-accent-2-100',
    min: 4.5,
  },
  {
    name: '.tag-neutral text on its fill (e.g. the "archived" anketa badge)',
    fg: '--color-neutral-800',
    bg: '--color-neutral-100',
    min: 4.5,
  },
];

/** Selectors whose `color` resolves to the shared --color-text-muted token, checked against every solid background it can appear on. */
const MUTED_TEXT_SELECTORS = ['.text-muted', '.card-meta', 'h4', '.table th'];
const SURFACE_VARS = ['--color-bg', '--color-surface'];

describe('color token contrast (WCAG 2.1 AA)', () => {
  for (const [themeName, tokens] of Object.entries(THEMES)) {
    describe(`${themeName} theme`, () => {
      for (const pair of SOLID_PAIRS) {
        it(`${pair.name} reaches ${pair.min}:1`, () => {
          const fgHex = resolveToHex(tokens[pair.fg], tokens);
          const bgHex = resolveToHex(tokens[pair.bg], tokens);

          expect(contrastRatio(fgHex, bgHex)).toBeGreaterThanOrEqual(pair.min);
        });
      }

      for (const selector of MUTED_TEXT_SELECTORS) {
        const colorRaw = extractDeclaration(componentsCss, selector, 'color');

        for (const backgroundVar of SURFACE_VARS) {
          it(`${selector} text on ${backgroundVar} reaches 4.5:1`, () => {
            const bgHex = resolveToHex(tokens[backgroundVar], tokens);
            const fgHex = resolveToHex(colorRaw, tokens, bgHex);

            expect(contrastRatio(fgHex, bgHex)).toBeGreaterThanOrEqual(4.5);
          });
        }
      }

      for (const backgroundVar of SURFACE_VARS) {
        // .banner-error is routinely rendered nested inside a .card (e.g.
        // Login/Signup/AccountSettings/Activate/ResetPassword/ForgotPassword/
        // CreateCompany/Anketa), not just directly on the page — so its
        // translucent fill has to clear 4.5:1 composited onto
        // --color-surface too, not only --color-bg.
        it(`.banner-error text on its fill over ${backgroundVar} reaches 4.5:1`, () => {
          const underlyingBg = resolveToHex(tokens[backgroundVar], tokens);
          const bannerBgRaw = extractDeclaration(
            componentsCss,
            '.banner-error',
            'background',
          );
          const fgRaw = extractDeclaration(
            componentsCss,
            '.banner-error',
            'color',
          );
          const bannerBg = resolveToHex(bannerBgRaw, tokens, underlyingBg);
          const fgHex = resolveToHex(fgRaw, tokens);

          expect(contrastRatio(fgHex, bannerBg)).toBeGreaterThanOrEqual(4.5);
        });
      }

      it('.btn-primary resting/:hover/:active fills are pairwise distinct', () => {
        // A pure contrast-ratio check wouldn't catch two *identical* fills —
        // both are equally "readable" against whatever text sits on them.
        // This is exactly the shape of a real bug an independent review
        // caught: the dark-theme ramp reversal (see this file's tokens.css
        // comment) accidentally made --color-accent-600 equal --color-accent
        // itself, making :hover invisible against the resting fill.
        const resting = resolveToHex(
          extractDeclaration(componentsCss, '.btn-primary', 'background'),
          tokens,
        );
        const hover = resolveToHex(
          extractDeclaration(componentsCss, '.btn-primary:hover', 'background'),
          tokens,
        );
        const active = resolveToHex(
          extractDeclaration(
            componentsCss,
            '.btn-primary:active',
            'background',
          ),
          tokens,
        );

        expect(hover).not.toBe(resting);
        expect(active).not.toBe(resting);
        expect(active).not.toBe(hover);
      });
    });
  }

  it('the [data-theme="dark"] block and the prefers-color-scheme media block define identical tokens', () => {
    // Duplicated by hand (no build-time theming system here) since a browser
    // can't apply a media-query fallback conditionally on an *absent*
    // attribute any other way — this is what would have caught the two
    // blocks drifting apart, which the dark-mode badge bug's fix touched.
    expect(darkMediaRaw).toEqual(darkAttrRaw);
  });
});
