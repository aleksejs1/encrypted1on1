# 4. Session-based auth, not JWT

## Status

Accepted.

## Context

The app needs to authenticate a browser SPA against a same-origin backend API (see [ADR 6](0006-single-frankenphp-container.md) — one FrankenPHP process serves both), for a login/logout/CSRF-protected request model. JWTs are a common default for SPA/API pairs, particularly across separate origins or when a stateless backend is a hard requirement — neither applies here.

## Decision

Authentication is a plain Symfony session, backed by an httpOnly, `SameSite=Strict` cookie, protected by CSRF tokens (`CsrfTokenController`, checked on every state-changing request). There is no JWT, no client-side token storage, and no token-refresh logic anywhere in the frontend.

## Consequences

- No client-side token storage means no XSS-driven token-theft surface for the auth session itself — the session cookie is httpOnly and never touched by JavaScript.
- No token-refresh state machine, no expiry-race handling, no "silent refresh" complexity in the frontend — the browser just carries the cookie automatically.
- This ties the app to being served same-origin (or with careful cross-origin cookie configuration) — acceptable, since [ADR 6](0006-single-frankenphp-container.md) already commits to a single same-origin deployable unit.
- Scaling to multiple stateless backend instances behind a load balancer would need shared session storage — not a current requirement for a single-instance, self-hosted deployment, and not designed around speculatively.
