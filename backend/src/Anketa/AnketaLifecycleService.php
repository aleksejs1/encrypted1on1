<?php

namespace App\Anketa;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\Goal;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use App\Repository\GoalRepository;
use Doctrine\ORM\EntityManagerInterface;

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
        private readonly AnketaNotifier $notifier,
    ) {
    }

    /**
     * Builds a new Anketa (optionally seeded with a client-carried outcomesBlob) and
     * copies in_progress goals from $carryFrom into it, if given. Shared by create()
     * and archive()'s auto-recreation. Persists the new Anketa and any copied Goals;
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
        ?Company $company = null,
    ): Anketa {
        $anketa = new Anketa(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $employeeSealedKey,
            managerSealedKey: $managerSealedKey,
            periodicityDays: $periodicityDays,
            company: $company ?? $employee->getCompany(),
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
        ?Company $company = null,
        ?User $creator = null,
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
            company: $company,
        );

        $this->entityManager->flush();

        if (null !== $creator) {
            $counterpart = $anketa->isEmployee($creator) ? $anketa->getManager() : $anketa->getEmployee();
            $this->notifier->notifyAnketaCreated($anketa, $counterpart, $creator);
        }

        return $anketa;
    }

    /**
     * Archives an anketa and, unless skipped or either participant is blocked, auto-recreates
     * the subsequent anketa with carried-forward uncompleted goals, flushes, and notifies the counterpart.
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
        $anketa->archive($missed);
        $archivedAt = $anketa->getArchivedAt();
        \assert(null !== $archivedAt);

        $nextAnketa = null;
        if ($this->shouldCreateNext($anketa, $skipNextMeeting)) {
            if (null === $mySealedKey || null === $counterpartSealedKey) {
                throw new \InvalidArgumentException('Next anketa requires sealed keys.');
            }

            $nextAnketa = $this->createNextAnketa(
                $anketa,
                $actor,
                $archivedAt,
                $nextMeetingDate,
                $mySealedKey,
                $counterpartSealedKey,
                $outcomesBlob,
            );
        }

        $this->entityManager->flush();

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
        // skipNextMeeting, but forced, regardless of what the client asked for.
        if ($skipNextMeeting) {
            return false;
        }

        return !$anketa->getEmployee()->isBlocked() && !$anketa->getManager()->isBlocked();
    }

    private function createNextAnketa(
        Anketa $anketa,
        User $actor,
        \DateTimeImmutable $archivedAt,
        ?\DateTimeImmutable $nextMeetingDate,
        string $mySealedKey,
        string $counterpartSealedKey,
        ?string $outcomesBlob,
    ): Anketa {
        $periodicityDays = $anketa->getPeriodicityDays();
        if (null === $periodicityDays) {
            throw new \InvalidArgumentException('Next anketa requires periodicity.');
        }

        $isEmployee = $anketa->isEmployee($actor);

        return $this->createWithCarryForward(
            employee: $anketa->getEmployee(),
            manager: $anketa->getManager(),
            meetingDate: $nextMeetingDate ?? $archivedAt->modify(sprintf('+%d days', $periodicityDays)),
            employeeSealedKey: $isEmployee ? $mySealedKey : $counterpartSealedKey,
            managerSealedKey: $isEmployee ? $counterpartSealedKey : $mySealedKey,
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            carryFrom: $anketa,
            company: $anketa->getCompany(),
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
