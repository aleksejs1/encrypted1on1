# 3. SQLite as the default database

## Status

Accepted.

## Context

encrypted1on1 is designed to be self-hosted, single-tenant — one instance per organization, not a multi-tenant SaaS. The server only ever stores ciphertext and metadata (see [ADR 1](0001-end-to-end-encryption.md)), so the usual reasons to reach for a heavier database (complex queries, high write concurrency, multi-service access) mostly don't apply at the scale this app targets.

## Decision

SQLite is the default and only database anyone needs to operate to run this app — no separate database server/container to provision, back up, or keep patched. Backups are a single-file `sqlite3 .backup` snapshot (`docker/prod/backup.sh`); the whole persistent state of an instance is one file plus the sealed-key/ciphertext columns in it.

MySQL is supported as a deliberately second-class escape hatch for operators who need it (existing infrastructure, specific operational requirements) — see [`docs/deployment.md`](../deployment.md#using-mysql-instead-of-sqlite). Because the historical `migrations/` directory is hand-written SQL targeting SQLite's own dialect and isn't portable, MySQL gets its own standalone bootstrap migration (`backend/migrations-mysql/`, generated fresh from Doctrine's entity mappings against a real MySQL database) and its own migration command — an ongoing, explicitly-accepted dual-migration maintenance cost for any future schema change, not a one-time setup fee.

## Consequences

- Zero-dependency self-hosting: `docker compose up` with no database container to configure, size, or secure separately.
- Backup/restore is trivially simple and auditable — one file, one command — rather than a `mysqldump`/replication story.
- Write concurrency is bounded by SQLite's own model; acceptable for this app's actual workload (a handful of participants per anketa, infrequent writes), not chosen for high-concurrency scale.
- Any schema-changing feature now costs two migrations to keep MySQL genuinely supported, not one — `docs/deployment.md` states this cost plainly rather than letting the MySQL path silently rot.
- `docker/prod/backup.sh`/`restore.sh` stay SQLite-specific; a MySQL operator brings their own backup strategy.
