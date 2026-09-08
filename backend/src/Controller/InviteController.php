<?php

namespace App\Controller;

use App\Billing\SeatLimitChecker;
use App\Entity\ActivationToken;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Http\RateLimitResponse;
use App\Notification\InvitationNotifier;
use App\Security\AuthSession;
use App\Security\CsrfGuard;
use App\Security\RequiresCompanyAdmin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One endpoint serves both `REGISTRATION_MODE=invite` (any logged-in user)
 * and `admin_only` (admin-only) — the spec's two invite-based modes differ
 * only in *who* may call this, not in the mechanism itself (see the Phase
 * 6g plan). `domain` mode (open self-registration) is a different flow
 * entirely — see SignupController — but the email-domain restriction
 * (`allowedEmailDomain`) applies here too, regardless of mode.
 */
class InviteController
{
    use RequiresCompanyAdmin;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly CsrfGuard $csrfGuard,
        private readonly InvitationNotifier $notifier,
        private readonly TranslatorInterface $translator,
        private readonly SeatLimitChecker $seatLimitChecker,
        #[Autowire(service: 'limiter.invite')]
        private readonly RateLimiterFactory $inviteLimiter,
    ) {
    }

    #[Route('/api/invites', name: 'invite_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->csrfGuard->assertValid($request);

        $inviter = $this->authSession->getCurrentUser($request);
        if (null === $inviter) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }
        $company = $inviter->getCompany();
        if ('admin_only' === $company->getRegistrationMode() && !$inviter->isAdmin()) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.admin_only_invite'));
        }

        // Keyed by the inviter's own account, not IP — this is an authenticated
        // action, and IP-keying would collectively throttle a whole office behind
        // one NAT for what's really a per-user action.
        $limit = $this->inviteLimiter->create($inviter->getId())->consume();
        if (!$limit->isAccepted()) {
            return RateLimitResponse::create($limit, $this->translator);
        }

        $email = $request->toArray()['email'] ?? null;
        if (!\is_string($email) || '' === $email) {
            return new JsonResponse(['error' => $this->translator->trans('errors.missing_email')], 400);
        }

        $allowedEmailDomain = $company->getAllowedEmailDomain();
        if ('' !== $allowedEmailDomain && !str_ends_with($email, '@'.$allowedEmailDomain)) {
            return new JsonResponse(['error' => $this->translator->trans('errors.email_domain_restricted', ['%domain%' => $allowedEmailDomain])], 400);
        }

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null !== $existing) {
            return new JsonResponse(['error' => $this->translator->trans('errors.email_already_registered')], 400);
        }

        // The seat-limit check and the token insert must land in the same transaction —
        // checking, then flushing separately, leaves a window where two concurrent
        // invites can both read the same not-yet-grown headcount and both pass, landing
        // the company one seat past its limit. wrapInTransaction() also gives
        // SeatLimitChecker a consistent view including this same request's own pending
        // token, not just committed ones. The callback returns null (never throws) on a
        // reached limit — wrapInTransaction() closes the EntityManager on any exception,
        // which a routine business-rule rejection must not trigger.
        $rawToken = $this->entityManager->wrapInTransaction(function () use ($email, $company, $inviter) {
            if ($this->seatLimitChecker->hasReachedLimit($company)) {
                return null;
            }

            // Admin status is only ever granted via the CLI bootstrap or the admin
            // panel's explicit "make admin" action (Phase 6g) — never implicitly
            // through an invite.
            [$activationToken, $rawToken] = ActivationToken::issue($email, $company);
            $this->entityManager->persist($activationToken);

            // Shares the token's own id — see InviteRecord's own docblock for why —
            // so ActivationController::complete() can stamp acceptedAt on this exact
            // row without a separate lookup table.
            $inviteRecord = new InviteRecord($activationToken->getId(), $email, $company, $inviter, $activationToken->getExpiresAt());
            $this->entityManager->persist($inviteRecord);

            return $rawToken;
        });

        if (null === $rawToken) {
            return new JsonResponse(['error' => $this->translator->trans('errors.seat_limit_reached')], 400);
        }

        $this->notifier->notifyInvited($email, $rawToken, $inviter);

        return new JsonResponse(['ok' => true], 201);
    }

    /**
     * Company-scoped invite history (GitHub issue #24) — every InviteRecord for the
     * admin's own company, newest first. Deliberately not paginated: same scale
     * assumption as AdminController::listUsers()'s own unpaginated findBy(), and
     * InviteRecord's own retention window (InviteRecord::RETENTION_DAYS) already
     * bounds how large this list can grow.
     */
    #[Route('/api/admin/invites', name: 'admin_invites_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);

        $this->authSession->closeForReading($request);

        // Fetch-joins invitedBy — toPayload() below reads getDeletedAt()/getDisplayName()/
        // getEmail() on it for every row, which would otherwise be an N+1 lazy-load per
        // invite (same "batch, not per-row" discipline AnketaController::bulk() and this
        // file's own PlatformAdminController sibling already follow).
        /** @var InviteRecord[] $inviteRecords */
        $inviteRecords = $this->entityManager->createQueryBuilder()
            ->select('i', 'invitedBy')
            ->from(InviteRecord::class, 'i')
            ->leftJoin('i.invitedBy', 'invitedBy')
            ->where('i.company = :company')
            ->orderBy('i.createdAt', 'DESC')
            ->setParameter('company', $admin->getCompany())
            ->getQuery()
            ->getResult();

        $now = new \DateTimeImmutable();

        return new JsonResponse(array_map(fn (InviteRecord $inviteRecord) => self::toPayload($inviteRecord, $now), $inviteRecords));
    }

    /**
     * Shared by PlatformAdminController::listInvites() too — a plain array-mapping
     * closure duplicated between the two, same "two instances doesn't clear the
     * extraction bar" precedent AdminController/PlatformAdminController's own
     * near-identical listUsers() mappings already established.
     *
     * @return array{id: string, email: string, invitedBy: array{name: string, email: string}|array{deleted: true}|null, createdAt: string, expiresAt: string, acceptedAt: string|null, status: string}
     */
    public static function toPayload(InviteRecord $inviteRecord, \DateTimeImmutable $now): array
    {
        $invitedBy = $inviteRecord->getInvitedBy();
        $invitedByPayload = match (true) {
            null === $invitedBy => null,
            null !== $invitedBy->getDeletedAt() => ['deleted' => true],
            default => ['name' => $invitedBy->getDisplayName(), 'email' => $invitedBy->getEmail()],
        };

        return [
            'id' => $inviteRecord->getId(),
            'email' => $inviteRecord->getEmail(),
            'invitedBy' => $invitedByPayload,
            'createdAt' => $inviteRecord->getCreatedAt()->format(\DATE_ATOM),
            'expiresAt' => $inviteRecord->getExpiresAt()->format(\DATE_ATOM),
            'acceptedAt' => $inviteRecord->getAcceptedAt()?->format(\DATE_ATOM),
            'status' => $inviteRecord->status($now),
        ];
    }
}
