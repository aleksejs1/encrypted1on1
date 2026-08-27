<?php

namespace App\Report;

use App\Entity\Goal;

/**
 * Goal-snapshot math shared between OverviewAggregator (its own "Goals, right
 * now" tile + achievedInRange) and GoalsAggregator (the dedicated Goals tab,
 * private/company-admin-reporting-proposal.md §7.2/§8.2) — factored out once
 * a second aggregator needed the exact same "current state" and "did this
 * goalUuid reach status X during [from, to]" logic, so the two report types
 * can't silently drift apart on what "overdue" or "achieved in range" means.
 *
 * Every method here takes $goalSnapshots as **every** Goal row for the
 * company, unfiltered by status or date range — see OverviewAggregator's own
 * class docblock for the full reasoning (carry-forward semantics, current-
 * state vs. historical-fact distinction).
 */
final class GoalMetrics
{
    /**
     * @param GoalSnapshotForReport[] $goalSnapshots
     *
     * @return array{0: int, 1: int} [totalInProgress, overdueInProgress]
     */
    public static function countCurrentInProgress(array $goalSnapshots, \DateTimeImmutable $now): array
    {
        // Goal::$targetDate is Doctrine's `date_immutable` type — always hydrated at
        // midnight, with no real time-of-day component. Comparing it against a
        // full-precision $now directly would make a goal due "today" read as overdue
        // for nearly the entire day (its midnight timestamp is less than any later
        // moment on the same day) — truncating $now to the start of today first makes
        // this a genuine calendar-day comparison instead.
        $today = $now->setTime(0, 0, 0, 0);

        $totalInProgress = 0;
        $overdueInProgress = 0;
        foreach (self::latestSnapshotPerGoal($goalSnapshots) as $snapshot) {
            // Same exclusion the users tile already applies to a deleted account — see
            // GoalSnapshotForReport::$bothParticipantsActive's own docblock for why this
            // check belongs here.
            if (Goal::STATUS_IN_PROGRESS !== $snapshot->status || !$snapshot->bothParticipantsActive) {
                continue;
            }
            ++$totalInProgress;
            if (null !== $snapshot->targetDate && $snapshot->targetDate < $today) {
                ++$overdueInProgress;
            }
        }

        return [$totalInProgress, $overdueInProgress];
    }

    /** @param GoalSnapshotForReport[] $goalSnapshots */
    public static function countCreatedInRange(array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $firstSeenPerGoal = [];
        foreach ($goalSnapshots as $snapshot) {
            $existing = $firstSeenPerGoal[$snapshot->goalUuid] ?? null;
            if (null === $existing || $snapshot->createdAt < $existing) {
                $firstSeenPerGoal[$snapshot->goalUuid] = $snapshot->createdAt;
            }
        }

        $createdInRange = 0;
        foreach ($firstSeenPerGoal as $firstSeen) {
            if ($firstSeen >= $from && $firstSeen <= $to) {
                ++$createdInRange;
            }
        }

        return $createdInRange;
    }

    /** @param GoalSnapshotForReport[] $goalSnapshots how many distinct goalUuids reached $status during [from, to] */
    public static function countStatusInRange(array $goalSnapshots, string $status, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $matchingGoalUuids = [];
        foreach ($goalSnapshots as $snapshot) {
            if (self::isStatusInRange($snapshot, $status, $from, $to)) {
                $matchingGoalUuids[$snapshot->goalUuid] = true;
            }
        }

        return \count($matchingGoalUuids);
    }

    /**
     * Whether this snapshot reflects $status having been reached during an anketa
     * cycle whose meeting fell in [from, to]. A status change (achieved/cancelled)
     * mutates a goal's current row in place (AnketaController::updateGoal(),
     * Goal::setStatus()) rather than creating a new snapshot, so there is at most one
     * such row per goalUuid, ever — deduping by goalUuid in countStatusInRange() above
     * is a safety net, not something this method needs to worry about itself.
     */
    private static function isStatusInRange(GoalSnapshotForReport $snapshot, string $status, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return $status === $snapshot->status
            && $snapshot->anketaMeetingDate >= $from && $snapshot->anketaMeetingDate <= $to;
    }

    /**
     * How many distinct goalUuids reached $status in each of $months — the shared
     * dedup-by-goalUuid-per-month math behind both OverviewAggregator's
     * `trend[].goalsAchieved` series and GoalsAggregator's `byMonth[].achieved`
     * series, so the two can't silently disagree on the same underlying history.
     *
     * @param GoalSnapshotForReport[] $goalSnapshots
     * @param list<string>            $months        Y-m labels, as returned by monthsBetween()
     *
     * @return array<string, int> keyed by month
     */
    public static function countStatusByMonth(array $goalSnapshots, string $status, array $months, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $goalUuidsByMonth = array_fill_keys($months, []);
        foreach ($goalSnapshots as $snapshot) {
            if (!self::isStatusInRange($snapshot, $status, $from, $to)) {
                continue;
            }
            $month = $snapshot->anketaMeetingDate->format('Y-m');
            if (isset($goalUuidsByMonth[$month])) {
                $goalUuidsByMonth[$month][$snapshot->goalUuid] = true;
            }
        }

        return array_map(\count(...), $goalUuidsByMonth);
    }

    /** @return list<string> Y-m labels, inclusive of both endpoints' months. */
    public static function monthsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = [];
        $cursor = $from->modify('first day of this month');
        $last = $to->modify('first day of this month');
        while ($cursor <= $last) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        return $months;
    }

    /**
     * One pass, tracking the running latest-by-`anketaMeetingDate` snapshot per
     * goalUuid — a plain "max per key" reduction, not a sort-then-let-later-overwrite
     * trick.
     *
     * @param GoalSnapshotForReport[] $goalSnapshots
     *
     * @return array<string, GoalSnapshotForReport> keyed by goalUuid
     */
    private static function latestSnapshotPerGoal(array $goalSnapshots): array
    {
        $latest = [];
        foreach ($goalSnapshots as $snapshot) {
            $current = $latest[$snapshot->goalUuid] ?? null;
            if (null === $current || $snapshot->anketaMeetingDate >= $current->anketaMeetingDate) {
                $latest[$snapshot->goalUuid] = $snapshot;
            }
        }

        return $latest;
    }
}
