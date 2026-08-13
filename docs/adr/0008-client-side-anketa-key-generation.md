# 8. Client-side-only anketa-key generation

## Status

Accepted.

## Context

When a periodic anketa cycle is archived, the next anketa in the cycle is auto-created with a fresh symmetric key, sealed to both participants' public keys (see [ADR 1](0001-end-to-end-encryption.md)/`docs/encryption.md`). Generating that key server-side, even just for the moment it takes to seal and discard it, would be operationally more convenient — the server already has both participants' public keys on hand and could do the whole thing in one request with no client round-trip.

## Decision

Auto-recreation is never server-side. The key for the next anketa is generated in the *archiving client's own browser* — the same browser that's already there triggering the archive — and the sealed keys for both participants are sent to the server as part of the `POST /api/anketas/{id}/archive` request body. `GET /api/anketas/{id}` exposes `counterpartPublicKey` (not secret — costs nothing to expose) specifically so the client has what it needs to do this itself.

## Consequences

- The server never holds a plaintext anketa key at any point in its lifecycle, not even transiently during generation — a stronger, more literal reading of the end-to-end promise than "the server never *stores* plaintext," which a transient-generation shortcut would still technically satisfy.
- Auto-recreation depends on the archiving client actually running the extra client-side crypto step before the archive request completes — a genuinely more complex request than a bare "mark archived" call would be, accepted as the cost of the stronger guarantee above.
- If a participant is blocked, the server independently refuses to persist the client-submitted next anketa regardless of what was sent (`isBlocked()` checked server-side in `archive()`) — the server can veto, but it can never generate on its own behalf.
- Any future feature that might want the server to *initiate* anketa creation on its own schedule (rather than reacting to a client-submitted archive) would need this decision revisited explicitly, not quietly worked around.
