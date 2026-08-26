<?php

namespace App\Tests\Functional;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\Goal;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;

class AdminReportControllerTest extends ApiTestCase
{
    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        // Same reasoning/order as CompanyIsolationTest's own tearDown(): this class
        // creates real extra Company rows, which breaks every other test's implicit
        // reliance on SingleCompanyProvider's "there's only one company" shortcut
        // unless cleaned up here.
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            $connection->executeStatement("DELETE FROM goals WHERE anketa_id IN (SELECT id FROM anketas WHERE employee_id IN (SELECT id FROM users WHERE company_id IN ({$placeholders})))", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM anketas WHERE employee_id IN (SELECT id FROM users WHERE company_id IN ({$placeholders}))", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();

        $result = $this->jsonRequest($client, 'GET', '/api/admin/reports/overview?from=2026-01-01&to=2026-12-31');

        self::assertSame(401, $result['status']);
    }

    public function testRequiresAdmin(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('report-non-admin'));

        $result = $this->jsonRequest($client, 'GET', '/api/admin/reports/overview?from=2026-01-01&to=2026-12-31');

        self::assertSame(403, $result['status']);
    }

    public function testRejectsAMissingToDate(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('report-missing-to'), admin: true);

        $result = $this->jsonRequest($client, 'GET', '/api/admin/reports/overview?from=2026-01-01');

        self::assertSame(400, $result['status']);
    }

    public function testRejectsAnInvalidFromDate(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('report-invalid-from'), admin: true);

        $result = $this->jsonRequest($client, 'GET', '/api/admin/reports/overview?from=not-a-date&to=2026-12-31');

        self::assertSame(400, $result['status']);
    }

    public function testRejectsAFromAfterTo(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('report-backwards-range'), admin: true);

        $result = $this->jsonRequest($client, 'GET', '/api/admin/reports/overview?from=2026-12-31&to=2026-01-01');

        self::assertSame(400, $result['status']);
    }

    public function testRejectsARangeWiderThanTheMaximum(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('report-too-wide-range'), admin: true);

        $result = $this->jsonRequest($client, 'GET', '/api/admin/reports/overview?from=0001-01-01&to=9999-12-31');

        self::assertSame(400, $result['status']);
    }

    public function testAcceptsARangeAtExactlyTheMaximum(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('report-max-range'), admin: true);
        $now = new \DateTimeImmutable();

        $result = $this->jsonRequest($client, 'GET', $this->overviewPath($now->modify('-1826 days'), $now));

        self::assertSame(200, $result['status']);
    }

    public function testCompanyIsolationNeverLeaksAnotherCompanysNumbersIn(): void
    {
        $adminClient = static::createClient();
        $companyA = $this->makeCompany('Report Company A');
        $companyB = $this->makeCompany('Report Company B');
        $admin = $this->activateUser($adminClient, $this->uniqueEmail('report-isolation-admin'), admin: true, company: $companyA);

        $otherEmployee = $this->activateUser($this->secondClient(), $this->uniqueEmail('report-isolation-b-emp'), company: $companyB);
        $otherManager = $this->activateUser($this->secondClient(), $this->uniqueEmail('report-isolation-b-mgr'), company: $companyB);
        $now = new \DateTimeImmutable();
        $this->persistAnketa($otherEmployee, $otherManager, $now->modify('-1 day'), archived: true, missed: false);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($adminClient, 'GET', $this->overviewPath($now->modify('-30 days'), $now->modify('30 days')));

        self::assertSame(200, $result['status']);
        self::assertSame(0, $result['json']['meetings']['total'], "company A's admin must never see company B's anketas");
        self::assertSame(1, $result['json']['users']['active'], 'only the requesting admin belongs to company A here');
    }

    public function testHappyPathReturnsExactNumbersFromKnownFixtures(): void
    {
        $adminClient = static::createClient();
        $company = $this->makeCompany('Report Happy Path');
        $admin = $this->activateUser($adminClient, $this->uniqueEmail('report-happy-admin'), admin: true, company: $company);
        $employeeUser = $this->activateUser($this->secondClient(), $this->uniqueEmail('report-happy-emp'), company: $company);
        $managerUser = $this->activateUser($this->secondClient(), $this->uniqueEmail('report-happy-mgr'), company: $company);

        $now = new \DateTimeImmutable();
        $from = $now->modify('-30 days');
        $to = $now->modify('+30 days');

        // Completed: archived, not missed, both sides published.
        $completed = $this->persistAnketa($employeeUser, $managerUser, $now->modify('-20 days'), archived: true, missed: false, employeePublished: true, managerPublished: true);
        // Missed: archived, missed.
        $missed = $this->persistAnketa($employeeUser, $managerUser, $now->modify('-15 days'), archived: true, missed: true);
        // Overdue-open: meeting date already passed, never archived, nobody published.
        $this->persistAnketa($employeeUser, $managerUser, $now->modify('-5 days'), archived: false, missed: false);
        // Upcoming-open: meeting date still ahead — must not count against responseRate.
        $this->persistAnketa($employeeUser, $managerUser, $now->modify('+5 days'), archived: false, missed: false);

        // A genuinely new goal, created now, attached to the completed anketa (in range),
        // not yet overdue.
        $this->persistGoal($completed, $employeeUser, 'goal-new', Goal::STATUS_IN_PROGRESS, $now->modify('+10 days'));
        // An achieved goal, attached to the missed anketa (in range).
        $this->persistGoal($missed, $employeeUser, 'goal-achieved', Goal::STATUS_ACHIEVED, null);

        // A goal whose *anketa* falls entirely outside the requested [from, to] window
        // (created long ago, never archived) — still overdue *today*, and must still be
        // counted, proving overdueInProgress/totalInProgress aren't accidentally
        // range-filtered. See OverviewAggregator's own class docblock.
        $outsideWindowAnketa = $this->persistAnketa($employeeUser, $managerUser, $now->modify('-200 days'), archived: false, missed: false);
        $oldGoal = $this->persistGoal($outsideWindowAnketa, $employeeUser, 'goal-old-overdue', Goal::STATUS_IN_PROGRESS, $now->modify('-50 days'));

        $this->entityManager()->flush();

        // Goal::__construct() always stamps createdAt as "now" — genuinely backdating a
        // fixture's creation moment (to actually prove createdInRange excludes it) needs
        // raw SQL, same precedent as AnketaControllerTest's own sealedKeyUpdatedAt backdating.
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE goals SET createdAt = ? WHERE id = ?',
            [$now->modify('-200 days')->format('Y-m-d H:i:s'), $oldGoal->getId()],
        );
        $this->entityManager()->clear();

        $result = $this->jsonRequest($adminClient, 'GET', $this->overviewPath($from, $to));

        self::assertSame(200, $result['status']);
        $json = $result['json'];

        self::assertSame(4, $json['meetings']['total']);
        self::assertSame(1, $json['meetings']['completed']);
        self::assertSame(1, $json['meetings']['missed']);
        self::assertSame(1, $json['meetings']['overdueOpen']);
        self::assertSame(1, $json['meetings']['upcomingOpen']);
        // Denominator: 2 * (completed + missed + overdueOpen) = 6; numerator: only the
        // completed anketa's two published sides.
        self::assertEqualsWithDelta(2 / 6, $json['meetings']['responseRate'], 0.0001);

        self::assertSame(2, $json['goals']['createdInRange'], 'goal-new and goal-achieved were both genuinely created just now, inside the window — goal-old-overdue was backdated out of it');
        self::assertSame(1, $json['goals']['achievedInRange']);
        self::assertSame(2, $json['goals']['totalInProgress'], 'goal-new and goal-old-overdue');
        self::assertSame(1, $json['goals']['overdueInProgress'], 'goal-old-overdue, whose own anketa is outside the window');

        self::assertSame(3, $json['users']['active']);
        self::assertSame(0, $json['users']['blocked']);
        self::assertSame(1, $json['users']['admins']);
    }

    public function testAnInProgressGoalIsExcludedFromCurrentCountsOnceItsEmployeeIsDeleted(): void
    {
        $adminClient = static::createClient();
        $company = $this->makeCompany('Report Deleted Employee');
        $this->activateUser($adminClient, $this->uniqueEmail('report-deleted-emp-admin'), admin: true, company: $company);
        $employeeUser = $this->activateUser($this->secondClient(), $this->uniqueEmail('report-deleted-emp-emp'), company: $company);
        $managerUser = $this->activateUser($this->secondClient(), $this->uniqueEmail('report-deleted-emp-mgr'), company: $company);

        $now = new \DateTimeImmutable();
        $anketa = $this->persistAnketa($employeeUser, $managerUser, $now->modify('-10 days'), archived: false, missed: false);
        $this->persistGoal($anketa, $employeeUser, 'goal-orphaned', Goal::STATUS_IN_PROGRESS, $now->modify('-1 day'));
        $this->entityManager()->flush();

        $range = $this->overviewPath($now->modify('-30 days'), $now->modify('30 days'));

        // Before deletion: counted, same as any other in-progress goal.
        $before = $this->jsonRequest($adminClient, 'GET', $range);
        self::assertSame(1, $before['json']['goals']['totalInProgress']);
        self::assertSame(1, $before['json']['goals']['overdueInProgress']);

        $employeeEntity = $this->entityManager()->find(User::class, $employeeUser['id']);
        \assert($employeeEntity instanceof User);
        $employeeEntity->delete();
        $this->entityManager()->flush();

        // After deletion: excluded from "current state" counts, same as the users tile
        // already excludes a deleted account — but the goal's own past history (it did
        // exist, in_progress, during this window) is unaffected.
        $after = $this->jsonRequest($adminClient, 'GET', $range);
        self::assertSame(0, $after['json']['goals']['totalInProgress']);
        self::assertSame(0, $after['json']['goals']['overdueInProgress']);
        self::assertSame(1, $after['json']['goals']['createdInRange'], 'the goal genuinely was created inside this window — that historical fact does not change');
    }

    private function overviewPath(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return '/api/admin/reports/overview?'.http_build_query([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);
    }

    /**
     * @param array{id: string} $employee
     * @param array{id: string} $manager
     */
    private function persistAnketa(array $employee, array $manager, \DateTimeImmutable $meetingDate, bool $archived, bool $missed, bool $employeePublished = false, bool $managerPublished = false): Anketa
    {
        $employeeEntity = $this->entityManager()->find(User::class, $employee['id']);
        $managerEntity = $this->entityManager()->find(User::class, $manager['id']);
        \assert($employeeEntity instanceof User && $managerEntity instanceof User);

        $anketa = new Anketa($employeeEntity, $managerEntity, $meetingDate, 'sealed-e', 'sealed-m', 30);
        if ($employeePublished) {
            $anketa->publish($employeeEntity, 'employee-blob');
        }
        if ($managerPublished) {
            $anketa->publish($managerEntity, 'manager-blob');
        }
        if ($archived) {
            $anketa->archive($missed);
        }
        $this->entityManager()->persist($anketa);

        return $anketa;
    }

    /** @param array{id: string} $author */
    private function persistGoal(Anketa $anketa, array $author, string $goalUuid, string $status, ?\DateTimeImmutable $targetDate): Goal
    {
        $authorEntity = $this->entityManager()->find(User::class, $author['id']);
        \assert($authorEntity instanceof User);

        $goal = new Goal($goalUuid, $anketa, $authorEntity, 'Goal title', null, $targetDate, $status);
        $this->entityManager()->persist($goal);

        return $goal;
    }

    private function makeCompany(string $name): Company
    {
        $company = new Company($name);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }
}
