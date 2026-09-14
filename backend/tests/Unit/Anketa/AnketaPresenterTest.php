<?php

namespace App\Tests\Unit\Anketa;

use App\Anketa\AnketaPresenter;
use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\Goal;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class AnketaPresenterTest extends TestCase
{
    private AnketaPresenter $presenter;
    private Company $company;
    private User $employee;
    private User $manager;

    protected function setUp(): void
    {
        $this->presenter = new AnketaPresenter();
        $this->company = new Company('Acme Inc');
        $this->employee = new User('employee@example.com', 'hash', 'emp-pub-key', 'emp-enc-key', $this->company);
        $this->employee->setDisplayName('Alice Employee');
        $this->manager = new User('manager@example.com', 'hash', 'mgr-pub-key', 'mgr-enc-key', $this->company);
        $this->manager->setDisplayName('Bob Manager');
    }

    public function testSerializeGoal(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );

        $targetDate = new \DateTimeImmutable('2026-11-15');
        $goal = new Goal(
            goalUuid: 'uuid-123',
            anketa: $anketa,
            author: $this->employee,
            title: 'Complete project X',
            description: 'Detailed description',
            targetDate: $targetDate,
            status: Goal::STATUS_IN_PROGRESS,
        );

        $result = $this->presenter->serializeGoal($goal);

        self::assertSame($goal->getId(), $result['id']);
        self::assertSame('uuid-123', $result['goalUuid']);
        self::assertSame($this->employee->getId(), $result['authorId']);
        self::assertSame('Complete project X', $result['title']);
        self::assertSame('Detailed description', $result['description']);
        self::assertSame('2026-11-15', $result['targetDate']);
        self::assertSame(Goal::STATUS_IN_PROGRESS, $result['status']);
        self::assertSame($goal->getCreatedAt()->format(\DATE_ATOM), $result['createdAt']);
    }

    public function testSummarizeFromEmployeePerspective(): void
    {
        $meetingDate = new \DateTimeImmutable('2026-10-01 10:00:00');
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: $meetingDate,
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );

        $anketa->publish($this->employee, 'emp-blob');

        $summary = $this->presenter->summarize($anketa, $this->employee);

        self::assertSame($anketa->getId(), $summary['id']);
        self::assertSame('employee', $summary['myRole']);
        self::assertSame($this->manager->getId(), $summary['counterpartId']);
        self::assertSame('manager@example.com', $summary['counterpartEmail']);
        self::assertSame('Bob Manager', $summary['counterpartName']);
        self::assertSame($meetingDate->format(\DATE_ATOM), $summary['meetingDate']);
        self::assertNotNull($summary['myPublishedAt']);
        self::assertNull($summary['counterpartPublishedAt']);
        self::assertNull($summary['archivedAt']);
        self::assertFalse($summary['missed']);
        self::assertSame(14, $summary['periodicityDays']);
        self::assertFalse($summary['counterpartKeyOutdated']);
        self::assertFalse($summary['counterpartDeleted']);
        self::assertSame(Anketa::CURRENT_FORM_VERSION, $summary['formVersion']);
    }

    public function testSummarizeFromManagerPerspective(): void
    {
        $meetingDate = new \DateTimeImmutable('2026-10-01 10:00:00');
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: $meetingDate,
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );

        $anketa->publish($this->employee, 'emp-blob');

        $summary = $this->presenter->summarize($anketa, $this->manager);

        self::assertSame('manager', $summary['myRole']);
        self::assertSame($this->employee->getId(), $summary['counterpartId']);
        self::assertSame('employee@example.com', $summary['counterpartEmail']);
        self::assertSame('Alice Employee', $summary['counterpartName']);
        self::assertNull($summary['myPublishedAt']);
        self::assertNotNull($summary['counterpartPublishedAt']);
    }

    public function testSerializeDetailIncludesBlobsAndGoals(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );
        $anketa->seedOutcomes('outcomes-blob');

        $goal = new Goal(
            goalUuid: 'uuid-456',
            anketa: $anketa,
            author: $this->manager,
            title: 'Manager goal',
            description: null,
            targetDate: null,
        );

        $detail = $this->presenter->serializeDetail($anketa, $this->employee, [$goal]);

        self::assertSame('sealed-emp', $detail['mySealedKey']);
        self::assertSame('mgr-pub-key', $detail['counterpartPublicKey']);
        self::assertNull($detail['employeeBlob']);
        self::assertSame(0, $detail['employeeBlobVersion']);
        self::assertSame('outcomes-blob', $detail['outcomesBlob']);
        self::assertSame(0, $detail['outcomesVersion']);
        self::assertCount(1, $detail['goals']);
        self::assertSame('uuid-456', $detail['goals'][0]['goalUuid']);
    }

    public function testSerializeLiveStateReturnsCountersWithoutBlobs(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );
        $anketa->saveComments('new comments', 0);

        $liveState = $this->presenter->serializeLiveState($anketa, $this->employee);

        self::assertSame($anketa->getId(), $liveState['id']);
        self::assertSame(1, $liveState['commentsVersion']);
        self::assertSame(0, $liveState['employeeBlobVersion']);
        self::assertSame(0, $liveState['managerBlobVersion']);
        self::assertSame(0, $liveState['outcomesVersion']);
        self::assertSame(0, $liveState['goalCheckpointsVersion']);
        self::assertArrayNotHasKey('commentsBlob', $liveState);
        self::assertArrayNotHasKey('mySealedKey', $liveState);
    }

    public function testIsKeyOutdated(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'sealed-emp',
            managerSealedKey: 'sealed-mgr',
            periodicityDays: 14,
        );

        self::assertFalse($this->presenter->isKeyOutdated($anketa, $this->manager));

        // When publicKeyUpdatedAt is set to the future
        $reflection = new \ReflectionProperty(User::class, 'publicKeyUpdatedAt');
        $reflection->setValue($this->manager, new \DateTimeImmutable('+1 hour'));

        self::assertTrue($this->presenter->isKeyOutdated($anketa, $this->manager));

        // After resealing, sealedKeyUpdatedAt is bumped
        $anketa->resealKeyFor($this->manager, 'new-sealed-mgr');

        // If publicKeyUpdatedAt is before sealedKeyUpdatedAt, it's not outdated
        $reflection->setValue($this->manager, new \DateTimeImmutable('-1 hour'));
        self::assertFalse($this->presenter->isKeyOutdated($anketa, $this->manager));
    }
}
