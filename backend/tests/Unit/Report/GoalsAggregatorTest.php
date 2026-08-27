<?php

namespace App\Tests\Unit\Report;

use App\Entity\Goal;
use App\Report\GoalsAggregator;
use App\Report\GoalSnapshotForReport;
use PHPUnit\Framework\TestCase;

/**
 * The goal-snapshot math itself (carry-forward dedup, current-state vs.
 * historical-fact, the midnight-targetDate overdue comparison) is already
 * covered exhaustively against GoalMetrics via OverviewAggregatorTest — this
 * class only needs to prove GoalsAggregator wires that shared math up
 * correctly and gets its own two additions right: cancelledInRange and the
 * achieved-only byMonth trend.
 */
class GoalsAggregatorTest extends TestCase
{
    private const FROM = '2026-06-01';
    private const TO = '2026-08-31';
    private const NOW = '2026-08-25 12:00:00';

    public function testEmptyInputProducesAllZeroes(): void
    {
        $report = $this->aggregate([]);

        self::assertSame(0, $report['createdInRange']);
        self::assertSame(0, $report['achievedInRange']);
        self::assertSame(0, $report['cancelledInRange']);
        self::assertSame(0, $report['totalInProgress']);
        self::assertSame(0, $report['overdueInProgress']);
    }

    public function testCountsCreatedAchievedCancelledAndInProgressSeparately(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-created', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-01'), true),
            new GoalSnapshotForReport('goal-achieved', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-07-05'), true),
            new GoalSnapshotForReport('goal-cancelled', Goal::STATUS_CANCELLED, new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-07-10'), true),
        ];

        $report = $this->aggregate($goals);

        self::assertSame(1, $report['createdInRange'], 'only goal-created was actually first seen inside the window');
        self::assertSame(1, $report['achievedInRange']);
        self::assertSame(1, $report['cancelledInRange']);
        self::assertSame(1, $report['totalInProgress'], 'goal-created is the only one still in_progress');
    }

    public function testCancelledInRangeIgnoresGoalsCancelledOutsideTheWindow(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-old-cancel', Goal::STATUS_CANCELLED, new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-01-05'), true),
        ];

        $report = $this->aggregate($goals);

        self::assertSame(0, $report['cancelledInRange']);
    }

    public function testOverdueInProgressIgnoresTheDateRangeFilter(): void
    {
        // Same "current state, not windowed" reasoning as OverviewAggregator's own
        // identically-named test — this aggregator reuses the exact same GoalMetrics
        // call, so it must behave identically.
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-05'), true),
        ];

        $report = $this->aggregate($goals);

        self::assertSame(1, $report['totalInProgress']);
        self::assertSame(1, $report['overdueInProgress']);
    }

    public function testByMonthCoversEveryMonthInTheRangeAndCountsAchievedOnly(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-achieved-june', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-06-10'), true),
            new GoalSnapshotForReport('goal-cancelled-august', Goal::STATUS_CANCELLED, new \DateTimeImmutable('2026-01-01'), null, new \DateTimeImmutable('2026-08-20'), true),
        ];

        $report = $this->aggregate($goals);

        self::assertSame(['2026-06', '2026-07', '2026-08'], array_column($report['byMonth'], 'month'));
        self::assertSame([1, 0, 0], array_column($report['byMonth'], 'achieved'), 'the cancelled goal must not show up in the achieved-only trend');
    }

    public function testByMonthDedupsAnAchievedGoalWithMultipleHistoricalSnapshotsToOne(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-10'), null, new \DateTimeImmutable('2026-01-15'), true),
            new GoalSnapshotForReport('goal-1', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-05'), true),
        ];

        $report = $this->aggregate($goals);

        self::assertSame(1, $report['achievedInRange']);
        self::assertSame([0, 1, 0], array_column($report['byMonth'], 'achieved'));
    }

    /**
     * @param GoalSnapshotForReport[] $goals
     *
     * @return array{
     *     range: array{from: string, to: string},
     *     createdInRange: int,
     *     achievedInRange: int,
     *     cancelledInRange: int,
     *     totalInProgress: int,
     *     overdueInProgress: int,
     *     byMonth: list<array{month: string, achieved: int}>,
     * }
     */
    private function aggregate(array $goals): array
    {
        return GoalsAggregator::aggregate(
            $goals,
            new \DateTimeImmutable(self::FROM),
            new \DateTimeImmutable(self::TO),
            new \DateTimeImmutable(self::NOW),
        );
    }
}
