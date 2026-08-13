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
- Write concurrency is bounded by SQLite's own model; acceptable for this app's actual workload (a handful of participants per anketa, infrequent writes), not chosen for high-concurrency scale. **Confirmed via a real load test, not just asserted** (`make load-test-sqlite`, `backend/src/Command/LoadTestSqliteCommand.php` — see `private/todo.md` item 26): SQLite runs in WAL mode with a 5s busy timeout (`App\Doctrine\SqliteConnectionMiddleware`, SQLite-only, a no-op for the MySQL path), not SQLite's rollback-journal default — WAL removes reader-blocks-writer/writer-blocks-reader contention; the busy timeout smooths over writer-vs-writer contention, since SQLite only ever allows one writer regardless of journal mode. At an artificially adversarial worst case — up to 200 concurrent processes hammering the *same* single row with the same version-guarded update `Anketa::saveComments()` already uses — **zero "database is locked" errors** at any tested level (5/20/50/100/200 concurrent writers); only at 100+ concurrent writers on that one hot row did a small fraction (0.05–0.15%) exhaust their optimistic-concurrency retry budget (10 attempts), an expected consequence of contention on a single row, not a database-level failure. Write latency stayed sub-2ms at p50 even at 200 concurrent writers; p95/max grew with concurrency as expected (queueing) but never approached the 5s busy-timeout ceiling. Real production writes are spread across many different anketas for different pairs, not one shared row, so this test's worst case is already far more contentious than any realistic scenario at this app's actual scale. Also confirmed for real that enabling WAL doesn't disturb `docker/prod/backup.sh`: built the real prod image, ran `sqlite3 .backup` (the exact command the script already uses) against a live WAL-mode database, and confirmed the resulting snapshot's contents match the source exactly — no change needed there.
- Any schema-changing feature now costs two migrations to keep MySQL genuinely supported, not one — `docs/deployment.md` states this cost plainly rather than letting the MySQL path silently rot.
- `docker/prod/backup.sh`/`restore.sh` stay SQLite-specific; a MySQL operator brings their own backup strategy.
