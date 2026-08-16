<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The tenant boundary — see private/cloud-service-plan.md (not tracked in git) for the
 * full design writeup this implements Phase A of. Every self-hosted deployment gets
 * exactly one row here, auto-seeded by the migration that introduced this table; nothing
 * in Phase A/B creates a second one on its own — Phase B's CompanyController does, once
 * CLOUD_MODE is on. `registrationMode`/`allowedEmailDomain` are moved here from what used
 * to be global env-bound scalars (`config/services.php`) — same values, same meaning,
 * just now a per-company setting instead of a per-instance one.
 *
 * Deliberately not an ApiResource (see SerializationBoundaryTest). `name` still has no
 * setter — nothing mutates it after creation. `registrationMode`/`allowedEmailDomain` do,
 * via updateSettings() (AdminController's `PUT /api/admin/company-settings`) — lets a
 * company admin configure who can invite/self-register and the email-domain restriction
 * without needing raw SQL, closing the gap this class's docblock used to flag as
 * out of scope.
 *
 * Billing fields (Phase D) are real, but deliberately minimal — this app has no chosen
 * pricing tiers yet (see private/cloud-service-plan.md's own "not an engineering
 * decision" note), so planTier/seatLimit exist as mechanism, not as decided numbers.
 * seatLimit is nullable = unlimited, which is what the single self-hosted company (and
 * every company that existed before this migration) gets — self-hosted deployments never
 * had a seat concept and this change must not silently start capping them.
 */
#[ORM\Entity]
#[ORM\Table(name: 'companies')]
class Company
{
    /** Mirrors the tri-state InviteController/SignupController already validate against. */
    public const REGISTRATION_MODES = ['invite', 'domain', 'admin_only'];

    /**
     * Mirrors Stripe's own subscription status vocabulary (trialing is unused so far —
     * this build has no trial period, see private/cloud-service-plan.md's own "no
     * trial" decision — but kept here since a future Stripe subscription can genuinely
     * report it). 'active' is also this app's own placeholder default absent any real
     * subscription at all, not just Stripe's post-trial state.
     */
    public const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'canceled'];

    /** Statuses under which a company is suspended when applyStripeSubscriptionUpdate() applies them — non-payment suspends, it never deletes. */
    private const SUSPENDING_SUBSCRIPTION_STATUSES = ['past_due', 'canceled'];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    private string $registrationMode;

    /** Empty string means "no restriction" — same convention the old env var used. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $allowedEmailDomain;

    /**
     * Free-text label, not yet tied to any enforced behavior beyond seatLimit — no
     * pricing tiers are decided yet. Purely informational until they are.
     */
    #[ORM\Column(type: 'string', length: 40)]
    private string $planTier;

    /**
     * Null = unlimited (self-hosted's own company, and every company that predates this
     * column). CompanyController sets a real number on every newly self-service-created
     * company — see its own constant for why that number is a placeholder.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $seatLimit;

    #[ORM\Column(type: 'string', length: 20)]
    private string $subscriptionStatus;

    /** Never set in this build (no trial period) — structurally present for when one exists. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    /**
     * The actual enforcement gate (checked at login, mirroring User::$isBlocked) — kept
     * independent of subscriptionStatus rather than derived from it on every read, since
     * a platform admin can suspend a company for reasons that have nothing to do with
     * billing (see suspend()/unsuspend()) — applyStripeSubscriptionUpdate() is the one
     * path that changes both together, for the specific "non-payment suspends" case.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $registrationMode = 'invite',
        string $allowedEmailDomain = '',
        ?int $seatLimit = null,
        string $planTier = 'free',
    ) {
        if (!\in_array($registrationMode, self::REGISTRATION_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported registration mode "%s".', $registrationMode));
        }

        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->registrationMode = $registrationMode;
        $this->allowedEmailDomain = $allowedEmailDomain;
        $this->planTier = $planTier;
        $this->seatLimit = $seatLimit;
        $this->subscriptionStatus = 'active';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRegistrationMode(): string
    {
        return $this->registrationMode;
    }

    public function getAllowedEmailDomain(): string
    {
        return $this->allowedEmailDomain;
    }

    /** Same validation as the constructor — a company admin, not just the CLI bootstrap or CompanyController, can now set these. */
    public function updateSettings(string $registrationMode, string $allowedEmailDomain): void
    {
        if (!\in_array($registrationMode, self::REGISTRATION_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported registration mode "%s".', $registrationMode));
        }

        $this->registrationMode = $registrationMode;
        $this->allowedEmailDomain = $allowedEmailDomain;
    }

    public function getPlanTier(): string
    {
        return $this->planTier;
    }

    public function getSeatLimit(): ?int
    {
        return $this->seatLimit;
    }

    public function getSubscriptionStatus(): string
    {
        return $this->subscriptionStatus;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isSuspended(): bool
    {
        return null !== $this->suspendedAt;
    }

    public function getSuspendedAt(): ?\DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    /** The manual, billing-independent path — a platform admin acting directly (PlatformAdminController). */
    public function suspend(): void
    {
        $this->suspendedAt = new \DateTimeImmutable();
    }

    public function unsuspend(): void
    {
        $this->suspendedAt = null;
    }

    /**
     * The webhook-driven path (BillingController) — a real Stripe subscription event
     * updates the raw status/ids Stripe reports, and this decides suspension from that
     * status in the same place, so "which statuses mean suspended" lives in exactly one
     * spot rather than being duplicated in the webhook handler.
     */
    public function applyStripeSubscriptionUpdate(string $subscriptionStatus, ?string $stripeCustomerId, ?string $stripeSubscriptionId): void
    {
        if (!\in_array($subscriptionStatus, self::SUBSCRIPTION_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported subscription status "%s".', $subscriptionStatus));
        }

        $this->subscriptionStatus = $subscriptionStatus;
        $this->stripeCustomerId = $stripeCustomerId;
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        if (\in_array($subscriptionStatus, self::SUSPENDING_SUBSCRIPTION_STATUSES, true)) {
            $this->suspend();
        } else {
            $this->unsuspend();
        }
    }
}
