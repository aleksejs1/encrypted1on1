<?php

namespace App\Anketa;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use App\Repository\AnketaRepository;
use App\Repository\GoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Domain orchestration service managing anketa lifecycle transitions:
 * creation with goal carry-forward, archiving meetings with automatic
 * next-cycle creation, counterpart key re-sharing, and draft/publish/answer updates.
 */
class AnketaLifecycleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GoalRepository $goalRepository,
        private readonly AnketaRepository $anketaRepository,
        private readonly AnketaNotifier $notifier,
    ) {
    }

    /**
     * Builds a new Anketa (optionally seeded with a client-carried outcomesBlob) and
     * copies in_progress goals from $carryFrom into it, if given — except for a one-off,
     * which never gets a carry-forward (GitHub issue #111, see Anketa::$oneOff): the
     * pair's open chain anketa already has it, and a second copy would just diverge.
     * Enforced here rather than by callers, so no caller can bring the duplicates back.
     * Shared by create() and archive()'s auto-recreation. Persists the new Anketa and any copied Goals;
     * does not flush, callers do that once after whatever else they need to persist.
     */
    public function createWithCarryForward(
        User $employee,
        User $manager,
        \DateTimeImmutable $meetingDate,
        string $employeeSealedKey,
        string $managerSealedKey,
        int $periodicityDays,
        ?string $outcomesBlob = null,
        ?Anketa $carryFrom = null,
        string $templateKey = Anketa::DEFAULT_TEMPLATE_KEY,
        bool $oneOff = false,
    ): Anketa {
        if ($oneOff) {
            $outcomesBlob = null;
            $carryFrom = null;
        }

        $anketa = new Anketa(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $employeeSealedKey,
            managerSealedKey: $managerSealedKey,
            periodicityDays: $periodicityDays,
            templateKey: $templateKey,
            oneOff: $oneOff,
        );

        if (null !== $outcomesBlob) {
            $anketa->seedOutcomes($outcomesBlob);
        }

        $this->entityManager->persist($anketa);

        if (null !== $carryFrom) {
            foreach ($this->goalRepository->findInProgressForAnketa($carryFrom) as $previousGoal) {
                $this->entityManager->persist(new Goal(
                    goalUuid: $previousGoal->getGoalUuid(),
                    anketa: $anketa,
                    author: $previousGoal->getAuthor(),
                    title: $previousGoal->getTitle(),
                    description: $previousGoal->getDescription(),
                    targetDate: $previousGoal->getTargetDate(),
                    status: Goal::STATUS_IN_PROGRESS,
                ));
            }
        }

        return $anketa;
    }

    /**
     * Creates an anketa with carry-forward, flushes the entity manager, and notifies
     * the counterpart user if a creator is provided.
     */
    public function createAnketa(
        User $employee,
        User $manager,
        \DateTimeImmutable $meetingDate,
        string $employeeSealedKey,
        string $managerSealedKey,
        int $periodicityDays,
        ?string $outcomesBlob = null,
        ?Anketa $carryFrom = null,
        ?User $creator = null,
        string $templateKey = Anketa::DEFAULT_TEMPLATE_KEY,
        bool $oneOff = false,
    ): Anketa {
        $anketa = $this->createWithCarryForward(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $employeeSealedKey,
            managerSealedKey: $managerSealedKey,
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            carryFrom: $carryFrom,
            templateKey: $templateKey,
            oneOff: $oneOff,
        );

        $this->entityManager->flush();

        if (null !== $creator) {
            $counterpart = $anketa->isEmployee($creator) ? $anketa->getManager() : $anketa->getEmployee();
            $this->notifier->notifyAnketaCreated($anketa, $counterpart, $creator);
        }

        return $anketa;
    }

    /**
     * Archives an anketa and, unless skipped, it's a one-off, or either participant is
     * blocked (see shouldCreateNext()), auto-recreates
     * the subsequent anketa with carried-forward uncompleted goals, flushes, and notifies the counterpart.
     *
     * The archive itself goes through AnketaRepository::markArchivedIfOpen(), in the same
     * transaction as the successor's insert, so of two concurrent archive requests only
     * one creates a successor (GitHub issue #130); the other gets
     * AnketaAlreadyArchivedException and changes nothing.
     *
     * @throws AnketaAlreadyArchivedException
     */
    public function archive(
        Anketa $anketa,
        User $actor,
        bool $missed,
        bool $skipNextMeeting,
        ?\DateTimeImmutable $nextMeetingDate = null,
        ?string $mySealedKey = null,
        ?string $counterpartSealedKey = null,
        ?string $outcomesBlob = null,
    ): ?Anketa {
        $nextPeriodicityDays = $this->nextAnketaPeriodicity($anketa, $skipNextMeeting, $mySealedKey, $counterpartSealedKey);

        // The callback returns false (never throws) when the anketa was already
        // archived — same reason as InviteController::create(): wrapInTransaction()
        // closes the EntityManager on any exception.
        $nextAnketa = $this->entityManager->wrapInTransaction(function () use (
            $anketa, $actor, $missed, $nextPeriodicityDays, $nextMeetingDate, $mySealedKey, $counterpartSealedKey, $outcomesBlob,
        ): Anketa|false|null {
            $archivedAt = new \DateTimeImmutable();
            // Must stay the transaction's first statement. On SQLite (WAL), a deferred
            // transaction that has already read and then tries to write fails at once
            // with SQLITE_BUSY instead of waiting out busy_timeout, so the losing
            // request would get a 500 rather than this clean "already archived".
            if (!$this->anketaRepository->markArchivedIfOpen($anketa, $archivedAt, $missed)) {
                return false;
            }
            // Re-read rather than calling $anketa->archive(): the row is the source of
            // truth now, and a refresh also keeps the unit of work from writing the same
            // columns a second time on flush.
            $this->entityManager->refresh($anketa);

            if (null === $nextPeriodicityDays) {
                return null;
            }
            // Checked by nextAnketaPeriodicity() above; parameters, so not re-read.
            \assert(null !== $mySealedKey && null !== $counterpartSealedKey);

            return $this->createNextAnketa(
                $anketa,
                $actor,
                $archivedAt,
                $nextPeriodicityDays,
                $nextMeetingDate,
                $mySealedKey,
                $counterpartSealedKey,
                $outcomesBlob,
            );
        });

        if (false === $nextAnketa) {
            throw new AnketaAlreadyArchivedException();
        }

        if (null !== $nextAnketa) {
            $nextRecipient = $anketa->isEmployee($actor) ? $anketa->getManager() : $anketa->getEmployee();
            $this->notifier->notifyAnketaCreated($nextAnketa, $nextRecipient, $actor);
        }

        return $nextAnketa;
    }

    public function shouldCreateNext(Anketa $anketa, bool $skipNextMeeting): bool
    {
        // Closes the Phase 6d deferred item: if either participant is now blocked
        // (Phase 6g), auto-recreation stops for this pair — same effect as
        // skipNextMeeting, but forced, regardless of what the client asked for. A one-off
        // anketa (GitHub issue #111, see Anketa::$oneOff) is forced the same way — it
        // never recreates itself, or the pair's chain would fork into two.
        if ($skipNextMeeting || $anketa->isOneOff()) {
            return false;
        }

        return !$anketa->getEmployee()->isBlocked() && !$anketa->getManager()->isBlocked();
    }

    /**
     * The successor's periodicity if archive() should create one (see
     * shouldCreateNext()), after checking that everything it needs for one is there;
     * null if no successor is due.
     *
     * AnketaController::archive() already returns a translated 400 for a missing
     * periodicity or sealed key, so these throws are defensive re-checks for any future
     * caller that skips that pre-check. They must surface as a 400 (via
     * JsonExceptionListener's HttpExceptionInterface handling), not an opaque
     * untranslated 500 the way a bare \InvalidArgumentException would — and must run
     * before archive()'s transaction, since wrapInTransaction() closes the
     * EntityManager on any exception.
     */
    private function nextAnketaPeriodicity(Anketa $anketa, bool $skipNextMeeting, ?string $mySealedKey, ?string $counterpartSealedKey): ?int
    {
        if (!$this->shouldCreateNext($anketa, $skipNextMeeting)) {
            return null;
        }
        $periodicityDays = $anketa->getPeriodicityDays();
        if (null === $periodicityDays) {
            throw new BadRequestHttpException('Next anketa requires periodicity.');
        }
        if (null === $mySealedKey || null === $counterpartSealedKey) {
            throw new BadRequestHttpException('Next anketa requires sealed keys.');
        }

        return $periodicityDays;
    }

    private function createNextAnketa(
        Anketa $anketa,
        User $actor,
        \DateTimeImmutable $archivedAt,
        int $periodicityDays,
        ?\DateTimeImmutable $nextMeetingDate,
        string $mySealedKey,
        string $counterpartSealedKey,
        ?string $outcomesBlob,
    ): Anketa {
        $isEmployee = $anketa->isEmployee($actor);

        // Deliberately NOT $anketa->getTemplateKey() here, unlike periodicityDays two
        // lines below — a template choice should not blindly carry forward the way
        // periodicity does: a non-recurring template auto-recreating itself forever
        // would be wrong. Anketa::nextCycleTemplateKeyFor() looks up the per-template
        // recurrence rule (see its own docblock) — see AnketaLifecycleServiceTest::
        // testArchiveWithNextMeetingUsesNextCycleTemplateKeyMap.
        return $this->createWithCarryForward(
            employee: $anketa->getEmployee(),
            manager: $anketa->getManager(),
            meetingDate: $nextMeetingDate ?? $archivedAt->modify(sprintf('+%d days', $periodicityDays)),
            employeeSealedKey: $isEmployee ? $mySealedKey : $counterpartSealedKey,
            managerSealedKey: $isEmployee ? $counterpartSealedKey : $mySealedKey,
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            carryFrom: $anketa,
            templateKey: Anketa::nextCycleTemplateKeyFor($anketa->getTemplateKey()),
        );
    }

    /**
     * Restores a counterpart's access after their public key changed (most commonly a
     * password reset — password-reset plan, part 2). The caller must already have a
     * working copy of the anketa key (their own side is unaffected) and does the actual
     * unseal/reseal client-side; this just stores the result for the other participant's
     * side, never the caller's own.
     */
    public function reshareKey(Anketa $anketa, User $actor, string $sealedKey): void
    {
        $counterpart = $anketa->isEmployee($actor) ? $anketa->getManager() : $anketa->getEmployee();
        $anketa->resealKeyFor($counterpart, $sealedKey);
        $this->entityManager->flush();
    }

    public function saveDraft(Anketa $anketa, User $user, string $blob): void
    {
        $anketa->saveDraft($user, $blob);
        $this->entityManager->flush();
    }

    public function publish(Anketa $anketa, User $user, string $blob): void
    {
        $anketa->publish($user, $blob);
        $this->entityManager->flush();
    }

    /**
     * Updates an already-published participant's answers with optimistic concurrency check.
     * Returns true if updated and flushed, or false on concurrency conflict.
     */
    public function updatePublishedAnswers(Anketa $anketa, User $user, string $blob, int $expectedVersion): bool
    {
        $updated = $anketa->updateAnswers($user, $blob, $expectedVersion);
        if ($updated) {
            $this->entityManager->flush();
        }

        return $updated;
    }
}
