<?php

namespace App\Tests\Unit\Report;

use App\Entity\Goal;
use App\Report\AnketaSummaryForReport;
use App\Report\GoalSnapshotForReport;
use App\Report\OverviewAggregator;
use PHPUnit\Framework\TestCase;

class OverviewAggregatorTest extends TestCase
{
    private const FROM = '2026-06-01';
    private const TO = '2026-08-31';
    private const NOW = '2026-08-25 12:00:00';

    public function testEmptyInputProducesAllZeroesWithNoDivisionByZero(): void
    {
        $report = $this->aggregate([], [], []);

        self::assertSame(0, $report['meetings']['total']);
        self::assertSame(0.0, $report['meetings']['responseRate']);
        self::assertSame(0, $report['goals']['createdInRange']);
        self::assertSame(0, $report['goals']['totalInProgress']);
        self::assertSame(['active' => 0, 'blocked' => 0, 'admins' => 0], $report['users']);
    }

    public function testMeetingsAreClassifiedByArchivedAndMissed(): void
    {
        $anketas = [
            $this->anketa('2026-07-01', archived: true, missed: false, employeePublished: true, managerPublished: true),
            $this->anketa('2026-07-02', archived: true, missed: true, employeePublished: false, managerPublished: false),
            // Not archived, meeting date already in the past relative to "now" — overdue.
            $this->anketa('2026-08-01', archived: false, missed: false, employeePublished: false, managerPublished: false),
            // Not archived, meeting date still in the future — upcoming, excluded from responseRate's denominator.
            $this->anketa('2026-09-15', archived: false, missed: false, employeePublished: false, managerPublished: false),
        ];

        $report = $this->aggregate($anketas, [], []);

        self::assertSame(4, $report['meetings']['total']);
        self::assertSame(1, $report['meetings']['completed']);
        self::assertSame(1, $report['meetings']['missed']);
        self::assertSame(1, $report['meetings']['overdueOpen']);
        self::assertSame(1, $report['meetings']['upcomingOpen']);
    }

    public function testResponseRateExcludesUpcomingAnketasFromItsDenominator(): void
    {
        $anketas = [
            // Completed, both sides published: 2/2.
            $this->anketa('2026-07-01', archived: true, missed: false, employeePublished: true, managerPublished: true),
            // Upcoming, neither side published — must not count as a non-response.
            $this->anketa('2026-09-15', archived: false, missed: false, employeePublished: false, managerPublished: false),
        ];

        $report = $this->aggregate($anketas, [], []);

        self::assertSame(1.0, $report['meetings']['responseRate']);
    }

    public function testACarriedForwardGoalSnapshotInsideTheRangeIsNotDoubleCountedAsNewlyCreated(): void
    {
        // A goal genuinely created before the range, then carried forward into a new
        // anketa whose own meetingDate falls inside [from, to] — carry-forward gives the
        // new row a fresh createdAt (AnketaController::createAnketaWithCarryForward()),
        // so a naive "count in-range rows" would wrongly count this as newly created.
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-10'), null, new \DateTimeImmutable('2026-01-15'), true),
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-05'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(0, $report['goals']['createdInRange'], 'the goal was first created in January, well before the range');
        self::assertSame(1, $report['goals']['totalInProgress']);
    }

    public function testAGenuinelyNewGoalInsideTheRangeIsCountedAsCreated(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-01'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(1, $report['goals']['createdInRange']);
    }

    public function testOverdueInProgressIgnoresTheDateRangeFilter(): void
    {
        // The anketa this goal snapshot belongs to falls outside [from, to] entirely
        // (never archived, so it never got a fresh in-range snapshot either) — the goal
        // is still overdue *today*, and must count regardless of the requested window.
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-05'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(1, $report['goals']['totalInProgress']);
        self::assertSame(1, $report['goals']['overdueInProgress']);
    }

    public function testAGoalDueTodayIsNotYetOverdue(): void
    {
        // Goal::$targetDate is Doctrine's `date_immutable` type — always hydrated at
        // midnight, never as a full self::NOW-style timestamp. Using a midnight value
        // here (not a copy of self::NOW's own time-of-day) is what makes this test
        // actually exercise the real "is $now, at some point later the same day, past
        // a midnight-only target date" comparison OverviewAggregator has to get right.
        $targetDateAsDoctrineWouldHydrateIt = new \DateTimeImmutable('2026-08-25');
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-07-01'), $targetDateAsDoctrineWouldHydrateIt, new \DateTimeImmutable('2026-07-01'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(0, $report['goals']['overdueInProgress']);
    }

    public function testAGoalDueYesterdayIsOverdue(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-08-24'), new \DateTimeImmutable('2026-07-01'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(1, $report['goals']['overdueInProgress']);
    }

