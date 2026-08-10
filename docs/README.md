# Documentation

Reader-facing documentation for encrypted1on1 — what the system does and how, as it stands today.

- **[encryption.md](encryption.md)** — the cryptographic methodology: key derivation, what's encrypted with what, and a threat model (what a full server compromise does and doesn't reveal).
- **[user-flow.md](user-flow.md)** — the same system from a user's perspective: getting an account, logging in, running a 1:1 cycle end to end, what happens if you forget your password.
- **[architecture.md](architecture.md)** — how the application itself is put together: tech stack, directory layout, request/session model, testing and CI.
- **[deployment.md](deployment.md)** — how to actually run it, in development and in either of the two production setups.

If you're doing active development on this codebase (not just reading about it), see [`CLAUDE.md`](../CLAUDE.md) at the repo root instead — that's development notes (why each part was built the way it was, phase by phase), not user-facing documentation, and is kept separate from these docs on purpose.
