<?php

namespace App\Anketa;

use App\Entity\Anketa;
use App\Entity\CustomTemplateVersion;
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
     * Builds a new Anketa (optionally seeded with a client-carried outcomesBlob and
     * topicsBlob) and copies in_progress goals from $carryFrom into it, if given — except for a one-off,
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
        ?string $topicsBlob = null,
        ?Anketa $carryFrom = null,
        string $templateKey = Anketa::DEFAULT_TEMPLATE_KEY,
        bool $oneOff = false,
        ?CustomTemplateVersion $customTemplateVersion = null,
    ): Anketa {
        if ($oneOff) {
            $outcomesBlob = null;
            $topicsBlob = null;
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
            customTemplateVersion: $customTemplateVersion,
        );

        if (null !== $outcomesBlob) {
            $anketa->seedOutcomes($outcomesBlob);
        }
        if (null !== $topicsBlob) {
            $anketa->seedTopics($topicsBlob);
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
        ?string $topicsBlob = null,
        ?Anketa $carryFrom = null,
        ?User $creator = null,
        string $templateKey = Anketa::DEFAULT_TEMPLATE_KEY,
        bool $oneOff = false,
        ?CustomTemplateVersion $customTemplateVersion = null,
    ): Anketa {
        $anketa = $this->createWithCarryForward(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $employeeSealedKey,
            managerSealedKey: $managerSealedKey,
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            topicsBlob: $topicsBlob,
            carryFrom: $carryFrom,
            templateKey: $templateKey,
            oneOff: $oneOff,
            customTemplateVersion: $customTemplateVersion,
        );

        $this->entityManager->flush();

        if (null !== $creator) {
            $counterpart = $anketa->counterpartOf($creator);
            $this->notifier->notifyAnketaCreated($anketa, $counterpart, $creator);
        }

        return $anketa;
    }

    /**
     * Archives an anketa and, unless skipped, it's a one-off, or either participant is
     * blocked (see shouldCreateNext()), auto-recreates
     * the subsequent anketa with carried-forward uncompleted goals, flushes, and notifies the counterpart.
     *
     * $nextTemplateKey is the successor's template, already resolved by the caller
     * (the user's "Next meeting type" choice, or defaultNextTemplate()) before anything
     * is mutated — resolving it here would be too late for a check that has to leave
     * the anketa unarchived when it fails. Required whenever a successor is created;
     * ignored otherwise. So is $nextCustomTemplateVersion, the company template's
     * version the caller resolved for a 'custom' key (GitHub issue #144); the
     * successor's constructor refuses a key and version that don't go together.
     *
     * $swapRolesNext creates the successor with the two roles swapped (GitHub issue
     * #254); the anketa being archived keeps its own. Ignored without a successor.
     *
     * The archive itself goes through AnketaRepository::markArchivedIfOpen(), in the same
     * transaction as the successor's insert, so of two concurrent archive requests only
     * one creates a successor (GitHub issue #130); the other gets
     * AnketaAlreadyArchivedException and changes nothing.
     *
     * @throws AnketaAlreadyArchivedException
     * @throws AnketaTopicsChangedException   if `$expectedTopicsVersion` is given and the
     *                                        topics list is no longer at it; nothing is archived
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
        ?string $topicsBlob = null,
        ?string $nextTemplateKey = null,
        ?CustomTemplateVersion $nextCustomTemplateVersion = null,
        ?int $expectedTopicsVersion = null,
        bool $swapRolesNext = false,
    ): ?Anketa {
        $nextPeriodicityDays = $this->nextAnketaPeriodicity($anketa, $skipNextMeeting, $mySealedKey, $counterpartSealedKey);
        // A programming error, not a fallback: AnketaController::archive() always
        // resolves one. Thrown before the transaction, for nextAnketaPeriodicity()'s reason.
        if (null !== $nextPeriodicityDays && null === $nextTemplateKey) {
            throw new BadRequestHttpException('Next 1:1 requires a template key.');
        }

        // The callback returns false (never throws) when the anketa was already
        // archived — same reason as InviteController::create(): wrapInTransaction()
        // closes the EntityManager on any exception.
        $nextAnketa = $this->entityManager->wrapInTransaction(function () use (
            $anketa, $actor, $missed, $nextPeriodicityDays, $nextMeetingDate, $mySealedKey, $counterpartSealedKey, $outcomesBlob, $topicsBlob, $nextTemplateKey, $nextCustomTemplateVersion, $expectedTopicsVersion, $swapRolesNext,
        ): Anketa|false|null {
            $archivedAt = new \DateTimeImmutable();
            // Must stay the transaction's first statement. On SQLite (WAL), a deferred
            // transaction that has already read and then tries to write fails at once
            // with SQLITE_BUSY instead of waiting out busy_timeout, so the losing
            // request would get a 500 rather than this clean "already archived".
            if (!$this->anketaRepository->markArchivedIfOpen($anketa, $archivedAt, $missed, $expectedTopicsVersion)) {
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
            // ($nextTemplateKey's check above already narrows it for PHPStan.)
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
                $topicsBlob,
                $nextTemplateKey,
                $nextCustomTemplateVersion,
                $swapRolesNext,
            );
        });

        if (false === $nextAnketa) {
            throw $this->archiveRefusal($anketa, $expectedTopicsVersion);
        }

        if (null !== $nextAnketa) {
            $nextRecipient = $anketa->counterpartOf($actor);
            $this->notifier->notifyAnketaCreated($nextAnketa, $nextRecipient, $actor);
        }

        return $nextAnketa;
    }

    /**
     * Moves the meeting and, if that changed its day, emails the counterpart the new date
     * (GitHub issue #200). The emails show a date only, so a move within the same day
     * would send "moved from X to X". A blocked counterpart (a deleted account is one)
     * can't open the meeting, and gets nothing.
     */
    public function reschedule(Anketa $anketa, User $actor, \DateTimeImmutable $meetingDate): void
    {
        $previousDate = $anketa->getMeetingDate();
        $anketa->reschedule($meetingDate);
        $this->entityManager->flush();

        $counterpart = $anketa->counterpartOf($actor);
        if ($previousDate->format('Y-m-d') !== $anketa->getMeetingDate()->format('Y-m-d') && !$counterpart->isBlocked()) {
            $this->notifier->notifyMeetingRescheduled($anketa, $counterpart, $actor, $previousDate);
        }
    }

    /**
     * The template the successor of $anketa gets when nobody picks a different one at
     * archive (GitHub issue #140): the per-template recurrence map,
     * Anketa::nextCycleTemplateKeyFor(), which is now only the default — the "Next
     * meeting type" picker can override it. A custom anketa (GitHub issue #144, #133
     * §7.4) recurs on its company template, as the template's id rather than this
     * anketa's version, so the successor gets the template's latest version; once an
     * admin has archived the template, the default is Regular instead. Null for a
     * one-off, which never has a successor. Deliberately doesn't check the anketa's
     * archived state: archive() needs it before the anketa is archived, and
     * AnketaPresenter emits null for an archived anketa itself. The one place this rule
     * lives, for both the presenter's next-template fields and
     * AnketaController::archive().
     *
     * @return array{key: string, customTemplateId: string|null}|null
     */
    public function defaultNextTemplate(Anketa $anketa): ?array
    {
        if ($anketa->isOneOff()) {
            return null;
        }

        $version = $anketa->getCustomTemplateVersion();
        if (null !== $version) {
            $template = $version->getTemplate();

            return $template->isArchived()
                ? ['key' => Anketa::DEFAULT_TEMPLATE_KEY, 'customTemplateId' => null]
                : ['key' => Anketa::CUSTOM_TEMPLATE_KEY, 'customTemplateId' => $template->getId()];
        }

        return ['key' => Anketa::nextCycleTemplateKeyFor($anketa->getTemplateKey()), 'customTemplateId' => null];
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
     * Why archive()'s conditional UPDATE matched nothing. With a topics version named
     * it may be that the list moved on rather than that the anketa is archived, so the
     * row is re-read to tell. (archive()'s transaction callback returned, so the
     * EntityManager is open.).
     */
    private function archiveRefusal(Anketa $anketa, ?int $expectedTopicsVersion): AnketaAlreadyArchivedException|AnketaTopicsChangedException
    {
        if (null !== $expectedTopicsVersion) {
            $this->entityManager->refresh($anketa);
            if (!$anketa->isArchived()) {
                return new AnketaTopicsChangedException();
            }
        }

        return new AnketaAlreadyArchivedException();
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
            throw new BadRequestHttpException('Next 1:1 requires periodicity.');
        }
        if (null === $mySealedKey || null === $counterpartSealedKey) {
            throw new BadRequestHttpException('Next 1:1 requires sealed keys.');
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
        ?string $topicsBlob,
        string $nextTemplateKey,
        ?CustomTemplateVersion $nextCustomTemplateVersion,
        bool $swapRoles,
    ): Anketa {
        // With $swapRoles the successor's employee is this anketa's manager and the
        // other way round (GitHub issue #254). The request's sealed keys are per
        // person, so they follow the people, not the roles. Nothing else carried
        // forward depends on a role: goals, outcomes and topics name their author.
        $counterpart = $anketa->counterpartOf($actor);
        $actorIsNextEmployee = $anketa->isEmployee($actor) !== $swapRoles;

        // Uses only the template key it's given (see archive()), never
        // $anketa->getTemplateKey() — a template choice doesn't blindly carry forward
        // the way periodicity does. The default comes from defaultNextTemplate().
        return $this->createWithCarryForward(
            employee: $actorIsNextEmployee ? $actor : $counterpart,
            manager: $actorIsNextEmployee ? $counterpart : $actor,
            meetingDate: $nextMeetingDate ?? $archivedAt->modify(sprintf('+%d days', $periodicityDays)),
            employeeSealedKey: $actorIsNextEmployee ? $mySealedKey : $counterpartSealedKey,
            managerSealedKey: $actorIsNextEmployee ? $counterpartSealedKey : $mySealedKey,
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            topicsBlob: $topicsBlob,
            carryFrom: $anketa,
            templateKey: $nextTemplateKey,
            customTemplateVersion: $nextCustomTemplateVersion,
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
        $counterpart = $anketa->counterpartOf($actor);
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