    public function testAnAchievedGoalIsOnlyCountedOnceEvenWithMultipleHistoricalSnapshots(): void
    {
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-10'), null, new \DateTimeImmutable('2026-01-15'), true),
            new GoalSnapshotForReport('goal-1', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-05'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(1, $report['goals']['achievedInRange']);
        self::assertSame(0, $report['goals']['totalInProgress'], 'the latest snapshot is achieved, not in_progress');
    }

    public function testAGoalWhoseAnketaHasADeletedParticipantIsExcludedFromCurrentInProgressCounts(): void
    {
        // Same exclusion the users tile already applies (deletedAt IS NULL) — an
        // in_progress goal left behind by a departed employee shouldn't keep inflating
        // "Goals, right now" forever, since the users tile right next to it correctly
        // drops that person from its own count.
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-01-05'), false),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(0, $report['goals']['totalInProgress']);
        self::assertSame(0, $report['goals']['overdueInProgress']);
    }

    public function testADeletedParticipantDoesNotAffectCreatedOrAchievedInRangeHistoricalCounts(): void
    {
        // createdInRange/achievedInRange are historical facts about a window, not
        // current state — a departed employee's own past history still happened (the
        // reporting proposal's own §12 Q4 default), unlike totalInProgress/
        // overdueInProgress above.
        $from = new \DateTimeImmutable('2026-06-01');
        $to = new \DateTimeImmutable('2026-08-31');
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-01'), false),
        ];

        $report = OverviewAggregator::aggregate([], $goals, [], $from, $to, new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $report['goals']['createdInRange']);
        self::assertSame(1, $report['goals']['achievedInRange']);
    }

    public function testGoalDedupIsOrderIndependent(): void
    {
        // Snapshots handed in out of meetingDate order must still resolve to the
        // chronologically latest one, not whichever happens to be processed last.
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-07-01'), null, new \DateTimeImmutable('2026-07-05'), true),
            new GoalSnapshotForReport('goal-1', Goal::STATUS_IN_PROGRESS, new \DateTimeImmutable('2026-01-10'), null, new \DateTimeImmutable('2026-01-15'), true),
        ];

        $report = $this->aggregate([], $goals, []);

        self::assertSame(0, $report['goals']['totalInProgress']);
        self::assertSame(1, $report['goals']['achievedInRange']);
    }

    public function testUsersAreCountedByCurrentBlockedAndAdminState(): void
    {
        $users = [
            ['isBlocked' => false, 'isAdmin' => true],
            ['isBlocked' => false, 'isAdmin' => false],
            ['isBlocked' => true, 'isAdmin' => false],
        ];

        $report = $this->aggregate([], [], $users);

        self::assertSame(['active' => 2, 'blocked' => 1, 'admins' => 1], $report['users']);
    }

    public function testTrendCoversEveryMonthInTheRangeIncludingEmptyOnes(): void
    {
        $anketas = [
            $this->anketa('2026-06-10', archived: true, missed: false, employeePublished: true, managerPublished: true),
            $this->anketa('2026-08-20', archived: true, missed: false, employeePublished: true, managerPublished: true),
        ];

        $report = $this->aggregate($anketas, [], []);

        self::assertSame(
            ['2026-06', '2026-07', '2026-08'],
            array_column($report['trend'], 'month'),
        );
        self::assertSame([1, 0, 1], array_column($report['trend'], 'meetingsCompleted'));
    }

    public function testTrendGoalsAchievedAgreesWithTheAchievedInRangeTileOnAPartialFirstMonth(): void
    {
        // A goal achieved just before the requested range starts, but in the same
        // calendar month as `from` — the trend's monthly buckets are labeled by
        // calendar month, but must still respect the exact day-level range, or this
        // bar would show a goal the achievedInRange tile above it doesn't count.
        $from = new \DateTimeImmutable('2026-08-10');
        $to = new \DateTimeImmutable('2026-08-31');
        $goals = [
            new GoalSnapshotForReport('goal-1', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-08-01'), null, new \DateTimeImmutable('2026-08-01'), true),
            new GoalSnapshotForReport('goal-2', Goal::STATUS_ACHIEVED, new \DateTimeImmutable('2026-08-15'), null, new \DateTimeImmutable('2026-08-15'), true),
        ];

        $report = OverviewAggregator::aggregate([], $goals, [], $from, $to, new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $report['goals']['achievedInRange'], 'only goal-2 falls inside [from, to]');
        self::assertSame(
            [1],
            array_column($report['trend'], 'goalsAchieved'),
            "the trend's August bucket must agree with the achievedInRange tile — goal-1 is outside the range despite sharing August's calendar month",
        );
    }

    /**
     * @param AnketaSummaryForReport[]                    $anketas
     * @param GoalSnapshotForReport[]                     $goals
     * @param list<array{isBlocked: bool, isAdmin: bool}> $users
     *
     * @return array{
     *     range: array{from: string, to: string},
     *     meetings: array{total: int, completed: int, missed: int, overdueOpen: int, upcomingOpen: int, responseRate: float},
     *     goals: array{createdInRange: int, achievedInRange: int, totalInProgress: int, overdueInProgress: int},
     *     users: array{active: int, blocked: int, admins: int},
     *     trend: list<array{month: string, meetingsCompleted: int, goalsAchieved: int}>,
     * }
     */
    private function aggregate(array $anketas, array $goals, array $users): array
    {
        return OverviewAggregator::aggregate(
            $anketas,
            $goals,
            $users,
            new \DateTimeImmutable(self::FROM),
            new \DateTimeImmutable(self::TO),
            new \DateTimeImmutable(self::NOW),
        );
    }

    private function anketa(string $meetingDate, bool $archived, bool $missed, bool $employeePublished, bool $managerPublished): AnketaSummaryForReport
    {
        return new AnketaSummaryForReport(
            meetingDate: new \DateTimeImmutable($meetingDate),
            archivedAt: $archived ? new \DateTimeImmutable($meetingDate) : null,
            missed: $missed,
            employeePublishedAt: $employeePublished ? new \DateTimeImmutable($meetingDate) : null,
            managerPublishedAt: $managerPublished ? new \DateTimeImmutable($meetingDate) : null,
        );
    }
}
