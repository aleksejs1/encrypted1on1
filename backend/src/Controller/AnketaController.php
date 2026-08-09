<?php

namespace App\Controller;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
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

        $anketa = new Anketa(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $isEmployee ? $body['mySealedKey'] : $body['counterpartSealedKey'],
            managerSealedKey: $isEmployee ? $body['counterpartSealedKey'] : $body['mySealedKey'],
        );

        // Outcomes carry-forward (Phase 6c): the client already decrypted the prior
        // archived anketa's outcomesBlob, filtered to unchecked items, and re-encrypted
        // with this anketa's new key — the server can't do that part, it never sees plaintext.
        if (isset($body['outcomesBlob']) && \is_string($body['outcomesBlob'])) {
            $anketa->seedOutcomes($body['outcomesBlob']);
        }

        $this->entityManager->persist($anketa);

        // Goals carry-forward (Phase 6c): unlike outcomes, goal title/status is plaintext,
        // so the server can do this copy itself — no client round-trip needed.
        $previousAnketa = $this->findMostRecentArchivedAnketaForPair($employee, $manager);
        if (null !== $previousAnketa) {
            foreach ($this->goalRepository()->findBy(['anketa' => $previousAnketa, 'status' => Goal::STATUS_IN_PROGRESS]) as $previousGoal) {
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

        $this->entityManager->flush();

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

        return new JsonResponse([
            ...$this->summarize($anketa, $user),
            'mySealedKey' => $anketa->sealedKeyFor($user),
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
        [$anketa] = $this->findAccessible($id, $request);

        $anketa->archive();
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
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
        ];
    }
}
