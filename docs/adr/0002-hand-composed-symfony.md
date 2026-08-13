# 2. Hand-composed Symfony, no `symfony/skeleton`

## Status

Accepted.

## Context

This is a privacy tool whose credibility depends on a reasonably technical user being able to read the code and verify its privacy claims themselves (see `CLAUDE.md`'s "Code must stay simple enough to audit" constraint). Symfony's usual starting point, `symfony/skeleton`, pulls in a large set of bundles, config surface, and directory conventions aimed at general-purpose web applications — most of which this app, a handful of REST-ish endpoints over a few entities, doesn't need.

## Decision

The backend is Symfony components composed by hand (`services.php`, explicit bundle registration), not `symfony/skeleton`. Every route beyond the one deliberately-generic case is a custom controller with real, specific logic — `AnketaController`, `AuthController`, `AdminController`, etc. — not a generic CRUD layer generated from entity metadata. The single exception is `User`, exposed read-only through API Platform (`GET /api/users`, needed for counterpart-picking; neither `isAdmin` nor `isBlocked` is sensitive, and `authHash`/`encryptedPrivateKey` carry no serialization group so they structurally cannot leak through it — enforced by `backend/tests/Architecture/SerializationBoundaryTest.php`).

## Consequences

- Fewer moving parts to audit: a reviewer can read `src/Controller/*.php` top to bottom and see exactly what each endpoint does, without tracing through a generic resource layer's configuration to find the actual behavior.
- Every new endpoint is a deliberate, visible piece of code — there's no risk of a framework auto-generating and exposing a route nobody meant to expose, the way a broadly-scoped API Platform setup could.
- The cost: some boilerplate (request parsing, JSON responses, ownership checks) is repeated per controller rather than abstracted away by a framework layer — accepted deliberately, since a small amount of repetition is easier to audit than an abstraction that hides what actually runs.
- Adding API Platform more broadly later (e.g. to a new entity) needs the same explicit case-by-case justification `User` got, not a default reach.
- **A real tension with the original product spec, not an oversight**: the spec's own reasoning for choosing API Platform in the first place was developer-convenience-focused — declarative filtering/pagination and, specifically, a bulk endpoint for fetching a pair's whole anketa history in one request instead of N+1. This decision keeps that convenience layer to one resource and reaches the same functional goal a different way (`AnketaController::bulk()`, a hand-written endpoint — see `private/todo.md` item 11) rather than reverting the choice. The two parts of the same spec point in different directions here: broader API Platform use for convenience, versus the spec's own separately-stated, higher-order constraint that the code stay simple enough for a privacy-conscious user to audit end to end. This ADR resolves that conflict in favor of auditability — a generic resource layer's declarative filters/providers/serialization groups are a real thing a reviewer has to learn to know what a given resource actually exposes, where a hand-written controller method is legible top to bottom without that extra layer of framework knowledge.
