# 7. No frontend component framework

## Status

Accepted.

## Context

The Phase 8 visual design system ("Organic") needed a consistent look across every page — buttons, cards, form fields, tags, tables. A common way to get this is a component library (Material, Bootstrap-for-Svelte, a headless UI kit) providing pre-built, themeable components.

## Decision

The design system is plain, global, unscoped CSS classes (`frontend/src/design/tokens.css` for colors/spacing/radius/shadow custom properties, `components.css` for `.btn`/`.field`/`.input`/`.card`/`.tag`/`.table` etc.) applied directly to plain HTML markup inside ordinary Svelte components — no component library dependency. Custom fonts are self-hosted (`frontend/public/fonts/`), not loaded from a CDN, for the same privacy reason the app avoids any other unnecessary third-party network call from the browser.

## Consequences

- No third-party UI library version, license, or supply-chain surface to track — every pixel of styling is source code sitting in this repository, directly auditable.
- No risk of a component library's own JS silently making network calls, loading remote fonts, or behaving in ways the rest of this app's privacy model would need to specifically re-verify.
- More manual work per new page/component to apply the right classes and keep them consistent — accepted, matching this project's general preference for boring, visible code over an abstraction that does more than asked.
- Accessibility, keyboard navigation, and cross-browser quirks that a mature component library would handle for free are this project's own responsibility to get right (see `docs/architecture.md`'s testing notes on manual `npm run dev` verification passes for UI correctness).
