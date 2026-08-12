<?php

namespace App\Controller;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every route here has real per-side logic (ownership checks, one-way
 * publish) rather than generic CRUD — same reasoning as AuthController,
 * so this is a plain controller, not an API Platform resource. See the
 * Phase 5 plan for the crypto shape (sealed keys, draft-vs-published blob
 * states) this implements.
 */
class AnketaController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly AnketaNotifier $notifier,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/api/anketas', name: 'anketa_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $user = $this->requireUser($request);

        $body = $request->toArray();
        foreach (['counterpartId', 'myRole', 'meetingDate', 'mySealedKey', 'counterpartSealedKey'] as $field) {
            if (empty($body[$field]) || !\is_string($body[$field])) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => $field])], 400);
            }
        }
        if (!\in_array($body['myRole'], ['employee', 'manager'], true)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.invalid_role')], 400);
        }

        $counterpart = $this->entityManager->find(User::class, $body['counterpartId']);
        if (null === $counterpart) {
            return new JsonResponse(['error' => $this->translator->trans('errors.counterpart_not_found')], 404);
        }

        try {
            // The constructor (unlike createFromFormat(DATE_ATOM, ...)) accepts the
            // milliseconds + "Z" suffix that JS's Date.toISOString() actually produces.
            $meetingDate = new \DateTimeImmutable($body['meetingDate']);
        } catch (\Exception) {
            return new JsonResponse(['error' => $this->translator->trans('errors.meeting_date_must_be_valid_date')], 400);
        }

        $isEmployee = 'employee' === $body['myRole'];
        $employee = $isEmployee ? $user : $counterpart;
        $manager = $isEmployee ? $counterpart : $user;

        // Periodicity (Phase 6d) is set once, on a pair's first anketa, and inherited by
        // every later one — same "most recent anketa for this pair" lookup goal carry-forward
        // already needed (6c), reused here rather than a second query for the same concept.
        $previousAnketa = $this->findMostRecentArchivedAnketaForPair($employee, $manager);

        $periodicityDays = $previousAnketa?->getPeriodicityDays();
        if (null === $periodicityDays) {
            $periodicityDays = $body['periodicityDays'] ?? null;
            if (!\is_int($periodicityDays) || $periodicityDays < 1) {
                return new JsonResponse(['error' => $this->translator->trans('errors.periodicity_required')], 400);
            }
        }

        $outcomesBlob = $body['outcomesBlob'] ?? null;
        if (null !== $outcomesBlob && !\is_string($outcomesBlob)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.outcomes_blob_must_be_string')], 400);
        }

        $anketa = $this->createAnketaWithCarryForward(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $isEmployee ? $body['mySealedKey'] : $body['counterpartSealedKey'],
            managerSealedKey: $isEmployee ? $body['counterpartSealedKey'] : $body['mySealedKey'],
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            carryFrom: $previousAnketa,
        );

        $this->entityManager->flush();

        // Sent after the flush — only for an anketa that actually made it to the DB.
        // Best-effort (see AnketaNotifier); never blocks the response on a mail failure.
        $this->notifier->notifyAnketaCreated($anketa, $counterpart, $user);

        return new JsonResponse(['id' => $anketa->getId()], 201);
    }

    #[Route('/api/anketas', name: 'anketa_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(Anketa::class, 'a')
            ->where('a.employee = :user OR a.manager = :user')
            ->setParameter('user', $user)
            ->orderBy('a.meetingDate', 'DESC')
            ->getQuery()
            ->getResult();

        return new JsonResponse(array_map(fn (Anketa $anketa) => $this->summarize($anketa, $user), $anketas));
    }

    #[Route('/api/anketas/{id}', name: 'anketa_get', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        [$anketa, $user] = $this->findAccessible($id, $request);
        $counterpart = $anketa->isEmployee($user) ? $anketa->getManager() : $anketa->getEmployee();

        return new JsonResponse([
            ...$this->summarize($anketa, $user),
            'mySealedKey' => $anketa->sealedKeyFor($user),
            // Needed client-side to seal the auto-recreated next anketa's key on archive
            // (Phase 6d) without a separate /api/users round trip — public keys aren't secret.
            'counterpartPublicKey' => $counterpart->getPublicKey(),
            'employeeBlob' => $anketa->getEmployeeBlob(),
            'employeePublishedAt' => $anketa->getEmployeePublishedAt()?->format(\DATE_ATOM),
            'managerBlob' => $anketa->getManagerBlob(),
            'managerPublishedAt' => $anketa->getManagerPublishedAt()?->format(\DATE_ATOM),
            'commentsBlob' => $anketa->getCommentsBlob(),
            'commentsVersion' => $anketa->getCommentsVersion(),
            'outcomesBlob' => $anketa->getOutcomesBlob(),
            'outcomesVersion' => $anketa->getOutcomesVersion(),
            'goals' => array_map(
                fn (Goal $goal) => $this->serializeGoal($goal),
                $this->goalRepository()->findBy(['anketa' => $anketa]),
            ),
            'goalCheckpointsBlob' => $anketa->getGoalCheckpointsBlob(),
            'goalCheckpointsVersion' => $anketa->getGoalCheckpointsVersion(),
        ]);
    }

    #[Route('/api/anketas/{id}/comments', name: 'anketa_comments', methods: ['PUT'])]
    public function saveComments(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa] = $this->findAccessible($id, $request);

        $body = $request->toArray();
        $blob = $body['blob'] ?? null;
        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_string($blob) || !\is_int($expectedVersion)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob_or_expected_version')], 400);
        }

        if (!$anketa->saveComments($blob, $expectedVersion)) {
            // Conflict: hand back the current state so the client can merge without a second round-trip.
            return new JsonResponse([
                'error' => $this->translator->trans('errors.comments_conflict'),
                'commentsBlob' => $anketa->getCommentsBlob(),
                'commentsVersion' => $anketa->getCommentsVersion(),
            ], 409);
        }

        $this->entityManager->flush();

        return new JsonResponse(['commentsVersion' => $anketa->getCommentsVersion()]);
    }

    #[Route('/api/anketas/{id}/outcomes', name: 'anketa_outcomes', methods: ['PUT'])]
    public function saveOutcomes(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa] = $this->findAccessible($id, $request);

        $body = $request->toArray();
        $blob = $body['blob'] ?? null;
        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_string($blob) || !\is_int($expectedVersion)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob_or_expected_version')], 400);
        }

        if (!$anketa->saveOutcomes($blob, $expectedVersion)) {
            return new JsonResponse([
                'error' => $this->translator->trans('errors.outcomes_conflict'),
                'outcomesBlob' => $anketa->getOutcomesBlob(),
                'outcomesVersion' => $anketa->getOutcomesVersion(),
            ], 409);
        }

        $this->entityManager->flush();

        return new JsonResponse(['outcomesVersion' => $anketa->getOutcomesVersion()]);
    }

    #[Route('/api/anketas/{id}/goal-checkpoints', name: 'anketa_goal_checkpoints', methods: ['PUT'])]
    public function saveGoalCheckpoints(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa] = $this->findAccessible($id, $request);

        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $body = $request->toArray();
        $blob = $body['blob'] ?? null;
        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_string($blob) || !\is_int($expectedVersion)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob_or_expected_version')], 400);
        }

        if (!$anketa->saveGoalCheckpoints($blob, $expectedVersion)) {
            return new JsonResponse([
                'error' => $this->translator->trans('errors.goal_checkpoints_conflict'),
                'goalCheckpointsBlob' => $anketa->getGoalCheckpointsBlob(),
                'goalCheckpointsVersion' => $anketa->getGoalCheckpointsVersion(),
            ], 409);
        }

        $this->entityManager->flush();

        return new JsonResponse(['goalCheckpointsVersion' => $anketa->getGoalCheckpointsVersion()]);
    }

    #[Route('/api/anketas/{id}/goals', name: 'anketa_goal_create', methods: ['POST'])]
    public function createGoal(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $body = $request->toArray();
        foreach (['goalUuid', 'title'] as $field) {
            if (empty($body[$field]) || !\is_string($body[$field])) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => $field])], 400);
            }
        }
        $description = $body['description'] ?? null;
        if (null !== $description && !\is_string($description)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.description_must_be_string')], 400);
        }

        $targetDate = null;
        if (!empty($body['targetDate'])) {
            if (!\is_string($body['targetDate'])) {
                return new JsonResponse(['error' => $this->translator->trans('errors.target_date_must_be_string')], 400);
            }
            try {
                $targetDate = new \DateTimeImmutable($body['targetDate']);
            } catch (\Exception) {
                return new JsonResponse(['error' => $this->translator->trans('errors.target_date_must_be_valid_date')], 400);
            }
        }

        $goal = new Goal(
            goalUuid: $body['goalUuid'],
            anketa: $anketa,
            author: $user,
            title: $body['title'],
            description: $description,
            targetDate: $targetDate,
        );
        $this->entityManager->persist($goal);
        $this->entityManager->flush();

        return new JsonResponse($this->serializeGoal($goal), 201);
    }

    #[Route('/api/anketas/{id}/goals/{goalId}', name: 'anketa_goal_update', methods: ['PUT'])]
    public function updateGoal(string $id, string $goalId, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        $goal = $this->entityManager->find(Goal::class, $goalId);
        if (null === $goal || $goal->getAnketa()->getId() !== $anketa->getId()) {
            throw new NotFoundHttpException($this->translator->trans('errors.goal_not_found'));
        }
        if (!$goal->isAuthor($user)) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.goal_author_only'));
        }
        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $body = $request->toArray();
        if (isset($body['title'])) {
            if (!\is_string($body['title']) || '' === $body['title']) {
                return new JsonResponse(['error' => $this->translator->trans('errors.title_must_be_non_empty')], 400);
            }
            $goal->setTitle($body['title']);
        }
        if (\array_key_exists('description', $body)) {
            if (null !== $body['description'] && !\is_string($body['description'])) {
                return new JsonResponse(['error' => $this->translator->trans('errors.description_must_be_string')], 400);
            }
            $goal->setDescription($body['description']);
        }
        if (\array_key_exists('targetDate', $body)) {
            if (null === $body['targetDate']) {
                $goal->setTargetDate(null);
            } elseif (\is_string($body['targetDate'])) {
                try {
                    $goal->setTargetDate(new \DateTimeImmutable($body['targetDate']));
                } catch (\Exception) {
                    return new JsonResponse(['error' => $this->translator->trans('errors.target_date_must_be_valid_date')], 400);
                }
            } else {
                return new JsonResponse(['error' => $this->translator->trans('errors.target_date_must_be_string_or_null')], 400);
            }
        }
        if (isset($body['status'])) {
            if (!\in_array($body['status'], Goal::STATUSES, true)) {
                return new JsonResponse(['error' => $this->translator->trans('errors.status_must_be_one_of', ['%statuses%' => implode(', ', Goal::STATUSES)])], 400);
            }
            $goal->setStatus($body['status']);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeGoal($goal));
    }

    #[Route('/api/anketas/{id}/draft', name: 'anketa_draft', methods: ['PUT'])]
    public function saveDraft(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        if ($anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.already_published'));
        }

        $blob = $request->toArray()['blob'] ?? null;
        if (!\is_string($blob)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob')], 400);
        }

        $anketa->saveDraft($user, $blob);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/anketas/{id}/publish', name: 'anketa_publish', methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        if ($anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.already_published'));
        }

        $blob = $request->toArray()['blob'] ?? null;
        if (!\is_string($blob)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob')], 400);
        }

        $anketa->publish($user, $blob);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/anketas/{id}/archive', name: 'anketa_archive', methods: ['POST'])]
    public function archive(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        $body = $request->toArray();
        $missed = $body['missed'] ?? false;
        $skipNextMeeting = $body['skipNextMeeting'] ?? false;
        if (!\is_bool($missed) || !\is_bool($skipNextMeeting)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missed_skip_must_be_booleans')], 400);
        }

        $nextMeetingDate = null;
        if (!$skipNextMeeting && isset($body['nextMeetingDate'])) {
            if (!\is_string($body['nextMeetingDate'])) {
                return new JsonResponse(['error' => $this->translator->trans('errors.next_meeting_date_must_be_string')], 400);
            }
            try {
                $nextMeetingDate = new \DateTimeImmutable($body['nextMeetingDate']);
            } catch (\Exception) {
                return new JsonResponse(['error' => $this->translator->trans('errors.next_meeting_date_must_be_valid_date')], 400);
            }
        }

        $outcomesBlob = $body['outcomesBlob'] ?? null;
        if (null !== $outcomesBlob && !\is_string($outcomesBlob)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.outcomes_blob_must_be_string')], 400);
        }

        // Closes the Phase 6d deferred item: if either participant is now blocked
        // (Phase 6g), auto-recreation stops for this pair — same effect as
        // skipNextMeeting, but forced, regardless of what the client asked for.
        $eitherBlocked = $anketa->getEmployee()->isBlocked() || $anketa->getManager()->isBlocked();
        $createNext = !$skipNextMeeting && !$eitherBlocked;

        if ($createNext) {
            $periodicityDays = $anketa->getPeriodicityDays();
            if (null === $periodicityDays) {
                return new JsonResponse(['error' => $this->translator->trans('errors.no_periodicity_on_record')], 400);
            }
            $mySealedKey = $body['mySealedKey'] ?? null;
            $counterpartSealedKey = $body['counterpartSealedKey'] ?? null;
            if (!\is_string($mySealedKey) || !\is_string($counterpartSealedKey)) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_sealed_keys')], 400);
            }
        }

        // Auto-recreation (Phase 6d) is triggered by *this* request, from the archiving
        // user's own already-unlocked browser session — never a server-side background
        // job. The server never generates or even transiently holds an anketa key; the
        // client seals mySealedKey/counterpartSealedKey itself before sending them, exactly
        // like a manually created anketa (see the Phase 6d plan's security-design note).
        $anketa->archive($missed);
        $archivedAt = $anketa->getArchivedAt();
        \assert(null !== $archivedAt); // archive() just set this, unconditionally, on the line above.

        $nextAnketa = null;
        $nextRecipient = null;
        if ($createNext) {
            $isEmployee = $anketa->isEmployee($user);
            $nextAnketa = $this->createAnketaWithCarryForward(
                employee: $anketa->getEmployee(),
                manager: $anketa->getManager(),
                meetingDate: $nextMeetingDate ?? $archivedAt->modify(sprintf('+%d days', $periodicityDays)),
                employeeSealedKey: $isEmployee ? $mySealedKey : $counterpartSealedKey,
                managerSealedKey: $isEmployee ? $counterpartSealedKey : $mySealedKey,
                periodicityDays: $periodicityDays,
                outcomesBlob: $outcomesBlob,
                carryFrom: $anketa,
            );
            // The recipient is the participant who *didn't* trigger this archive request —
            // same "creator notifies the other side" shape as manual creation in create().
            $nextRecipient = $isEmployee ? $anketa->getManager() : $anketa->getEmployee();
        }

        $this->entityManager->flush();

        // $nextAnketa and $nextRecipient are always assigned together, above, inside the
        // same `if ($createNext)` block — checking one implies the other.
        if (null !== $nextAnketa) {
            $this->notifier->notifyAnketaCreated($nextAnketa, $nextRecipient, $user);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/anketas/{id}/meeting-date', name: 'anketa_reschedule', methods: ['PUT'])]
    public function reschedule(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa] = $this->findAccessible($id, $request);

        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $meetingDate = $request->toArray()['meetingDate'] ?? null;
        if (!\is_string($meetingDate)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_meeting_date')], 400);
        }
        try {
            $anketa->reschedule(new \DateTimeImmutable($meetingDate));
        } catch (\Exception) {
            return new JsonResponse(['error' => $this->translator->trans('errors.meeting_date_must_be_valid_date')], 400);
        }

        $this->entityManager->flush();

        return new JsonResponse(['meetingDate' => $anketa->getMeetingDate()->format(\DATE_ATOM)]);
    }

    /**
     * Restores a counterpart's access after their public key changed (most commonly a
     * password reset — password-reset plan, part 2). The caller must already have a
     * working copy of the anketa key (their own side is unaffected) and does the actual
     * unseal/reseal client-side; this just stores the result for the *other*
     * participant's side, never the caller's own.
     */
    #[Route('/api/anketas/{id}/reshare-key', name: 'anketa_reshare_key', methods: ['PUT'])]
    public function reshareKey(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        $sealedKey = $request->toArray()['sealedKey'] ?? null;
        if (!\is_string($sealedKey) || '' === $sealedKey) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'sealedKey'])], 400);
        }

        $counterpart = $anketa->isEmployee($user) ? $anketa->getManager() : $anketa->getEmployee();
        $anketa->resealKeyFor($counterpart, $sealedKey);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function requireUser(Request $request): User
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }

        return $user;
    }

    /** @return array{0: Anketa, 1: User} */
    private function findAccessible(string $id, Request $request): array
    {
        $user = $this->requireUser($request);
        $anketa = $this->entityManager->find(Anketa::class, $id);
        if (null === $anketa) {
            throw new NotFoundHttpException($this->translator->trans('errors.anketa_not_found'));
        }
        if (!$anketa->isParticipant($user)) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.not_a_participant'));
        }

        return [$anketa, $user];
    }

    /**
     * Builds a new Anketa (optionally seeded with a client-carried outcomesBlob) and
     * copies in_progress goals from $carryFrom into it, if given. Shared by create()
     * (carryFrom = the pair's most recent archived anketa, found by query) and
     * archive()'s auto-recreation (carryFrom = the anketa just archived, already in
     * hand — no query needed there). Persists the new Anketa and any copied Goals;
     * does not flush, callers do that once after whatever else they need to persist.
     */
    private function createAnketaWithCarryForward(
        User $employee,
        User $manager,
        \DateTimeImmutable $meetingDate,
        string $employeeSealedKey,
        string $managerSealedKey,
        int $periodicityDays,
        ?string $outcomesBlob,
        ?Anketa $carryFrom,
    ): Anketa {
        $anketa = new Anketa(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $employeeSealedKey,
            managerSealedKey: $managerSealedKey,
            periodicityDays: $periodicityDays,
        );

        if (null !== $outcomesBlob) {
            $anketa->seedOutcomes($outcomesBlob);
        }

        $this->entityManager->persist($anketa);

        if (null !== $carryFrom) {
            foreach ($this->goalRepository()->findBy(['anketa' => $carryFrom, 'status' => Goal::STATUS_IN_PROGRESS]) as $previousGoal) {
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

    /** @return \Doctrine\ORM\EntityRepository<Goal> */
    private function goalRepository(): \Doctrine\ORM\EntityRepository
    {
        return $this->entityManager->getRepository(Goal::class);
    }

    /**
     * "Pair" is the unordered set of two users — roles aren't verified, they're just a
     * per-anketa choice (see the Phase 5 plan), so carry-forward must match regardless
     * of which one played employee/manager last time.
     */
    private function findMostRecentArchivedAnketaForPair(User $a, User $b): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->entityManager->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('(anketa.employee = :a AND anketa.manager = :b) OR (anketa.employee = :b AND anketa.manager = :a)')
            ->andWhere('anketa.archivedAt IS NOT NULL')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->orderBy('anketa.meetingDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }

    /**
     * @return array{id: string, goalUuid: string, authorId: string, title: string,
     *     description: string|null, targetDate: string|null, status: string, createdAt: string}
     */
    private function serializeGoal(Goal $goal): array
    {
        return [
            'id' => $goal->getId(),
            'goalUuid' => $goal->getGoalUuid(),
            'authorId' => $goal->getAuthor()->getId(),
            'title' => $goal->getTitle(),
            'description' => $goal->getDescription(),
            'targetDate' => $goal->getTargetDate()?->format('Y-m-d'),
            'status' => $goal->getStatus(),
            'createdAt' => $goal->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array{id: string, myRole: string, counterpartId: string, counterpartEmail: string,
     *     meetingDate: string, myPublishedAt: string|null, counterpartPublishedAt: string|null,
     *     archivedAt: string|null, missed: bool, periodicityDays: int|null, counterpartKeyOutdated: bool}
     */
    private function summarize(Anketa $anketa, User $user): array
    {
        $isEmployee = $anketa->isEmployee($user);
        $counterpart = $isEmployee ? $anketa->getManager() : $anketa->getEmployee();

        return [
            'id' => $anketa->getId(),
            'myRole' => $isEmployee ? 'employee' : 'manager',
            'counterpartId' => $counterpart->getId(),
            'counterpartEmail' => $counterpart->getEmail(),
            'meetingDate' => $anketa->getMeetingDate()->format(\DATE_ATOM),
            'myPublishedAt' => ($isEmployee ? $anketa->getEmployeePublishedAt() : $anketa->getManagerPublishedAt())?->format(\DATE_ATOM),
            'counterpartPublishedAt' => ($isEmployee ? $anketa->getManagerPublishedAt() : $anketa->getEmployeePublishedAt())?->format(\DATE_ATOM),
            'archivedAt' => $anketa->getArchivedAt()?->format(\DATE_ATOM),
            'missed' => $anketa->isMissed(),
            'periodicityDays' => $anketa->getPeriodicityDays(),
            'counterpartKeyOutdated' => $this->isKeyOutdated($anketa, $counterpart),
        ];
    }

    /**
     * True when $participant's public key has changed (password-reset plan, part 2)
     * since their side of $anketa's sealed key was last set — i.e. their copy of the
     * anketa key was sealed to a public key that's no longer current, so whoever's
     * looking at this (the *other* participant, whose own key is unaffected) can offer
     * to re-seal it. Self-correcting: resealKeyFor() bumps the anketa-side timestamp
     * past the reset, a later reset moves publicKeyUpdatedAt forward again.
     */
    private function isKeyOutdated(Anketa $anketa, User $participant): bool
    {
        $publicKeyUpdatedAt = $participant->getPublicKeyUpdatedAt();

        return null !== $publicKeyUpdatedAt && $publicKeyUpdatedAt > $anketa->sealedKeyUpdatedAtFor($participant);
    }
}
