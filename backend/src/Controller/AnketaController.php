<?php

namespace App\Controller;

use App\Entity\Anketa;
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
        $anketa = new Anketa(
            employee: $isEmployee ? $user : $counterpart,
            manager: $isEmployee ? $counterpart : $user,
            meetingDate: $meetingDate,
            employeeSealedKey: $isEmployee ? $body['mySealedKey'] : $body['counterpartSealedKey'],
            managerSealedKey: $isEmployee ? $body['counterpartSealedKey'] : $body['mySealedKey'],
        );

        $this->entityManager->persist($anketa);
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
        ]);
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
