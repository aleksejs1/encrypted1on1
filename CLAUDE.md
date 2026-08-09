# CLAUDE.md

Guidance for Claude Code (and other AI assistants) working in this repository.

## Project

encrypted1on1 — a self-hosted, end-to-end encrypted platform for 1:1 meetings between managers and employees. See [README.md](README.md) for the overview.

## Current stage

Pre-implementation. No application code exists yet. The detailed spec currently lives outside this repo as a local working document (not tracked in git); an English version is planned to land here (likely `docs/SPEC.md`) as a separate task. Until that exists, treat the constraints below as authoritative, and ask before assuming anything not covered here.

## Non-negotiable constraints

- **End-to-end encryption is the entire point of this product.** The server must never be able to derive plaintext content from what it stores. When in doubt about whether something should be encrypted client-side, assume yes. The one deliberate, narrow exception is a goal's title/description/status/target date (not its progress checkpoints) — nothing else gets that exception without an explicit, discussed product decision.
- **Code must stay simple enough to audit.** This is a privacy tool; its credibility depends on a reasonably technical user being able to read the code and verify the privacy claims themselves. Prefer fewer files, fewer abstractions, and boring solutions over clever ones. Don't add speculative flexibility for requirements that don't exist yet.
- **Repository language is English, without exception** — code, comments, commit messages, docs, issue/PR templates. Working discussions with the maintainer may happen in other languages, but nothing non-English lands in this repo.
- **License is AGPLv3** — see [LICENSE](LICENSE). Don't introduce dependencies or vendored code under incompatible licenses without flagging it first.

## Working style

- This project explicitly favors dumb-and-simple over clever, and has a documented history of scope creep during planning — when a change grows noticeably beyond what was asked, say so before building it, rather than quietly expanding scope.
