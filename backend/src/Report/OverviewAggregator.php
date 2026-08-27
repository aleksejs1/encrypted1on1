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
 *
 * The actual per-goalUuid grouping/classification logic lives in
 * GoalMetrics, not here — GoalsAggregator (the dedicated Goals tab) needs
 * the identical "current state" and "reached status X in [from, to]" math
 * over the same kind of unfiltered snapshot list, so it's factored out
 * rather than reimplemented a second time.
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
        [$totalInProgress, $overdueInProgress] = GoalMetrics::countCurrentInProgress($goalSnapshots, $now);

        return [
            'createdInRange' => GoalMetrics::countCreatedInRange($goalSnapshots, $from, $to),
            'achievedInRange' => GoalMetrics::countStatusInRange($goalSnapshots, Goal::STATUS_ACHIEVED, $from, $to),
            'totalInProgress' => $totalInProgress,
            'overdueInProgress' => $overdueInProgress,
        ];
    }

    /**
     * @param AnketaSummaryForReport[] $anketas
     * @param GoalSnapshotForReport[]  $goalSnapshots
     *
     * @return list<array{month: string, meetingsCompleted: int, goalsAchieved: int}>
     */
    private static function buildTrend(array $anketas, array $goalSnapshots, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $months = GoalMetrics::monthsBetween($from, $to);

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

        $achievedByMonth = GoalMetrics::countStatusByMonth($goalSnapshots, Goal::STATUS_ACHIEVED, $months, $from, $to);

        return array_map(
            static fn (string $month) => [
                'month' => $month,
                'meetingsCompleted' => $meetingsCompletedByMonth[$month],
                'goalsAchieved' => $achievedByMonth[$month],
            ],
            $months,
        );
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
