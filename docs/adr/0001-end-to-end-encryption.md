# 1. End-to-end encryption model

## Status

Accepted.

## Context

encrypted1on1 stores the content of 1:1 meetings between a manager and an employee — feedback, goals, comments, meeting outcomes. This is exactly the kind of content whose value depends on the platform operator (self-hosting or not) being structurally unable to read it, not merely promising not to. A tool whose entire premise is trust around sensitive workplace conversations has to make that promise verifiable, not just stated.

## Decision

All anketa content (question answers, comments, outcomes, goal progress checkpoints) is encrypted and decrypted entirely in the browser. The server stores only ciphertext, sealed per-anketa symmetric keys, and public keys — it never has access to any private key or master key, and no code path exists on the backend capable of deriving plaintext from what it stores. Per-user identity keypairs (X25519) are generated client-side and the private key is stored only wrapped under a key derived from the user's password (argon2id → HKDF split); the wrapped private key travels through the server as opaque ciphertext.

The one deliberate, narrow exception: a `Goal`'s title, description, status, and target date are stored as plaintext server-side fields (not a ciphertext blob), so the server can enforce real ownership checks (`author.id === current user`) and support carry-forward queries across anketa cycles. Progress checkpoints on a goal stay fully encrypted. This exception does not extend to anything else without an explicit, separately-discussed product decision.

See [`docs/encryption.md`](../encryption.md) for the full key-derivation chain and threat model, and [`CLAUDE.md`](../../CLAUDE.md)'s "Non-negotiable constraints" section for how this constrains all future work.

## Consequences

- The server can never leak anketa content, even under full compromise (DB dump, RCE) — the worst case is a corrupted/deleted database or a metadata leak (who talks to whom, when), not content disclosure.
- No server-side search, full-text indexing, or content-based analytics over anketa content is possible, ever — any such feature would require client-side implementation or a separate, explicit weakening of this model.
- Losing a password without a fresh keypair genuinely loses access to old encrypted content sealed under the discarded key — this is why password reset (`docs/history.md`, "Password reset, Part 1/2") issues a fresh keypair and re-shares anketa keys via the counterpart, rather than "recovering" the old one, which is cryptographically impossible without escrowing keys server-side.
- Any new feature that touches anketa content starts from "this must be client-side encrypted" as the default assumption, not an opt-in.
