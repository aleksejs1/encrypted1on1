# Documentation

Reader-facing documentation for encrypted1on1 — what the system does and how, as it stands today.

- **[encryption.md](encryption.md)** — the cryptographic design: key derivation, what's encrypted with what, and a threat model (what a full server compromise does and doesn't reveal).
- **[methodology.md](methodology.md)** — the 1:1 practice itself: why it matters, how a cycle is meant to be conducted, and what each question is actually asking.
- **[user-flow.md](user-flow.md)** — the system from a user's perspective: getting an account, logging in, running a 1:1 cycle end to end, what happens if you forget your password.
- **[architecture.md](architecture.md)** — how the application itself is put together: tech stack, directory layout, request/session model, testing and CI.
- **[deployment.md](deployment.md)** — how to actually run it, in development and in either of the two production setups.
- **[screenshots/](screenshots/)** — a visual tour, captioned.

If you're doing active development on this codebase (not just reading about it), see [`CLAUDE.md`](../CLAUDE.md) at the repo root instead — that's development notes (why each part was built the way it was, phase by phase), not user-facing documentation, and is kept separate from these docs on purpose.
