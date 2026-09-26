<?php

namespace App\Tests\Unit\Anketa;

use App\Anketa\AnketaAlreadyArchivedException;
use App\Anketa\AnketaLifecycleService;
use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\Goal;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use App\Repository\AnketaRepository;
use App\Repository\GoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AnketaLifecycleServiceTest extends TestCase
{
    private Company $company;
    private User $employee;
    private User $manager;

    protected function setUp(): void
    {
        $this->company = new Company('Acme Inc');
        $this->employee = new User('emp@example.com', 'hash', 'emp-pub', 'emp-enc', $this->company);
        $this->manager = new User('mgr@example.com', 'hash', 'mgr-pub', 'mgr-enc', $this->company);
    }

    private function createService(
        ?EntityManagerInterface $entityManager = null,
        ?GoalRepository $goalRepository = null,
        ?AnketaNotifier $notifier = null,
        ?AnketaRepository $anketaRepository = null,
    ): AnketaLifecycleService {
        if (null === $entityManager) {
            $entityManager = self::createStub(EntityManagerInterface::class);
            $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $transaction) => $transaction());
        }
        if (null === $anketaRepository) {
            $anketaRepository = self::createStub(AnketaRepository::class);
            // Stands in for the conditional UPDATE plus the service's refresh() of the
            // entity, which a mocked EntityManager can't do: the row is now archived,
            // and so is the in-memory entity.
            $anketaRepository->method('markArchivedIfOpen')->willReturnCallback(static function (Anketa $anketa, \DateTimeImmutable $archivedAt, bool $missed): bool {
                $anketa->archive($missed);

                return true;
            });
        }

        return new AnketaLifecycleService(
            $entityManager,
            $goalRepository ?? self::createStub(GoalRepository::class),
            $anketaRepository,
            $notifier ?? self::createStub(AnketaNotifier::class),
        );
    }

    public function testCreateWithCarryForwardCopiesInProgressGoals(): void
    {
        $meetingDate = new \DateTimeImmutable('2026-10-01 10:00:00');
        $previousAnketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'old-emp',
            managerSealedKey: 'old-mgr',
            periodicityDays: 14,
        );

        $existingGoal = new Goal(
            goalUuid: 'carried-uuid',
            anketa: $previousAnketa,
            author: $this->employee,
            title: 'Carry me',
            description: null,
            targetDate: null,
            status: Goal::STATUS_IN_PROGRESS,
        );

        $goalRepository = $this->createMock(GoalRepository::class);
        $goalRepository->expects(self::once())
            ->method('findInProgressForAnketa')
            ->with($previousAnketa)
            ->willReturn([$existingGoal]);

        $persistedObjects = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(function (object $entity) use (&$persistedObjects): void {
                $persistedObjects[] = $entity;
            });

        $service = $this->createService(entityManager: $entityManager, goalRepository: $goalRepository);

        $anketa = $service->createWithCarryForward(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: $meetingDate,
            employeeSealedKey: 'new-emp',
            managerSealedKey: 'new-mgr',
            periodicityDays: 14,
            outcomesBlob: 'initial-outcomes',
            carryFrom: $previousAnketa,
        );

        self::assertSame($anketa, $persistedObjects[0]);
        self::assertInstanceOf(Goal::class, $persistedObjects[1]);
        self::assertSame('carried-uuid', $persistedObjects[1]->getGoalUuid());
        self::assertSame('Carry me', $persistedObjects[1]->getTitle());
        self::assertSame('initial-outcomes', $anketa->getOutcomesBlob());
        self::assertSame('regular', $anketa->getTemplateKey());
    }

    public function testCreateWithCarryForwardUsesTheGivenTemplateKey(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');

        $service = $this->createService(entityManager: $entityManager);

        $anketa = $service->createWithCarryForward(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
            templateKey: 'onboarding',
        );

        self::assertSame('onboarding', $anketa->getTemplateKey());
    }

    public function testCreateAnketaFlushesAndNotifies(): void
    {
        $meetingDate = new \DateTimeImmutable('2026-10-01 10:00:00');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $notifier = $this->createMock(AnketaNotifier::class);
        $notifier->expects(self::once())
            ->method('notifyAnketaCreated')
            ->with(
                self::isInstanceOf(Anketa::class),
                $this->manager,
                $this->employee,
            );

        $service = $this->createService(entityManager: $entityManager, notifier: $notifier);

        $anketa = $service->createAnketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: $meetingDate,
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 7,
            creator: $this->employee,
        );

        self::assertSame(7, $anketa->getPeriodicityDays());
    }

    public function testArchiveWithNextMeetingAutoRecreation(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $goalRepository = $this->createMock(GoalRepository::class);
        $goalRepository->expects(self::once())
            ->method('findInProgressForAnketa')
            ->with($anketa)
            ->willReturn([]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist'); // for next anketa
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $transaction) => $transaction());
        $entityManager->expects(self::once())->method('refresh')->with($anketa);

        $notifier = $this->createMock(AnketaNotifier::class);
        $notifier->expects(self::once())
            ->method('notifyAnketaCreated')
            ->with(
                self::isInstanceOf(Anketa::class),
                $this->manager,
                $this->employee,
            );

        // The successor's default meeting date must come from the same archivedAt the
        // conditional UPDATE wrote, not a second clock read.
        $writtenArchivedAt = null;
        $anketaRepository = $this->createMock(AnketaRepository::class);
        $anketaRepository->expects(self::once())
            ->method('markArchivedIfOpen')
            ->willReturnCallback(static function (Anketa $archived, \DateTimeImmutable $archivedAt, bool $missed) use (&$writtenArchivedAt): bool {
                $writtenArchivedAt = $archivedAt;
                $archived->archive($missed);

                return true;
            });

        $service = $this->createService(
            entityManager: $entityManager,
            goalRepository: $goalRepository,
            notifier: $notifier,
            anketaRepository: $anketaRepository,
        );

        $nextAnketa = $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
            nextMeetingDate: null,
            mySealedKey: 'next-emp-key',
            counterpartSealedKey: 'next-mgr-key',
            outcomesBlob: null,
            nextTemplateKey: 'regular',
        );

        self::assertTrue($anketa->isArchived());
        self::assertNotNull($nextAnketa);
        self::assertSame('next-emp-key', $nextAnketa->sealedKeyFor($this->employee));
        self::assertSame('next-mgr-key', $nextAnketa->sealedKeyFor($this->manager));
        self::assertNotNull($writtenArchivedAt);
        self::assertEquals($writtenArchivedAt->modify('+14 days'), $nextAnketa->getMeetingDate());
        self::assertSame('regular', $nextAnketa->getTemplateKey());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function nextCycleTemplateKeyProvider(): array
    {
        return [
            // One non-identity mapping is enough to prove the default goes through the
            // map; the full per-template table is AnketaTest::testNextCycleTemplateKeyFor's.
            'regular' => ['regular', 'regular'],
            'onboarding' => ['onboarding', 'regular'],
            // The entity accepts any string (the DTO layer rejects an unrecognized key
            // as user input) — stale data from a retired template, or bad data.
            'unrecognized key' => ['not-a-real-key', 'regular'],
        ];
    }

    /**
     * GitHub issue #140: the default successor template comes from
     * Anketa::NEXT_CYCLE_TEMPLATE_KEY, not a blind carry-forward the way
     * periodicityDays is.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nextCycleTemplateKeyProvider')]
    public function testDefaultNextTemplateUsesNextCycleTemplateKeyMap(string $templateKey, string $expectedNextTemplateKey): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
            templateKey: $templateKey,
        );

        self::assertSame($expectedNextTemplateKey, $this->createService()->defaultNextTemplate($anketa));
    }

    /** A one-off never has a successor, so it has no default next template either. */
    public function testDefaultNextTemplateIsNullForAOneOff(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
            templateKey: 'career_growth',
            oneOff: true,
        );

        self::assertNull($this->createService()->defaultNextTemplate($anketa));
    }

    /**
     * GitHub issue #140: archive() creates the successor with exactly the template key
     * it's given — here a non-default one for a regular anketa — and never consults
     * the map itself.
     */
    public function testArchiveCreatesTheSuccessorWithTheGivenTemplateKey(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $goalRepository = self::createStub(GoalRepository::class);
        $goalRepository->method('findInProgressForAnketa')->willReturn([]);

        $service = $this->createService(goalRepository: $goalRepository);

        $nextAnketa = $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
            mySealedKey: 'next-emp-key',
            counterpartSealedKey: 'next-mgr-key',
            nextTemplateKey: 'career_growth',
        );

        self::assertNotNull($nextAnketa);
        self::assertSame('career_growth', $nextAnketa->getTemplateKey());
        self::assertFalse($nextAnketa->isOneOff());
    }

    /**
     * A missing template key while a successor is due is a programming error (the
     * controller always resolves one), not a fallback: the same defensive 400 as a
     * missing sealed key, thrown before the transaction opens. Not reachable through
     * the controller, so tested here directly.
     */
    public function testArchiveThrowsExceptionWhenNextAnketaMissingTemplateKey(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $service = $this->createService(entityManager: $entityManager);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/Next anketa requires a template key\./');

        $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
            mySealedKey: 'next-emp-key',
            counterpartSealedKey: 'next-mgr-key',
        );
    }

    /**
     * GitHub issue #130: a request that loses the race to archive (its in-memory copy is
     * still unarchived, but another request's archive already landed) must create no
     * successor, notify nobody, and leave the in-memory entity alone.
     */
    public function testArchiveThatLosesTheRaceCreatesNothing(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $anketaRepository = $this->createMock(AnketaRepository::class);
        $anketaRepository->expects(self::once())
            ->method('markArchivedIfOpen')
            ->with($anketa, self::isInstanceOf(\DateTimeImmutable::class), true)
            ->willReturn(false);

        $goalRepository = $this->createMock(GoalRepository::class);
        $goalRepository->expects(self::never())->method('findInProgressForAnketa');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $transaction) => $transaction());
        $entityManager->expects(self::never())->method('persist');

        $notifier = $this->createMock(AnketaNotifier::class);
        $notifier->expects(self::never())->method('notifyAnketaCreated');

        $service = $this->createService(
            entityManager: $entityManager,
            goalRepository: $goalRepository,
            notifier: $notifier,
            anketaRepository: $anketaRepository,
        );

        try {
            $service->archive(
                anketa: $anketa,
                actor: $this->employee,
                missed: true,
                skipNextMeeting: false,
                mySealedKey: 'next-emp-key',
                counterpartSealedKey: 'next-mgr-key',
                nextTemplateKey: 'regular',
            );
            self::fail('Expected AnketaAlreadyArchivedException.');
        } catch (AnketaAlreadyArchivedException) {
        }

        self::assertFalse($anketa->isArchived());
        self::assertFalse($anketa->isMissed());
    }

    public function testArchiveWithSkipNextMeetingDoesNotCreateNext(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $transaction) => $transaction());

        $service = $this->createService(entityManager: $entityManager);

        $nextAnketa = $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: true,
            skipNextMeeting: true,
        );

        self::assertTrue($anketa->isArchived());
        self::assertTrue($anketa->isMissed());
        self::assertNull($nextAnketa);
    }

    public function testArchiveWhenParticipantBlockedDoesNotCreateNext(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $this->manager->setBlocked(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $transaction) => $transaction());

        $service = $this->createService(entityManager: $entityManager);

        $nextAnketa = $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
        );

        self::assertTrue($anketa->isArchived());
        self::assertNull($nextAnketa);
    }

    /** GitHub issue #111: a one-off anketa never auto-recreates, even with keys sent. */
    public function testArchiveOfAOneOffAnketaDoesNotCreateNext(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
            templateKey: 'career_growth',
            oneOff: true,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('wrapInTransaction')->willReturnCallback(static fn (callable $transaction) => $transaction());

        $notifier = $this->createMock(AnketaNotifier::class);
        $notifier->expects(self::never())->method('notifyAnketaCreated');

        $service = $this->createService(entityManager: $entityManager, notifier: $notifier);

        $nextAnketa = $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
            mySealedKey: 'next-my-key',
            counterpartSealedKey: 'next-counterpart-key',
        );

        self::assertTrue($anketa->isArchived());
        self::assertNull($nextAnketa);
    }

    /** GitHub issue #111: the service itself drops the carry-forward for a one-off. */
    public function testCreateWithCarryForwardIgnoresCarryForwardForAOneOff(): void
    {
        $previousAnketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'old-emp',
            managerSealedKey: 'old-mgr',
            periodicityDays: 14,
        );

        $goalRepository = $this->createMock(GoalRepository::class);
        $goalRepository->expects(self::never())->method('findInProgressForAnketa');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');

        $service = $this->createService(entityManager: $entityManager, goalRepository: $goalRepository);

        $anketa = $service->createWithCarryForward(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'new-emp',
            managerSealedKey: 'new-mgr',
            periodicityDays: 14,
            outcomesBlob: 'client-carried-outcomes',
            carryFrom: $previousAnketa,
            oneOff: true,
        );

        self::assertTrue($anketa->isOneOff());
        self::assertNull($anketa->getOutcomesBlob());
    }

    public function testCreateWithCarryForwardPassesOneOffThrough(): void
    {
        $service = $this->createService();

        $regular = $service->createWithCarryForward(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );
        $oneOff = $service->createWithCarryForward(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-10-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
            oneOff: true,
        );

        self::assertFalse($regular->isOneOff());
        self::assertTrue($oneOff->isOneOff());
    }

    public function testReshareKey(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService(entityManager: $entityManager);

        $service->reshareKey($anketa, $this->employee, 'new-mgr-key');

        self::assertSame('new-mgr-key', $anketa->sealedKeyFor($this->manager));
    }

    public function testSaveDraftAndPublish(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('flush');

        $service = $this->createService(entityManager: $entityManager);

        $service->saveDraft($anketa, $this->employee, 'draft-blob');
        self::assertSame('draft-blob', $anketa->getEmployeeBlob());
        self::assertNull($anketa->getEmployeePublishedAt());

        $service->publish($anketa, $this->employee, 'published-blob');
        self::assertSame('published-blob', $anketa->getEmployeeBlob());
        self::assertNotNull($anketa->getEmployeePublishedAt());
    }

    public function testUpdatePublishedAnswers(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );
        $anketa->publish($this->employee, 'initial');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->createService(entityManager: $entityManager);

        // On matching version: updates and flushes
        $success = $service->updatePublishedAnswers($anketa, $this->employee, 'updated', 0);
        self::assertTrue($success);
        self::assertSame('updated', $anketa->getEmployeeBlob());
        self::assertSame(1, $anketa->getEmployeeBlobVersion());

        // On stale version: fails and does not flush
        $conflict = $service->updatePublishedAnswers($anketa, $this->employee, 'conflict', 0);
        self::assertFalse($conflict);
        self::assertSame('updated', $anketa->getEmployeeBlob());
    }

    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function missingSealedKeysProvider(): array
    {
        return [
            'both null' => [null, null],
            'mySealedKey null' => [null, 'mgr-key'],
            'counterpartSealedKey null' => ['emp-key', null],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingSealedKeysProvider')]
    public function testArchiveThrowsExceptionWhenNextAnketaMissingSealedKeys(?string $myKey, ?string $counterpartKey): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $service = $this->createService();

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/Next anketa requires sealed keys\./');

        $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
            mySealedKey: $myKey,
            counterpartSealedKey: $counterpartKey,
        );
    }

    public function testShouldCreateNext(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $service = $this->createService();

        self::assertTrue($service->shouldCreateNext($anketa, false));
        self::assertFalse($service->shouldCreateNext($anketa, true));

        $this->employee->setBlocked(true);
        self::assertFalse($service->shouldCreateNext($anketa, false));
    }

    public function testShouldCreateNextIsFalseForAOneOffAnketa(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
            oneOff: true,
        );

        self::assertFalse($this->createService()->shouldCreateNext($anketa, false));
    }

    public function testArchiveThrowsExceptionWhenNextAnketaMissingPeriodicity(): void
    {
        $anketa = new Anketa(
            employee: $this->employee,
            manager: $this->manager,
            meetingDate: new \DateTimeImmutable('2026-09-01 10:00:00'),
            employeeSealedKey: 'emp-key',
            managerSealedKey: 'mgr-key',
            periodicityDays: 14,
        );

        $reflection = new \ReflectionProperty(Anketa::class, 'periodicityDays');
        $reflection->setValue($anketa, null);

        // Checked before the transaction opens — a throw inside wrapInTransaction()
        // would close the EntityManager.
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $service = $this->createService(entityManager: $entityManager);

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessageMatches('/Next anketa requires periodicity\./');

        $service->archive(
            anketa: $anketa,
            actor: $this->employee,
            missed: false,
            skipNextMeeting: false,
            mySealedKey: 'emp-key',
            counterpartSealedKey: 'mgr-key',
        );
    }
}
