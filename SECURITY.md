# Security Policy

encrypted1on1 is an end-to-end encrypted, self-hosted tool — see [docs/encryption.md](docs/encryption.md) for exactly what the server can and can't see, and [docs/architecture-invariants.md](docs/architecture-invariants.md) for the security invariants the codebase is expected to hold. A vulnerability that would let the server (or its operator) recover plaintext it isn't supposed to see is treated as critical.

## Reporting a vulnerability

Please report security vulnerabilities privately through GitHub's [private vulnerability reporting](https://github.com/aleksejs1/encrypted1on1/security/advisories/new) (this repo's Security tab → "Report a vulnerability"), rather than opening a public issue or PR — that keeps details out of public view until a fix is available.

This is a single-maintainer project with no fixed response-time SLA, but reports are read and triaged as soon as practical.

## Supported versions

Only the latest tagged release and `main` are supported — this project doesn't maintain older release branches. If you're running an older version, please upgrade before reporting, or note the version explicitly in your report.
