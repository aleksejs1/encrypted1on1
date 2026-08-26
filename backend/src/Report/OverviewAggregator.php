<?php

namespace App\Report;

use App\Entity\Goal;

/**
 * Pure, unit-tested, no EntityManager — same fetch/aggregate split as the
 * frontend's own aggregateReport() (frontend/src/anketa/report.ts), backend
 * side: AdminReportController does a handful of narrow, indexed fetches and
 * hands plain DTOs here; all the actual date-bucketing/classification math
 * lives in this class so it's testable against bare fixtures instead of
 * through a kernel-booted HTTP round trip.
 *
 * $goalSnapshots is deliberately **every** Goal row for the company —
 * unfiltered by both status and date range, not just the requested window's
 * `in_progress` ones a first read of the reporting proposal's own query
 * sketch might suggest. That's a deliberate simplification made here, not
 * in the proposal: three of this aggregator's four goal numbers each need a
 * different slice of history that a single range/status-filtered query
 * can't provide at once —
 *   - `totalInProgress`/`overdueInProgress` need each goalUuid's *current*
 *     state, i.e. its most recent snapshot by `anketaMeetingDate` (carry-forward
 *     creates a fresh row, sharing `goalUuid`, on every cycle a goal stays open —
 *     AnketaController::createAnketaWithCarryForward());
 *   - `createdInRange` needs each goalUuid's *first-ever* snapshot — carry-forward
 *     also gives every continuation row a brand-new `createdAt`
 *     (Goal::__construct()), so naively counting in-range rows would count a
 *     goal as "created" again on every cycle it merely continues into;
 *   - `achievedInRange` needs to know whether *any* snapshot for a goalUuid
 *     reached `achieved` status during an anketa whose meeting fell in this
 *     window — achieving a goal mutates its current row in place
 *     (AnketaController::updateGoal(), Goal::setStatus()), so there is at
 *     most one such row per goalUuid, ever.
 * One unfiltered fetch (small — this app's whole scale envelope is a
 * self-hosted company's own goal history, not a multi-tenant firehose) and
 * doing all three kinds of grouping here in PHP is simpler and provably
 * correct, rather than three separately-shaped queries each re-deriving
 * part of the same goalUuid history.
 */
final class OverviewAggregator
{
    /**
     * @param AnketaSummaryForReport[]                    $anketas       every anketa in [from, to]
     * @param GoalSnapshotForReport[]                     $goalSnapshots every Goal row for the company, unfiltered
     * @param list<array{isBlocked: bool, isAdmin: bool}> $users         every non-deleted user in the company, current state
     *
     * @return array{
     *     range: array{from: string, to: string},
     *     meetings: array{total: int, completed: int, missed: int, overdueOpen: int, upcomingOpen: int, responseRate: float},
     *     goals: array{createdInRange: int, achievedInRange: int, totalInProgress: int, overdueInProgress: int},
     *     users: array{active: int, blocked: int, admins: int},
     *     trend: list<array{month: string, meetingsCompleted: int, goalsAchieved: int}>,
     * }
     */
    public static function aggregate(
        array $anketas,
        array $goalSnapshots,
        array $users,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        \DateTimeImmutable $now,
    ): array {
        return [
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'meetings' => self::classifyMeetings($anketas, $now),
            'goals' => self::aggregateGoals($goalSnapshots, $from, $to, $now),
            'users' => self::countUsers($users),
            'trend' => self::buildTrend($anketas, $goalSnapshots, $from, $to),
        ];
    }

    /**
     * @param AnketaSummaryForReport[] $anketas
     *
     * @return array{total: int, completed: int, missed: int, overdueOpen: int, upcomingOpen: int, responseRate: float}
     */
    private static function classifyMeetings(array $anketas, \DateTimeImmutable $now): array
    {
        $completed = 0;
        $missed = 0;
        $overdueOpen = 0;
        $upcomingOpen = 0;
        $publishedSides = 0;
        // Only counted for anketas whose deadline has already passed (completed/missed/
        // overdueOpen) — an upcomingOpen anketa hasn't reached its meeting date yet, so
        // neither side having published anything is expected, not a non-response.
        $respondableSides = 0;

        foreach ($anketas as $anketa) {
            if (null !== $anketa->archivedAt) {
                if (self::isCompletedMeeting($anketa)) {
                    ++$completed;
                } else {
                    ++$missed;
                }
            } elseif ($anketa->meetingDate < $now) {
                ++$overdueOpen;
            } else {
                ++$upcomingOpen;
                continue;
            }

            $respondableSides += 2;
            if (null !== $anketa->employeePublishedAt) {
                ++$publishedSides;
            }
            if (null !== $anketa->managerPublishedAt) {
                ++$publishedSides;
            }
        }

        return [
            'total' => \count($anketas),
            'completed' => $completed,
            'missed' => $missed,
            'overdueOpen' => $overdueOpen,
            'upcomingOpen' => $upcomingOpen,
            // Explicit float cast: PHP's `/` returns an int when both operands are
            // integers and divide evenly (e.g. 2/2), which would make this field's JSON
            // type flip between an integer and a float depending on the exact numbers —
            // surprising for a frontend that always expects a 0..1 ratio.
            'responseRate' => $respondableSides > 0 ? (float) $publishedSides / $respondableSides : 0.0,
        ];
    }

