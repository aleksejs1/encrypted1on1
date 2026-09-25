<?php

namespace App\Controller;

use App\Anketa\AnketaAlreadyArchivedException;
use App\Anketa\AnketaLifecycleService;
use App\Anketa\AnketaPresenter;
use App\Dto\ArchiveAnketaRequest;
use App\Dto\CreateAnketaRequest;
use App\Dto\CreateGoalRequest;
use App\Dto\RescheduleAnketaRequest;
use App\Dto\ReshareKeyRequest;
use App\Dto\SaveBlobRequest;
use App\Dto\SaveVersionedBlobRequest;
use App\Dto\UpdateGoalRequest;
use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use App\Repository\AnketaRepository;
use App\Repository\GoalRepository;
use App\Security\AuthSession;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
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
        private readonly TranslatorInterface $translator,
        private readonly AnketaRepository $anketaRepository,
        private readonly GoalRepository $goalRepository,
        private readonly AnketaLifecycleService $lifecycleService,
        private readonly AnketaPresenter $presenter,
    ) {
    }

    #[Route('/api/anketas', name: 'anketa_create', methods: ['POST'])]
    public function create(
        #[MapRequestPayload] CreateAnketaRequest $payload,
        Request $request,
    ): JsonResponse {
        $user = $this->requireUser($request);

        $counterpart = $this->entityManager->find(User::class, $payload->counterpartId);
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

        // The constructor (unlike createFromFormat(DATE_ATOM, ...)) accepts the
        // milliseconds + "Z" suffix that JS's Date.toISOString() actually produces.
        $meetingDate = new \DateTimeImmutable($payload->meetingDate);

        $isEmployee = 'employee' === $payload->myRole;
        $employee = $isEmployee ? $user : $counterpart;
        $manager = $isEmployee ? $counterpart : $user;

        // Periodicity (Phase 6d) is set once, on a pair's first anketa, and inherited by
        // every later one — same "most recent anketa for this pair" lookup goal carry-forward
        // already needed (6c), reused here rather than a second query for the same concept.
        $previousAnketa = $this->anketaRepository->findMostRecentArchivedForPair($employee, $manager);

        // GitHub issue #111: a pair that already has an open chain anketa (typically the
        // one archive() auto-created; open one-offs don't count) gets this one as a one-off
        // — no carry-forward (the open one already has it; a second copy of the same
        // goals/outcomes would just diverge) and no auto-recreated successor on archive
        // (Anketa::$oneOff). Decided here, server-side, not by CreateAnketa.svelte, whose
        // anketa list may be stale by the time it submits. Read-then-insert with no lock:
        // two creates for the same pair at the same instant can both come out as chain
        // anketas — an accepted limitation, see the decision record.
        $openAnketa = $this->anketaRepository->findOpenForPair($employee, $manager);
        $oneOff = null !== $openAnketa;

        // A one-off inherits periodicity from the open anketa too, so a pair whose first
        // anketa is still open isn't asked for (and can't set a different) periodicity again.
        $periodicityDays = $previousAnketa?->getPeriodicityDays() ?? $openAnketa?->getPeriodicityDays();
        if (null === $periodicityDays) {
            $periodicityDays = $payload->periodicityDays;
            if (null === $periodicityDays) {
                return new JsonResponse(['error' => $this->translator->trans('errors.periodicity_required')], 400);
            }
        }

        $anketa = $this->lifecycleService->createAnketa(
            employee: $employee,
            manager: $manager,
            meetingDate: $meetingDate,
            employeeSealedKey: $isEmployee ? $payload->mySealedKey : $payload->counterpartSealedKey,
            managerSealedKey: $isEmployee ? $payload->counterpartSealedKey : $payload->mySealedKey,
            periodicityDays: $periodicityDays,
            // Dropped for a one-off by the service itself — see createWithCarryForward().
            outcomesBlob: $payload->outcomesBlob,
            carryFrom: $previousAnketa,
            creator: $user,
            templateKey: $payload->templateKey,
            oneOff: $oneOff,
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
    public function saveComments(
        string $id,
        #[MapRequestPayload] SaveVersionedBlobRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa] = $this->findAccessible($id, $request);

        if (!$anketa->saveComments((string) $payload->blob, (int) $payload->expectedVersion)) {
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
    public function saveOutcomes(
        string $id,
        #[MapRequestPayload] SaveVersionedBlobRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa] = $this->findAccessible($id, $request);

        if (!$anketa->saveOutcomes((string) $payload->blob, (int) $payload->expectedVersion)) {
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
    public function saveGoalCheckpoints(
        string $id,
        #[MapRequestPayload] SaveVersionedBlobRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa] = $this->findAccessible($id, $request);

        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        if (!$anketa->saveGoalCheckpoints((string) $payload->blob, (int) $payload->expectedVersion)) {
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
    public function createGoal(
        string $id,
        #[MapRequestPayload] CreateGoalRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa, $user] = $this->findAccessible($id, $request);

        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $targetDate = (null !== $payload->targetDate && '' !== $payload->targetDate)
            ? new \DateTimeImmutable($payload->targetDate)
            : null;

        $goal = new Goal(
            goalUuid: $payload->goalUuid,
            anketa: $anketa,
            author: $user,
            title: $payload->title,
            description: $payload->description,
            targetDate: $targetDate,
        );
        $this->entityManager->persist($goal);
        $this->entityManager->flush();

        return new JsonResponse($this->presenter->serializeGoal($goal), 201);
    }

    #[Route('/api/anketas/{id}/goals/{goalId}', name: 'anketa_goal_update', methods: ['PUT'])]
    public function updateGoal(
        string $id,
        string $goalId,
        #[MapRequestPayload] UpdateGoalRequest $payload,
        Request $request,
    ): JsonResponse {
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

        if ($payload->hasTitle() && \is_string($payload->title)) {
            $goal->setTitle($payload->title);
        }
        if ($payload->hasDescription()) {
            $goal->setDescription(\is_string($payload->description) ? $payload->description : null);
        }
        if ($payload->hasTargetDate()) {
            if (null === $payload->targetDate || '' === $payload->targetDate) {
                $goal->setTargetDate(null);
            } elseif (\is_string($payload->targetDate)) {
                $goal->setTargetDate(new \DateTimeImmutable($payload->targetDate));
            }
        }
        if ($payload->hasStatus() && \is_string($payload->status)) {
            $goal->setStatus($payload->status);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->presenter->serializeGoal($goal));
    }

    #[Route('/api/anketas/{id}/draft', name: 'anketa_draft', methods: ['PUT'])]
    public function saveDraft(
        string $id,
        #[MapRequestPayload] SaveBlobRequest $payload,
        Request $request,
    ): JsonResponse {
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

        $this->lifecycleService->saveDraft($anketa, $user, (string) $payload->blob);

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/anketas/{id}/publish', name: 'anketa_publish', methods: ['POST'])]
    public function publish(
        string $id,
        #[MapRequestPayload] SaveBlobRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa, $user] = $this->findAccessible($id, $request);

        // See saveDraft()'s identical check above for why this is checked
        // before isPublished().
        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }
        if ($anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.already_published'));
        }

        $this->lifecycleService->publish($anketa, $user, (string) $payload->blob);

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Edits an already-published side's own answers — see
     * docs/decisions/2026-09-07-editable-published-anketa-answers.md. Deliberately separate
     * from publish() (which only handles the first publish and stamps *PublishedAt) so an
     * edit can never look like a fresh publish to anything reading that timestamp.
     */
    #[Route('/api/anketas/{id}/answers', name: 'anketa_update_answers', methods: ['PUT'])]
    public function updateAnswers(
        string $id,
        #[MapRequestPayload] SaveVersionedBlobRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa, $user] = $this->findAccessible($id, $request);

        if (!$anketa->isPublished($user)) {
            throw new ConflictHttpException($this->translator->trans('errors.not_published_yet'));
        }
        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $isEmployee = $anketa->isEmployee($user);
        if (!$this->lifecycleService->updatePublishedAnswers($anketa, $user, (string) $payload->blob, (int) $payload->expectedVersion)) {
            return new JsonResponse([
                'error' => $this->translator->trans('errors.answers_conflict'),
                'blob' => $isEmployee ? $anketa->getEmployeeBlob() : $anketa->getManagerBlob(),
                'blobVersion' => $isEmployee ? $anketa->getEmployeeBlobVersion() : $anketa->getManagerBlobVersion(),
            ], 409);
        }

        return new JsonResponse(['blobVersion' => $isEmployee ? $anketa->getEmployeeBlobVersion() : $anketa->getManagerBlobVersion()]);
    }

    #[Route('/api/anketas/{id}/archive', name: 'anketa_archive', methods: ['POST'])]
    public function archive(
        string $id,
        #[MapRequestPayload] ArchiveAnketaRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa, $user] = $this->findAccessible($id, $request);

        // Without this, a second archive (double submit, two tabs, both participants at
        // once) overwrote archivedAt/missed and created a second successor, forking the
        // pair's chain — GitHub issue #130. Checked before the 400s below, so a repeat with
        // a valid body gets 409 (a body that fails #[MapRequestPayload] validation is
        // rejected before this method runs); requests that race past it are caught
        // atomically by the lifecycle service (the catch at the end).
        if ($anketa->isArchived()) {
            return $this->alreadyArchived($anketa);
        }

        $missed = $payload->missed ?? false;
        $skipNextMeeting = $payload->skipNextMeeting ?? false;

        $nextMeetingDate = null;
        if (!$skipNextMeeting && null !== $payload->nextMeetingDate && '' !== $payload->nextMeetingDate) {
            $nextMeetingDate = new \DateTimeImmutable($payload->nextMeetingDate);
        }

        $createNext = $this->lifecycleService->shouldCreateNext($anketa, $skipNextMeeting);

        $mySealedKey = null;
        $counterpartSealedKey = null;
        if ($createNext) {
            $periodicityDays = $anketa->getPeriodicityDays();
            if (null === $periodicityDays) {
                return new JsonResponse(['error' => $this->translator->trans('errors.no_periodicity_on_record')], 400);
            }
            $mySealedKey = $payload->mySealedKey;
            $counterpartSealedKey = $payload->counterpartSealedKey;
            if (null === $mySealedKey || null === $counterpartSealedKey) {
                return new JsonResponse(['error' => $this->translator->trans('errors.missing_sealed_keys')], 400);
            }
        }

        try {
            $this->lifecycleService->archive(
                anketa: $anketa,
                actor: $user,
                missed: $missed,
                skipNextMeeting: $skipNextMeeting,
                nextMeetingDate: $nextMeetingDate,
                mySealedKey: $mySealedKey,
                counterpartSealedKey: $counterpartSealedKey,
                outcomesBlob: $payload->outcomesBlob,
            );
        } catch (AnketaAlreadyArchivedException) {
            // This request's copy predates the archive that won; re-read it for the
            // response. (wrapInTransaction() didn't throw, so the EntityManager is open.)
            $this->entityManager->refresh($anketa);

            return $this->alreadyArchived($anketa);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * The 409 for archiving an already-archived anketa carries the state that actually
     * got applied — by the counterpart, another tab, or an earlier attempt whose response
     * was lost — so the client can show it at once instead of waiting for a poll. Same
     * idea as updateAnswers()'s conflict response returning the current blob.
     */
    private function alreadyArchived(Anketa $anketa): JsonResponse
    {
        return new JsonResponse([
            'error' => $this->translator->trans('errors.anketa_archived'),
            'archivedAt' => $anketa->getArchivedAt()?->format(\DATE_ATOM),
            'missed' => $anketa->isMissed(),
        ], 409);
    }

    #[Route('/api/anketas/{id}/meeting-date', name: 'anketa_reschedule', methods: ['PUT'])]
    public function reschedule(
        string $id,
        #[MapRequestPayload] RescheduleAnketaRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa] = $this->findAccessible($id, $request);

        if ($anketa->isArchived()) {
            throw new ConflictHttpException($this->translator->trans('errors.anketa_archived'));
        }

        $anketa->reschedule(new \DateTimeImmutable($payload->meetingDate));

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
    public function reshareKey(
        string $id,
        #[MapRequestPayload] ReshareKeyRequest $payload,
        Request $request,
    ): JsonResponse {
        [$anketa, $user] = $this->findAccessible($id, $request);

        $this->lifecycleService->reshareKey($anketa, $user, $payload->sealedKey);

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
