# 5. No message queue or background worker

## Status

Accepted.

## Context

Two features need work done outside the request/response cycle: daily meeting-reminder emails and periodic database backups. The conventional Symfony answer is a Messenger worker process consuming a queue. This app's deployment (`docker/prod/docker-compose*.yml`) has no persistent worker process anywhere, and both jobs are naturally once-a-day batch operations, not something needing sub-second dispatch.

## Decision

Both jobs are plain one-shot commands, triggered by an external cron entry on the host, not a Symfony Scheduler/Messenger consumer running inside the app: `bin/console app:send-reminders` (meeting reminders) and `docker/prod/backup.sh` (database backups). Neither is invoked by any code path inside the app itself — `docs/deployment.md` documents the exact crontab lines an operator needs to add.

## Consequences

- No worker process to keep alive, monitor, or restart on crash — one fewer moving part in the deployment.
- No message broker (Redis, RabbitMQ) dependency for what is, in practice, two calls a day.
- Idempotency has to be handled explicitly per job instead of relying on queue semantics — e.g. `Anketa::reminderSentAt` makes `app:send-reminders` safe against a same-day cron rerun.
- This only scales to "once or twice daily batch work." A future feature genuinely needing near-real-time background processing (not on the roadmap today) would need to revisit this decision, not extend the cron pattern to it.
- Operators must remember to actually add the cron entries — this is a real, documented operational responsibility, not something the app enforces or checks.
