<?php

namespace App\Command;

/**
 * Pure classification of a dual-migration diff result, extracted out of
 * MakeDualMigrationCommand specifically so the tool's actual reason for existing —
 * telling "both sides caught up together" apart from "one side was already out of
 * sync before this ran" — has real, fast unit test coverage instead of only ever
 * being exercised by hand against real SQLite/MySQL infrastructure.
 */
final class DualMigrationDiffOutcome
{
    public const string BOTH_UP_TO_DATE = 'both_up_to_date';
    public const string BOTH_GENERATED = 'both_generated';
    public const string DRIFT = 'drift';

    /**
     * @param list<string> $sqliteNew new migration filenames generated on the SQLite side
     * @param list<string> $mysqlNew  new migration filenames generated on the MySQL side
     */
    public static function classify(array $sqliteNew, array $mysqlNew): string
    {
        if ([] === $sqliteNew && [] === $mysqlNew) {
            return self::BOTH_UP_TO_DATE;
        }

        if (([] === $sqliteNew) !== ([] === $mysqlNew)) {
            return self::DRIFT;
        }

        return self::BOTH_GENERATED;
    }

    /** First non-null, non-empty-string candidate, in order — used to resolve --mysql-url against its $MYSQL_DATABASE_URL fallback. */
    public static function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (null !== $candidate && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }
}
