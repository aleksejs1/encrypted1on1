<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Anketa;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class AnketaTest extends TestCase
{
    private function makeAnketa(): Anketa
    {
        $employee = new User('employee@example.com', 'hash', 'pub', 'enc');
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc');

        return new Anketa($employee, $manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30);
    }

    public function testSaveCommentsSucceedsOnMatchingVersionAndIncrements(): void
    {
        $anketa = $this->makeAnketa();

        self::assertTrue($anketa->saveComments('blob-v1', 0));
        self::assertSame('blob-v1', $anketa->getCommentsBlob());
        self::assertSame(1, $anketa->getCommentsVersion());
    }

    public function testSaveCommentsFailsOnVersionMismatchAndLeavesStateUnchanged(): void
    {
        $anketa = $this->makeAnketa();
        $anketa->saveComments('blob-v1', 0);

        self::assertFalse($anketa->saveComments('blob-v2', 0)); // 0 is now stale
        self::assertSame('blob-v1', $anketa->getCommentsBlob());
        self::assertSame(1, $anketa->getCommentsVersion());
    }

    public function testSaveOutcomesSucceedsOnMatchingVersionAndIncrements(): void
    {
        $anketa = $this->makeAnketa();

        self::assertTrue($anketa->saveOutcomes('blob-v1', 0));
        self::assertSame('blob-v1', $anketa->getOutcomesBlob());
        self::assertSame(1, $anketa->getOutcomesVersion());
    }

    public function testSaveOutcomesFailsOnVersionMismatch(): void
    {
        $anketa = $this->makeAnketa();
        $anketa->saveOutcomes('blob-v1', 0);

        self::assertFalse($anketa->saveOutcomes('blob-v2', 0));
        self::assertSame(1, $anketa->getOutcomesVersion());
    }

    public function testSeedOutcomesSetsBlobWithoutTouchingVersion(): void
    {
        $anketa = $this->makeAnketa();

        $anketa->seedOutcomes('carried-forward-blob');

        self::assertSame('carried-forward-blob', $anketa->getOutcomesBlob());
        self::assertSame(0, $anketa->getOutcomesVersion());
    }

    public function testSaveGoalCheckpointsSucceedsOnMatchingVersionAndIncrements(): void
    {
        $anketa = $this->makeAnketa();

        self::assertTrue($anketa->saveGoalCheckpoints('blob-v1', 0));
        self::assertSame('blob-v1', $anketa->getGoalCheckpointsBlob());
        self::assertSame(1, $anketa->getGoalCheckpointsVersion());
    }

    public function testSaveGoalCheckpointsFailsOnVersionMismatch(): void
    {
        $anketa = $this->makeAnketa();
        $anketa->saveGoalCheckpoints('blob-v1', 0);

        self::assertFalse($anketa->saveGoalCheckpoints('blob-v2', 0));
        self::assertSame(1, $anketa->getGoalCheckpointsVersion());
    }

    public function testPublishSetsBlobAndPublishedAtForTheCorrectSideOnly(): void
    {
        $anketa = $this->makeAnketa();
        $employee = $anketa->getEmployee();

        $anketa->publish($employee, 'employee-blob');

        self::assertTrue($anketa->isPublished($employee));
        self::assertFalse($anketa->isPublished($anketa->getManager()));
        self::assertSame('employee-blob', $anketa->getEmployeeBlob());
        self::assertNull($anketa->getManagerBlob());
    }

    public function testSaveDraftDoesNotMarkAsPublished(): void
    {
        $anketa = $this->makeAnketa();
        $employee = $anketa->getEmployee();

        $anketa->saveDraft($employee, 'draft-blob');

        self::assertSame('draft-blob', $anketa->getEmployeeBlob());
        self::assertFalse($anketa->isPublished($employee));
    }

    public function testArchiveSetsArchivedAtAndMissedFlag(): void
    {
        $anketa = $this->makeAnketa();
        self::assertFalse($anketa->isArchived());

        $anketa->archive(missed: true);

        self::assertTrue($anketa->isArchived());
        self::assertTrue($anketa->isMissed());
    }

    public function testSealedKeyForReturnsTheRightSidesKey(): void
    {
        $anketa = $this->makeAnketa();

        self::assertSame('sealed-e', $anketa->sealedKeyFor($anketa->getEmployee()));
        self::assertSame('sealed-m', $anketa->sealedKeyFor($anketa->getManager()));
    }

    public function testIsParticipantIsFalseForAThirdUser(): void
    {
        $anketa = $this->makeAnketa();
        $stranger = new User('stranger@example.com', 'hash', 'pub', 'enc');

        self::assertFalse($anketa->isParticipant($stranger));
    }

    public function testSealedKeyUpdatedAtForStartsAtCreatedAtForBothSides(): void
    {
        $anketa = $this->makeAnketa();

        self::assertEquals($anketa->getCreatedAt(), $anketa->sealedKeyUpdatedAtFor($anketa->getEmployee()));
        self::assertEquals($anketa->getCreatedAt(), $anketa->sealedKeyUpdatedAtFor($anketa->getManager()));
    }

    public function testResealKeyForUpdatesOnlyTheTargetedSide(): void
    {
        $anketa = $this->makeAnketa();
        $employee = $anketa->getEmployee();
        $manager = $anketa->getManager();
        $managerUpdatedAtBefore = $anketa->sealedKeyUpdatedAtFor($manager);

        $anketa->resealKeyFor($employee, 'resealed-e');

        self::assertSame('resealed-e', $anketa->sealedKeyFor($employee));
        self::assertGreaterThan($anketa->getCreatedAt(), $anketa->sealedKeyUpdatedAtFor($employee));
        // The manager's side is untouched — same sealed key, same timestamp.
        self::assertSame('sealed-m', $anketa->sealedKeyFor($manager));
        self::assertEquals($managerUpdatedAtBefore, $anketa->sealedKeyUpdatedAtFor($manager));
    }
}
