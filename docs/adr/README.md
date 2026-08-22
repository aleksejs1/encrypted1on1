# Architecture Decision Records

A short record of *why* — for the small number of decisions in this project that are genuinely foundational and hard to reverse. Each follows the standard [Nygard ADR template](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions): Context, Decision, Consequences. These aren't a substitute for [`docs/history.md`](../history.md), which documents the reasoning behind nearly every choice made in this codebase, phase by phase, in much greater detail — ADRs exist for the handful of decisions worth a stable, individually-linkable page of their own, not as a second copy of that log. For non-foundational decisions made after the `CLAUDE.md`/`history.md` split, see [`docs/decisions/`](../decisions/) instead.

1. [End-to-end encryption model](0001-end-to-end-encryption.md)
2. [Hand-composed Symfony, no `symfony/skeleton`](0002-hand-composed-symfony.md)
3. [SQLite as the default database](0003-sqlite-default-database.md)
4. [Session-based auth, not JWT](0004-session-based-auth.md)
5. [No message queue or background worker](0005-no-message-queue.md)
6. [Single FrankenPHP container for API and SPA](0006-single-frankenphp-container.md)
7. [No frontend component framework](0007-no-frontend-component-framework.md)
8. [Client-side-only anketa-key generation](0008-client-side-anketa-key-generation.md)
