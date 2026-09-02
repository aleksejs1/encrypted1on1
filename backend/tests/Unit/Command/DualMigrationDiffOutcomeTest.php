<?php

namespace App\Tests\Unit\Command;

use App\Command\DualMigrationDiffOutcome;
use PHPUnit\Framework\TestCase;

class DualMigrationDiffOutcomeTest extends TestCase
{
    public function testBothSidesAlreadyUpToDate(): void
    {
        self::assertSame(DualMigrationDiffOutcome::BOTH_UP_TO_DATE, DualMigrationDiffOutcome::classify([], []));
    }

    public function testBothSidesGeneratedANewMigration(): void
    {
        self::assertSame(
            DualMigrationDiffOutcome::BOTH_GENERATED,
            DualMigrationDiffOutcome::classify(['Version1.php'], ['Version1.php']),
        );
    }

    public function testOnlySqliteGeneratedIsDrift(): void
    {
        self::assertSame(
            DualMigrationDiffOutcome::DRIFT,
            DualMigrationDiffOutcome::classify(['Version1.php'], []),
        );
    }

    public function testOnlyMysqlGeneratedIsDrift(): void
    {
        self::assertSame(
            DualMigrationDiffOutcome::DRIFT,
            DualMigrationDiffOutcome::classify([], ['Version1.php']),
        );
    }

    public function testFirstNonEmptyPicksTheEarliestUsableCandidate(): void
    {
        self::assertSame('a', DualMigrationDiffOutcome::firstNonEmpty('a', 'b'));
        self::assertSame('b', DualMigrationDiffOutcome::firstNonEmpty(null, 'b'));
        self::assertSame('b', DualMigrationDiffOutcome::firstNonEmpty('', 'b'));
        self::assertSame('c', DualMigrationDiffOutcome::firstNonEmpty(null, '', 'c'));
    }

    public function testFirstNonEmptyReturnsNullWhenNothingIsUsable(): void
    {
        self::assertNull(DualMigrationDiffOutcome::firstNonEmpty());
        self::assertNull(DualMigrationDiffOutcome::firstNonEmpty(null, ''));
    }
}
