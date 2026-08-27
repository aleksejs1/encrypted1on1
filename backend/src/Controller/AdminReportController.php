<?php

namespace App\Controller;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\Goal;
use App\Entity\User;
use App\Report\AnketaSummaryForReport;
use App\Report\GoalsAggregator;
use App\Report\GoalSnapshotForReport;
use App\Report\OverviewAggregator;
use App\Security\AuthSession;
use App\Security\RequiresCompanyAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Company admin reporting (private/company-admin-reporting-proposal.md — draft,
 * Phases 1–2 so far: the company-wide Overview report and the Goals report).
 * Every route here is admin-only and company-scoped, same requireAdmin() shape
 * as AdminController (RequiresCompanyAdmin, shared by both).
 *
 * Every endpoint here is read-only and built exclusively from columns that are
 * already plaintext today (the proposal's own §2 accounting) — no new plaintext
 * exception, nothing here ever returns anketa content, comments, outcomes, goal
 * checkpoint text, or another user's goal title/description.
 */
class AdminReportController
{
    use RequiresCompanyAdmin;

    /**
     * ~5 years — generous enough for "since company creation" on any real deployment,
     * small enough to keep the trend chart's month-bucketing array and the anketa query
     * bounded. Does *not* bound the goal query below — that one is deliberately
     * unfiltered by date regardless of the requested range (see its own comment) — this
     * cap is only about the anketa side and the derived month array.
     */
    private const int MAX_RANGE_DAYS = 1826;

    /** The frontend's DateInput always sends this shape — a stricter check than "is this any string PHP's date parser accepts," which would otherwise also accept relative grammar like "now" or "+5 days". */
    private const string DATE_ONLY_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/admin/reports/overview', name: 'admin_reports_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);

        $range = $this->parseDateRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;

        $company = $admin->getCompany();

        // Every anketa in [from, to] — the meetings half of the report, plus the raw
        // material for the trend chart's "meetingsCompleted" series. Scalar/array
        // result, no entity hydration: this never touches employee/manager identity.
        /** @var list<array{meetingDate: \DateTimeImmutable, archivedAt: ?\DateTimeImmutable, missed: bool, employeePublishedAt: ?\DateTimeImmutable, managerPublishedAt: ?\DateTimeImmutable}> $anketaRows */
        $anketaRows = $this->entityManager->createQueryBuilder()
            ->select('a.meetingDate', 'a.archivedAt', 'a.missed', 'a.employeePublishedAt', 'a.managerPublishedAt')
            ->from(Anketa::class, 'a')
            ->innerJoin('a.employee', 'e')
            ->where('e.company = :company')
            ->andWhere('a.meetingDate BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()->getArrayResult();

        $anketas = array_map(
            static fn (array $row) => new AnketaSummaryForReport(
                meetingDate: $row['meetingDate'],
                archivedAt: $row['archivedAt'],
                missed: $row['missed'],
                employeePublishedAt: $row['employeePublishedAt'],
                managerPublishedAt: $row['managerPublishedAt'],
            ),
            $anketaRows,
        );

        // Every Goal row the company has ever had — deliberately unfiltered by date
        // range or status. See OverviewAggregator's own class docblock for why: the
        // goal numbers below each need a different slice of this same history, and
        // no single filtered query can provide all of them at once without silently
        // getting at least one wrong (most notably: a carried-forward goal snapshot
        // landing inside the range would otherwise double-count as newly created).
        $goalSnapshots = $this->fetchGoalSnapshots($company);

        // Current headcount — not range-filtered, same reasoning as SeatLimitChecker's
        // own count: "how many people are actually using this right now," not a
        // historical count for the requested window.
        /** @var list<array{isBlocked: bool, isAdmin: bool}> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u.isBlocked', 'u.isAdmin')
            ->from(User::class, 'u')
            ->where('u.company = :company')
            ->andWhere('u.deletedAt IS NULL')
            ->setParameter('company', $company)
            ->getQuery()->getArrayResult();

        $report = OverviewAggregator::aggregate($anketas, $goalSnapshots, $users, $from, $to, new \DateTimeImmutable());

        return new JsonResponse($report);
    }

    /**
     * The dedicated Goals tab (proposal §7.2/§8.2, Phase 2) — a focused view of the
     * same goal-snapshot history as overview()'s "Goals" section, plus a cancelled
     * count and its own achieved-per-month trend. Same admin-only/company-scoped
     * shape, same DTO/aggregator split.
     */
    #[Route('/api/admin/reports/goals', name: 'admin_reports_goals', methods: ['GET'])]
    public function goals(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);

        $range = $this->parseDateRange($request);
        if ($range instanceof JsonResponse) {
            return $range;
        }
        [$from, $to] = $range;

        $goalSnapshots = $this->fetchGoalSnapshots($admin->getCompany());

        $report = GoalsAggregator::aggregate($goalSnapshots, $from, $to, new \DateTimeImmutable());

        return new JsonResponse($report);
    }

    /**
     * Every Goal row the company has ever had — deliberately unfiltered by date range
     * or status. See OverviewAggregator's own class docblock for why: the goal
     * numbers derived from this each need a different slice of this same history,
     * and no single filtered query can provide all of them at once. Shared by
     * overview() and goals() — both aggregators consume the identical snapshot list.
     *
     * @return GoalSnapshotForReport[]
     */
    private function fetchGoalSnapshots(Company $company): array
    {
        /** @var list<array{goalUuid: string, status: string, createdAt: \DateTimeImmutable, targetDate: ?\DateTimeImmutable, anketaMeetingDate: \DateTimeImmutable, employeeDeletedAt: ?\DateTimeImmutable, managerDeletedAt: ?\DateTimeImmutable}> $goalRows */
        $goalRows = $this->entityManager->createQueryBuilder()
            ->select('g.goalUuid', 'g.status', 'g.createdAt', 'g.targetDate', 'a.meetingDate AS anketaMeetingDate', 'e.deletedAt AS employeeDeletedAt', 'm.deletedAt AS managerDeletedAt')
            ->from(Goal::class, 'g')
            ->innerJoin('g.anketa', 'a')
            ->innerJoin('a.employee', 'e')
            ->innerJoin('a.manager', 'm')
            ->where('e.company = :company')
            ->setParameter('company', $company)
            ->getQuery()->getArrayResult();

        return array_map(
            static fn (array $row) => new GoalSnapshotForReport(
                goalUuid: $row['goalUuid'],
                status: $row['status'],
                createdAt: $row['createdAt'],
                targetDate: $row['targetDate'],
                anketaMeetingDate: $row['anketaMeetingDate'],
                bothParticipantsActive: null === $row['employeeDeletedAt'] && null === $row['managerDeletedAt'],
            ),
            $goalRows,
        );
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|JsonResponse a [from, to] pair (to = end of that day), or a 400 response. */
    private function parseDateRange(Request $request): array|JsonResponse
    {
        $from = $request->query->get('from');
        $to = $request->query->get('to');
        if (!\is_string($from) || null === ($fromDate = self::parseDateOnly($from))) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'from'])], 400);
        }
        if (!\is_string($to) || null === ($toDate = self::parseDateOnly($to))) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'to'])], 400);
        }
        // End of that calendar day — an admin picking "to: 2026-08-25" expects that
        // whole day included, same inclusive-range reasoning Report.svelte's own
        // handleGenerate() already applies client-side to its own range.
        $toDate = $toDate->setTime(23, 59, 59, 999999);

        if ($fromDate > $toDate) {
            return new JsonResponse(['error' => $this->translator->trans('errors.report_from_after_to')], 400);
        }
        if ($fromDate->diff($toDate)->days > self::MAX_RANGE_DAYS) {
            return new JsonResponse(['error' => $this->translator->trans('errors.report_range_too_wide', ['%maxDays%' => self::MAX_RANGE_DAYS])], 400);
        }

        return [$fromDate, $toDate];
    }

    /**
     * Strict "is this a real calendar date in exactly YYYY-MM-DD shape" — rejects both
     * a shape mismatch and PHP's own leniency about out-of-range values (the
     * DateTimeImmutable constructor alone would silently roll "2026-02-30" over to
     * 2026-03-02 rather than reject it, the same overflow risk
     * frontend/src/dateFormat.ts's own parseDate() guards against for exactly this
     * reason). Also incidentally the fix for a looser check: without the shape/checkdate
     * guard, `new DateTimeImmutable($value)` accepts PHP's full relative-date grammar
     * (e.g. "now", "+5 days"), which the actual date-picker UI never sends.
     */
    private static function parseDateOnly(string $value): ?\DateTimeImmutable
    {
        if (1 !== preg_match(self::DATE_ONLY_PATTERN, $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
