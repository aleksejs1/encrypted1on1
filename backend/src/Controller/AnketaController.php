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
                return new JsonResponse(['error' => sprintf('Missing or invalid "%s".', $field)], 400);
            }
        }
        if (!\in_array($body['myRole'], ['employee', 'manager'], true)) {
            return new JsonResponse(['error' => '"myRole" must be "employee" or "manager".'], 400);
        }

        $counterpart = $this->entityManager->find(User::class, $body['counterpartId']);
        if (null === $counterpart) {
            return new JsonResponse(['error' => 'Counterpart not found.'], 404);
        }

        try {
            // The constructor (unlike createFromFormat(DATE_ATOM, ...)) accepts the
            // milliseconds + "Z" suffix that JS's Date.toISOString() actually produces.
            $meetingDate = new \DateTimeImmutable($body['meetingDate']);
        } catch (\Exception) {
            return new JsonResponse(['error' => '"meetingDate" must be a valid date.'], 400);
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
                return new JsonResponse(['error' => '"periodicityDays" must be a positive integer for a new pair.'], 400);
            }
        }

        $outcomesBlob = $body['outcomesBlob'] ?? null;
        if (null !== $outcomesBlob && !\is_string($outcomesBlob)) {
            return new JsonResponse(['error' => '"outcomesBlob" must be a string.'], 400);
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
            return new JsonResponse(['error' => 'Missing "blob" or "expectedVersion".'], 400);
        }

        if (!$anketa->saveComments($blob, $expectedVersion)) {
            // Conflict: hand back the current state so the client can merge without a second round-trip.
            return new JsonResponse([
                'error' => 'Comments changed since you last read them.',
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
            return new JsonResponse(['error' => 'Missing "blob" or "expectedVersion".'], 400);
        }

        if (!$anketa->saveOutcomes($blob, $expectedVersion)) {
            return new JsonResponse([
                'error' => 'Outcomes changed since you last read them.',
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
            throw new ConflictHttpException('Anketa is archived.');
        }

        $body = $request->toArray();
        $blob = $body['blob'] ?? null;
        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_string($blob) || !\is_int($expectedVersion)) {
            return new JsonResponse(['error' => 'Missing "blob" or "expectedVersion".'], 400);
        }

        if (!$anketa->saveGoalCheckpoints($blob, $expectedVersion)) {
            return new JsonResponse([
                'error' => 'Goal checkpoints changed since you last read them.',
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
            throw new ConflictHttpException('Anketa is archived.');
        }

        $body = $request->toArray();
        foreach (['goalUuid', 'title'] as $field) {
            if (empty($body[$field]) || !\is_string($body[$field])) {
                return new JsonResponse(['error' => sprintf('Missing or invalid "%s".', $field)], 400);
            }
        }
        $description = $body['description'] ?? null;
        if (null !== $description && !\is_string($description)) {
            return new JsonResponse(['error' => '"description" must be a string.'], 400);
        }

        $targetDate = null;
        if (!empty($body['targetDate'])) {
            if (!\is_string($body['targetDate'])) {
                return new JsonResponse(['error' => '"targetDate" must be a string.'], 400);
            }
            try {
                $targetDate = new \DateTimeImmutable($body['targetDate']);
            } catch (\Exception) {
                return new JsonResponse(['error' => '"targetDate" must be a valid date.'], 400);
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
            throw new NotFoundHttpException('Goal not found.');
        }
        if (!$goal->isAuthor($user)) {
            throw new AccessDeniedHttpException('Only the goal\'s author can edit it.');
        }
        if ($anketa->isArchived()) {
            throw new ConflictHttpException('Anketa is archived.');
        }

        $body = $request->toArray();
        if (isset($body['title'])) {
            if (!\is_string($body['title']) || '' === $body['title']) {
                return new JsonResponse(['error' => '"title" must be a non-empty string.'], 400);
            }
            $goal->setTitle($body['title']);
        }
        if (\array_key_exists('description', $body)) {
            if (null !== $body['description'] && !\is_string($body['description'])) {
                return new JsonResponse(['error' => '"description" must be a string.'], 400);
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
                    return new JsonResponse(['error' => '"targetDate" must be a valid date.'], 400);
                }
            } else {
                return new JsonResponse(['error' => '"targetDate" must be a string or null.'], 400);
            }
        }
        if (isset($body['status'])) {
            if (!\in_array($body['status'], Goal::STATUSES, true)) {
                return new JsonResponse(['error' => sprintf('"status" must be one of: %s.', implode(', ', Goal::STATUSES))], 400);
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
            throw new ConflictHttpException('Already published.');
        }

        $blob = $request->toArray()['blob'] ?? null;
        if (!\is_string($blob)) {
            return new JsonResponse(['error' => 'Missing "blob".'], 400);
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
            throw new ConflictHttpException('Already published.');
        }

        $blob = $request->toArray()['blob'] ?? null;
        if (!\is_string($blob)) {
            return new JsonResponse(['error' => 'Missing "blob".'], 400);
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
            return new JsonResponse(['error' => '"missed" and "skipNextMeeting" must be booleans.'], 400);
        }

        $nextMeetingDate = null;
        if (!$skipNextMeeting && isset($body['nextMeetingDate'])) {
            if (!\is_string($body['nextMeetingDate'])) {
                return new JsonResponse(['error' => '"nextMeetingDate" must be a string.'], 400);
            }
            try {
                $nextMeetingDate = new \DateTimeImmutable($body['nextMeetingDate']);
            } catch (\Exception) {
                return new JsonResponse(['error' => '"nextMeetingDate" must be a valid date.'], 400);
            }
        }

        $outcomesBlob = $body['outcomesBlob'] ?? null;
        if (null !== $outcomesBlob && !\is_string($outcomesBlob)) {
            return new JsonResponse(['error' => '"outcomesBlob" must be a string.'], 400);
        }

        // Closes the Phase 6d deferred item: if either participant is now blocked
        // (Phase 6g), auto-recreation stops for this pair — same effect as
        // skipNextMeeting, but forced, regardless of what the client asked for.
        $eitherBlocked = $anketa->getEmployee()->isBlocked() || $anketa->getManager()->isBlocked();
        $createNext = !$skipNextMeeting && !$eitherBlocked;

        if ($createNext) {
            $periodicityDays = $anketa->getPeriodicityDays();
            if (null === $periodicityDays) {
                return new JsonResponse(['error' => 'This anketa has no periodicity on record — pass "skipNextMeeting": true.'], 400);
            }
            $mySealedKey = $body['mySealedKey'] ?? null;
            $counterpartSealedKey = $body['counterpartSealedKey'] ?? null;
            if (!\is_string($mySealedKey) || !\is_string($counterpartSealedKey)) {
                return new JsonResponse(['error' => 'Missing "mySealedKey" or "counterpartSealedKey".'], 400);
            }
        }

        // Auto-recreation (Phase 6d) is triggered by *this* request, from the archiving
        // user's own already-unlocked browser session — never a server-side background
        // job. The server never generates or even transiently holds an anketa key; the
        // client seals mySealedKey/counterpartSealedKey itself before sending them, exactly
        // like a manually created anketa (see the Phase 6d plan's security-design note).
        $anketa->archive($missed);

        $nextAnketa = null;
        $nextRecipient = null;
        if ($createNext) {
            $isEmployee = $anketa->isEmployee($user);
            $nextAnketa = $this->createAnketaWithCarryForward(
                employee: $anketa->getEmployee(),
                manager: $anketa->getManager(),
                meetingDate: $nextMeetingDate ?? $anketa->getArchivedAt()->modify(sprintf('+%d days', $periodicityDays)),
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

        if (null !== $nextAnketa && null !== $nextRecipient) {
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
            throw new ConflictHttpException('Anketa is archived.');
        }

        $meetingDate = $request->toArray()['meetingDate'] ?? null;
        if (!\is_string($meetingDate)) {
            return new JsonResponse(['error' => 'Missing "meetingDate".'], 400);
        }
        try {
            $anketa->reschedule(new \DateTimeImmutable($meetingDate));
        } catch (\Exception) {
            return new JsonResponse(['error' => '"meetingDate" must be a valid date.'], 400);
        }

        $this->entityManager->flush();

        return new JsonResponse(['meetingDate' => $anketa->getMeetingDate()->format(\DATE_ATOM)]);
    }

    private function requireUser(Request $request): User
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            throw new UnauthorizedHttpException('', 'Not authenticated.');
        }

        return $user;
    }

    /** @return array{0: Anketa, 1: User} */
    private function findAccessible(string $id, Request $request): array
    {
        $user = $this->requireUser($request);
        $anketa = $this->entityManager->find(Anketa::class, $id);
        if (null === $anketa) {
            throw new NotFoundHttpException('Anketa not found.');
        }
        if (!$anketa->isParticipant($user)) {
            throw new AccessDeniedHttpException('Not a participant.');
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
        return $this->entityManager->createQueryBuilder()
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
    }

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
        ];
    }
}
