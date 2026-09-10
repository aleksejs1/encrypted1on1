/**
 * Shared by generate-demo-fixture.mjs and generate-doc-screenshots.mjs.
 *
 * A generous timeout, not Playwright's 30s default, for every wait that
 * follows a client-side argon2id key derivation (account activation
 * completion, login) — this has occasionally taken noticeably longer than
 * 30s under real system load in this environment.
 */
export const ARGON2ID_REDIRECT_TIMEOUT = 90_000;