    /** Shared by classifyMeetings() and buildTrend() — one place for "what counts as completed" so the meetings.completed tile and the trend chart's meetingsCompleted series can't silently drift apart. */
    private static function isCompletedMeeting(AnketaSummaryForReport $anketa): bool
    {
        return null !== $anketa->archivedAt && !$anketa->missed;
    }

    /**
     * @param GoalSnapshotForReport[] $goalSnapshots
     *
     * @return array{createdInRange: int, achievedInRange: int, totalInProgress: int, overdueInProgress: int}
     */
    private static function aggregateGoals(array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
    {
        [$totalInProgress, $overdueInProgress] = self::countCurrentInProgress($goalSnapshots, $now);

        return [
            'createdInRange' => self::countCreatedInRange($goalSnapshots, $from, $to),
            'achievedInRange' => self::countAchievedInRange($goalSnapshots, $from, $to),
            'totalInProgress' => $totalInProgress,
            'overdueInProgress' => $overdueInProgress,
        ];
    }

    /**
     * @param GoalSnapshotForReport[] $goalSnapshots
     *
     * @return array{0: int, 1: int} [totalInProgress, overdueInProgress]
     */
    private static function countCurrentInProgress(array $goalSnapshots, \DateTimeImmutable $now): array
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
            // check belongs here and nowhere else in this class.
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
    private static function countCreatedInRange(array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to): int
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

    /** @param GoalSnapshotForReport[] $goalSnapshots */
    private static function countAchievedInRange(array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $achievedGoalUuids = [];
        foreach ($goalSnapshots as $snapshot) {
            if (self::isAchievedInRange($snapshot, $from, $to)) {
                $achievedGoalUuids[$snapshot->goalUuid] = true;
            }
        }

        return \count($achievedGoalUuids);
    }

    /**
     * Shared by countAchievedInRange() and buildTrend() — one place for "does this
     * snapshot count as achieved in this window" so the achievedInRange tile and the
     * trend chart's goalsAchieved series can't silently drift apart. Both the exact
     * [from, to] bounds *and* the status check matter: $goalSnapshots is company-wide
     * and unfiltered (see this class's own docblock), so a snapshot from just before
     * `from` would otherwise slip through a month-label-only check in the trend chart.
     */
    private static function isAchievedInRange(GoalSnapshotForReport $snapshot, \DateTimeImmutable $from, \DateTimeImmutable $to): bool
    {
        return Goal::STATUS_ACHIEVED === $snapshot->status
            && $snapshot->anketaMeetingDate >= $from && $snapshot->anketaMeetingDate <= $to;
    }

    /**
     * @param AnketaSummaryForReport[] $anketas
     * @param GoalSnapshotForReport[]  $goalSnapshots
     *
     * @return list<array{month: string, meetingsCompleted: int, goalsAchieved: int}>
     */
    private static function buildTrend(array $anketas, array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = self::monthsBetween($from, $to);

        $meetingsCompletedByMonth = array_fill_keys($months, 0);
        foreach ($anketas as $anketa) {
            if (!self::isCompletedMeeting($anketa)) {
                continue;
            }
            $month = $anketa->meetingDate->format('Y-m');
            if (isset($meetingsCompletedByMonth[$month])) {
                ++$meetingsCompletedByMonth[$month];
            }
        }

        $achievedGoalUuidsByMonth = array_fill_keys($months, []);
        foreach ($goalSnapshots as $snapshot) {
            if (!self::isAchievedInRange($snapshot, $from, $to)) {
                continue;
            }
            $month = $snapshot->anketaMeetingDate->format('Y-m');
            if (isset($achievedGoalUuidsByMonth[$month])) {
                $achievedGoalUuidsByMonth[$month][$snapshot->goalUuid] = true;
            }
        }

        return array_map(
            static fn (string $month) => [
                'month' => $month,
                'meetingsCompleted' => $meetingsCompletedByMonth[$month],
                'goalsAchieved' => \count($achievedGoalUuidsByMonth[$month]),
            ],
            $months,
        );
    }

    /** @return list<string> Y-m labels, inclusive of both endpoints' months. */
    private static function monthsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
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
     * trick (that was the original shape here; a full O(n log n) sort was strictly more
     * work than this needs for the same result).
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

    /**
     * @param list<array{isBlocked: bool, isAdmin: bool}> $users
     *
     * @return array{active: int, blocked: int, admins: int}
     */
    private static function countUsers(array $users): array
    {
        $active = 0;
        $blocked = 0;
        $admins = 0;
        foreach ($users as $user) {
            if ($user['isBlocked']) {
                ++$blocked;
            } else {
                ++$active;
            }
            if ($user['isAdmin']) {
                ++$admins;
            }
        }

        return ['active' => $active, 'blocked' => $blocked, 'admins' => $admins];
    }
}
