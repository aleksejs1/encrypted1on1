<?php

namespace App\Report;

use App\Entity\Goal;

/**
 * The Goals tab (private/company-admin-reporting-proposal.md §7.2/§8.2, Phase
 * 2) — a focused view of the goals half of OverviewAggregator's own report,
 * plus `cancelledInRange` and a dedicated achieved-per-month trend Overview's
 * summary card row doesn't need. Same pure, no-EntityManager shape as
 * OverviewAggregator, and shares its goal-snapshot math via GoalMetrics —
 * see that class's own docblock for why $goalSnapshots must be **every**
 * Goal row for the company, unfiltered by status or date range.
 *
 * Deliberately returns no goal `title`/`description` anywhere — aggregate
 * counts only, per the proposal's own §5 decision table.
 */
final class GoalsAggregator
{
    /**
     * @param GoalSnapshotForReport[] $goalSnapshots every Goal row for the company, unfiltered
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
    public static function aggregate(array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
    {
        [$totalInProgress, $overdueInProgress] = GoalMetrics::countCurrentInProgress($goalSnapshots, $now);

        return [
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'createdInRange' => GoalMetrics::countCreatedInRange($goalSnapshots, $from, $to),
            'achievedInRange' => GoalMetrics::countStatusInRange($goalSnapshots, Goal::STATUS_ACHIEVED, $from, $to),
            'cancelledInRange' => GoalMetrics::countStatusInRange($goalSnapshots, Goal::STATUS_CANCELLED, $from, $to),
            'totalInProgress' => $totalInProgress,
            'overdueInProgress' => $overdueInProgress,
            'byMonth' => self::buildByMonth($goalSnapshots, $from, $to),
        ];
    }

    /**
     * @param GoalSnapshotForReport[] $goalSnapshots
     *
     * @return list<array{month: string, achieved: int}>
     */
    private static function buildByMonth(array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = GoalMetrics::monthsBetween($from, $to);
        $achievedByMonth = GoalMetrics::countStatusByMonth($goalSnapshots, Goal::STATUS_ACHIEVED, $months, $from, $to);

        return array_map(
            static fn (string $month) => [
                'month' => $month,
                'achieved' => $achievedByMonth[$month],
            ],
            $months,
        );
    }
}
