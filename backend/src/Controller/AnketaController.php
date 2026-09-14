<?php

namespace App\Controller;

use App\Anketa\AnketaLifecycleService;
use App\Anketa\AnketaPresenter;
use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Repository\AnketaRepository;
use App\Repository\GoalRepository;
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
 * so this is a plain controller, not an API Platform resource.
 */
class AnketaController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly TranslatorInterface $translator,
        private readonly AnketaRepository $anketaRepository,
        private readonly GoalRepository $goalRepository,
        private readonly AnketaLifecycleService $lifecycleService,
        private readonly AnketaPresenter $presenter,
    ) {
    }

    #[Route('/api/anketas', name: 'anketa_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        $user = $this->requireUser($request);

        $body = $request->toArray();
        foreach (['counterpartId', 'myRole', 'meetingDate', 'mySealedKey', 'counterpartSealedKey'] as $field) {
            if (!\is_string($body[$field] ?? null) || '' === $body[$field]) {
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
        // The tenant boundary (private/cloud-service-plan.md, not tracked in git, Phase
        // A): a counterpart from another company is treated identically to a nonexistent
        // one — same error, same status — rather than a distinct "wrong company" message,
        // so this never reveals that a given id belongs to a real user elsewhere. This is
        // the *only* place this needs enforcing: no anketa can exist cross-company if none
        // can ever be created that way, so nothing downstream needs to re-check it.
        if ($counterpart->getCompany() !== $user->getCompany()) {
            return new JsonResponse(['error' => $this->translator->trans('errors.counterpart_not_found')], 404);
        }
        // Same "treat like nonexistent" shape as the company check above — a blocked or
        // deleted account is already excluded from the counterpart-picker
        // (ExcludeDeletedUsersExtension), but that only stops the normal UI flow, not a
        // direct API call against a previously-known id. archive()'s auto-recreation
        // already refuses a blocked participant (see its own comment); this closes the
        // same gap for manual creation, which had no such check at all.
        if ($counterpart->isBlocked() || null !== $counterpart->getDeletedAt()) {
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
        $previousAnketa = $this->anketaRepository->findMostRecentArchivedForPair($employee, $manager);

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

        $anketa = $this->lifecycleService->createAnketa(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $isEmployee ? $body['mySealedKey'] : $body['counterpartSealedKey'],
            managerSealedKey: $isEmployee ? $body['counterpartSealedKey'] : $body['mySealedKey'],
            periodicityDays: $periodicityDays,
            outcomesBlob: $outcomesBlob,
            carryFrom: $previousAnketa,
            company: $user->getCompany(),
            creator: $user,
        );

        return new JsonResponse(['id' => $anketa->getId()], 201);
    }

    #[Route('/api/anketas', name: 'anketa_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        // Read-only from here on — release the session file lock instead of holding it
        // for the rest of this (frequently-polled) request. See
        // AuthSession::closeForReading()'s docblock for why this isn't automatic.
        $this->authSession->closeForReading($request);

        $anketas = $this->anketaRepository->findAllForUser($user);

        return new JsonResponse(array_map(fn (Anketa $anketa) => $this->presenter->summarize($anketa, $user), $anketas));
    }

    /**
     * Every anketa the requester participates in, at full detail (same shape as get()) —
     * closes private/todo.md's "N+1 GET /api/anketas/{id} calls" item for the two pages
     * that genuinely want all of them (Report.svelte, AccountSettings.svelte's export),
     * neither of which filters by id server-side, so there's no request body/id-list here,
     * just everything the user can already see (same no-filtering shape as list()).
     *
     * Declared before the /api/anketas/{id} route below — "bulk" would otherwise be
     * swallowed by that route's {id} placeholder.
     */
    #[Route('/api/anketas/bulk', name: 'anketa_bulk', methods: ['GET'])]
    public function bulk(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        // Read-only from here on — release the session file lock instead of holding it
        // for the rest of this request. See AuthSession::closeForReading()'s docblock
        // for why this isn't automatic.
        $this->authSession->closeForReading($request);

        $anketas = $this->anketaRepository->findAllForUser($user);
        $goalsByAnketaId = $this->goalRepository->findByAnketasGroupedByAnketaId($anketas);

        return new JsonResponse(array_map(
            fn (Anketa $anketa) => $this->presenter->serializeDetail($anketa, $user, $goalsByAnketaId[$anketa->getId()] ?? []),
            $anketas,
        ));
    }

    #[Route('/api/anketas/{id}', name: 'anketa_get', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        [$anketa, $user] = $this->findAccessible($id, $request);

        // Read-only from here on — release the session file lock instead of holding it
        // for the rest of this request. See AuthSession::closeForReading()'s docblock
        // for why this isn't automatic.
        $this->authSession->closeForReading($request);

        return new JsonResponse($this->presenter->serializeDetail($anketa, $user, $this->goalRepository->findByAnketa($anketa)));
    }

    /**
     * A cheap polling target for the anketa detail page's live-update mechanism
     * (see private/live-updates-proposal.md, not tracked in git) — every scalar a
     * client needs to decide "has anything changed since I last loaded," with no
     * blobs and no goals query. Deliberately mirrors summarize()'s scalar shape
     * plus the blob version counters rather than trimming serializeDetail() down,
     * since the two endpoints have genuinely different jobs (full state vs. a
     * cheap change signal) — see the "no generic CRUD" rule this controller
     * already follows for every other route.
     */
    #[Route('/api/anketas/{id}/live-state', name: 'anketa_live_state', methods: ['GET'])]
    public function liveState(string $id, Request $request): JsonResponse
    {
        [$anketa, $user] = $this->findAccessible($id, $request);

        // Read-only from here on — same reasoning as get() above.
        $this->authSession->closeForReading($request);

        return new JsonResponse($this->presenter->serializeLiveState($anketa, $user));
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
            if (!\is_string($body[$field] ?? null) || '' === $body[$field]) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => $field])], 400);
            }
        }
        $description = $body['description'] ?? null;
        if (null !== $description && !\is_string($description)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.description_must_be_string')], 400);
        }

        $targetDate = null;
        if (isset($body['targetDate']) && '' !== $body['targetDate']) {
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

        return new JsonResponse($this->presenter->serializeGoal($goal), 201);
    }

    #[Route('/api/anketas/{id}/goals/{goalId}', name: 'anketa_goal_update', methods: ['PUT'])]
    public function updateGoal(string $id, string $goalId, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        $goal = $this->goalRepository->find($goalId);
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

        return new JsonResponse($this->presenter->serializeGoal($goal));
    }

    #[Route('/api/anketas/{id}/draft', name: 'anketa_draft', methods: ['PUT'])]
    public function saveDraft(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        // Checked before isPublished() — once archived, both draft-saving and
        // publishing are terminal regardless of whether this side ever
        // published, same "archived overrides everything else" precedent
        // updateAnswers()/saveGoalCheckpoints() already established.
        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }
        if ($anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.already_published'));
        }

        $blob = $request->toArray()['blob'] ?? null;
        if (!\is_string($blob)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob')], 400);
        }

        $this->lifecycleService->saveDraft($anketa, $user, $blob);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/anketas/{id}/publish', name: 'anketa_publish', methods: ['POST'])]
    public function publish(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        // See saveDraft()'s identical check above for why this is checked
        // before isPublished().
        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }
        if ($anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.already_published'));
        }

        $blob = $request->toArray()['blob'] ?? null;
        if (!\is_string($blob)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob')], 400);
        }

        $this->lifecycleService->publish($anketa, $user, $blob);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Edits an already-published side's own answers — see
     * docs/decisions/2026-09-07-editable-published-anketa-answers.md. Deliberately separate
     * from publish() (which only handles the first publish and stamps *PublishedAt) so an
     * edit can never look like a fresh publish to anything reading that timestamp.
     */
    #[Route('/api/anketas/{id}/answers', name: 'anketa_update_answers', methods: ['PUT'])]
    public function updateAnswers(string $id, Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);
        [$anketa, $user] = $this->findAccessible($id, $request);

        if (!$anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.not_published_yet'));
        }
        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $body = $request->toArray();
        $blob = $body['blob'] ?? null;
        $expectedVersion = $body['expectedVersion'] ?? null;
        if (!\is_string($blob) || !\is_int($expectedVersion)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_blob_or_expected_version')], 400);
        }

        $isEmployee = $anketa->isEmployee($user);
        if (!$this->lifecycleService->updatePublishedAnswers($anketa, $user, $blob, $expectedVersion)) {
            return new JsonResponse([
                'error' => $this->translator->trans('errors.answers_conflict'),
                'blob' => $isEmployee ? $anketa->getEmployeeBlob() : $anketa->getManagerBlob(),
                'blobVersion' => $isEmployee ? $anketa->getEmployeeBlobVersion() : $anketa->getManagerBlobVersion(),
            ], 409);
        }

        return new JsonResponse(['blobVersion' => $isEmployee ? $anketa->getEmployeeBlobVersion() : $anketa->getManagerBlobVersion()]);
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

        $createNext = $this->lifecycleService->shouldCreateNext($anketa, $skipNextMeeting);

        $mySealedKey = null;
        $counterpartSealedKey = null;
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

        $this->lifecycleService->archive(
            anketa: $anketa,
            actor: $user,
            missed: $missed,
            skipNextMeeting: $skipNextMeeting,
            nextMeetingDate: $nextMeetingDate,
            mySealedKey: $mySealedKey,
            counterpartSealedKey: $counterpartSealedKey,
            outcomesBlob: $outcomesBlob,
        );

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
     * unseal/reseal client-side; this just stores the result for the other
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

        $this->lifecycleService->reshareKey($anketa, $user, $sealedKey);

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

    /**
     * Eager-joins employee/manager rather than a plain find().
     *
     * @return array{0: Anketa, 1: User}
     */
    private function findAccessible(string $id, Request $request): array
    {
        $user = $this->requireUser($request);
        $anketa = $this->anketaRepository->findWithParticipants($id);
        if (null === $anketa) {
            throw new NotFoundHttpException($this->translator->trans('errors.anketa_not_found'));
        }
        if (!$anketa->isParticipant($user)) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.not_a_participant'));
        }

        return [$anketa, $user];
    }
}
