<?php

namespace App\Report;

/**
 * One anketa's plaintext scheduling/completion metadata, as needed by
 * OverviewAggregator — deliberately decoupled from the Anketa entity (plain
 * readonly constructor, no Doctrine) so the aggregator can be unit-tested
 * against bare fixtures, same reasoning as the frontend's own
 * DecryptedAnketaForReport (frontend/src/anketa/report.ts). Never carries
 * employee/manager identity or any blob — see AdminReportController's own
 * query comment for why the query this is built from doesn't even fetch
 * those columns.
 */
final readonly class AnketaSummaryForReport
{
    public function __construct(
        public \DateTimeImmutable $meetingDate,
        public ?\DateTimeImmutable $archivedAt,
        public bool $missed,
        public ?\DateTimeImmutable $employeePublishedAt,
        public ?\DateTimeImmutable $managerPublishedAt,
    ) {
    }
}
