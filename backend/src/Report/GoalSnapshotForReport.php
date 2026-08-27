<?php

namespace App\Report;

/**
 * One Goal row's plaintext snapshot data, as needed by OverviewAggregator —
 * same "plain DTO, no Doctrine" reasoning as AnketaSummaryForReport. Never
 * carries title/description (§2 of the reporting proposal: aggregate-only,
 * no goal text in any report response).
 *
 * `anketaMeetingDate` (not this row's own `createdAt`) is what "is this
 * goal in the requested date range" and "which month does it belong to"
 * are judged against — the same anketa-cycle-is-the-unit-of-time
 * convention the meetings side of the report already uses. A row's own
 * `createdAt` is only used to find a goalUuid's true first appearance
 * (see OverviewAggregator::countCreatedInRange()) — carry-forward
 * (AnketaController::createAnketaWithCarryForward()) gives every carried
 * row a *fresh* createdAt, so only the minimum across a goalUuid's whole
 * history is its real creation moment.
 *
 * `bothParticipantsActive` reflects whether this snapshot's anketa still has
 * two live accounts behind it (neither `employee` nor `manager` has
 * `deletedAt` set) — consulted by GoalMetrics::countCurrentInProgress(),
 * shared by both OverviewAggregator's "Goals, right now" and GoalsAggregator's
 * own identical "right now" numbers, to keep both consistent with the
 * report's users tile, which already excludes deleted accounts
 * (`AdminReportController`'s users query, same reasoning `SeatLimitChecker`
 * documents: a deleted account no longer occupies a functional seat).
 * Deliberately *not* consulted by countCreatedInRange()/countStatusInRange()
 * — those are historical facts about a specific window, and a departed
 * employee's own past history still happened, per the reporting proposal's
 * own §12 Q4 default.
 */
final readonly class GoalSnapshotForReport
{
    public function __construct(
        public string $goalUuid,
        public string $status,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $targetDate,
        public \DateTimeImmutable $anketaMeetingDate,
        public bool $bothParticipantsActive,
    ) {
    }
}
