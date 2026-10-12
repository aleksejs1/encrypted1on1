<?php

namespace App\Entity;

use App\Billing\Plan;
use App\Billing\SubscriptionOutcome;
use App\Billing\SubscriptionSnapshot;
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
 * Billing fields: planTier names the plan (App\Billing\Plan has the names and each
 * plan's seat limit) and seatLimit is the number actually enforced. The two are stored
 * separately and can differ: a platform admin can change a company's limit by hand, and
 * a company keeps the limit it was given when the plan's number later changes.
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
     * The four values `subscriptionStatus` holds. Three are Stripe's own words for a
     * subscription that keeps the plan; `canceled` stands for every other Stripe status
     * (applySubscription()). `active` is also the placeholder of a company that never had
     * a subscription: stripeSubscriptionId tells the two apart. `trialing` is unused so
     * far (no trial period), but a Stripe subscription can report it.
     */
    public const SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due', 'canceled'];

    /**
     * A subscription in one of these keeps the company on Plus. `past_due` is among them
     * on purpose: Stripe retries a failed payment for about two weeks, and the company
     * keeps what it had while it does. Any other status, Stripe's `unpaid`, `incomplete`
     * and `paused` included, is stored as `canceled` and means Free.
     */
    private const PAYING_SUBSCRIPTION_STATUSES = ['trialing', 'active', 'past_due'];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[AllowPlaintext(reason: 'Company/organization name — operational metadata, not anketa content.')]
    private string $name;

    #[ORM\Column(type: 'string', length: 20)]
    #[AllowPlaintext(reason: 'Enum-like registration-mode setting, not content.')]
    private string $registrationMode;

    /** Empty string means "no restriction" — same convention the old env var used. */
    #[ORM\Column(type: 'string', length: 255)]
    #[AllowPlaintext(reason: 'Email-domain policy setting, not content.')]
    private string $allowedEmailDomain;

    /**
     * Plan::FREE, Plan::PLUS, or any other name for a plan set by hand. No access check
     * reads it (the enforced number is seatLimit); applySubscription() reads it to tell
     * a plan it may change from one it must leave alone.
     */
    #[ORM\Column(type: 'string', length: 40)]
    #[AllowPlaintext(reason: 'Billing plan label, not content.')]
    private string $planTier;

    /**
     * Null = unlimited (self-hosted's own company, and every company that predates this
     * column). CompanyController gives every self-service-created company the Free
     * plan's limit.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $seatLimit;

    #[ORM\Column(type: 'string', length: 20)]
    #[AllowPlaintext(reason: 'Mirrors Stripe subscription status vocabulary, not content.')]
    private string $subscriptionStatus;

    /** Never set in this build (no trial period) — structurally present for when one exists. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    /**
     * The actual enforcement gate (checked at login, mirroring User::$isBlocked). Set
     * and lifted by a platform admin only (suspend()/unsuspend()). It has nothing to do
     * with subscriptionStatus: not paying never suspends a company, see
     * applySubscription().
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $suspendedAt = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[AllowPlaintext(reason: 'A Stripe-issued identifier, not content.')]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[AllowPlaintext(reason: 'A Stripe-issued identifier, not content.')]
    private ?string $stripeSubscriptionId = null;

    /**
     * The end of the subscription's current period, as Stripe last reported it: the next
     * payment date of one that is paid, and for a `past_due` one the end of a period
     * nobody paid for yet, which is no "next payment". Stored so the Plan
     * page doesn't depend on Stripe being reachable. Null without a running subscription.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $subscriptionPeriodEndsAt = null;

    /**
     * When a cancellation already scheduled takes effect (Stripe's `cancel_at`, which it
     * also fills for a cancellation at period end, so no separate flag). Null while the
     * subscription renews, and once it has ended.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $subscriptionCancelsAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $registrationMode = 'invite',
        string $allowedEmailDomain = '',
        ?int $seatLimit = null,
        string $planTier = Plan::FREE,
    ) {
        if (!\in_array($registrationMode, self::REGISTRATION_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported registration mode "%s".', $registrationMode));
        }

        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->registrationMode = $registrationMode;
        $this->allowedEmailDomain = $allowedEmailDomain;
        $this->planTier = $planTier;
        $this->setSeatLimit($seatLimit);
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

    /** Platform-admin only (PlatformAdminController) — self-service company creation (CompanyController) is the only other place this is set. Null = unlimited. */
    public function setSeatLimit(?int $seatLimit): void
    {
        if (null !== $seatLimit && $seatLimit < 1) {
            throw new \InvalidArgumentException('seatLimit must be null or a positive integer.');
        }

        $this->seatLimit = $seatLimit;
    }

    public function getSubscriptionStatus(): string
    {
        return $this->subscriptionStatus;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function getSubscriptionPeriodEndsAt(): ?\DateTimeImmutable
    {
        return $this->subscriptionPeriodEndsAt;
    }

    public function getSubscriptionCancelsAt(): ?\DateTimeImmutable
    {
        return $this->subscriptionCancelsAt;
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
     * Brings the company in line with a subscription as Stripe reports it now. The one
     * place that says what a subscription means for a company.
     *
     * Whose subscription it is comes first. The company's own is the one stored on it. A
     * paying one replaces an own one that no longer pays (the company subscribed again).
     * One that isn't paying and isn't the company's own changes nothing: it never
     * started, or it is an old one reported late. A second paying one, while the
     * company's own pays too, changes nothing either; the caller reports it.
     *
     * Which company a subscription belongs to is the caller's question, answered before
     * this is called (the snapshot's own company tag is not checked here).
     *
     * Then, for a subscription that is applied:
     *
     * - A plan managed by payment (Plan::FREE, Plan::PLUS) follows the subscription: Plus
     *   while it is paying, Free otherwise. A plan with any other name was set by hand
     *   and stays, with its seat limit.
     * - The seat limit changes only together with the plan, so a limit a platform admin
     *   set by hand survives every event that keeps the plan. A company without a limit
     *   never gets one here.
     * - Going back to Free removes nobody: a company over the Free limit only can't
     *   invite (SeatLimitChecker).
     * - Suspension is not touched in either direction. Not paying costs the plan, never
     *   the access to one's own 1:1s; suspend()/unsuspend() stay a platform admin's.
     */
    public function applySubscription(SubscriptionSnapshot $subscription, Plan $plan): SubscriptionOutcome
    {
        $paying = \in_array($subscription->status, self::PAYING_SUBSCRIPTION_STATUSES, true);

        if ($subscription->id !== $this->stripeSubscriptionId) {
            if (!$paying) {
                return SubscriptionOutcome::IgnoredNotOurs;
            }
            if ($this->hasPayingSubscription()) {
                return SubscriptionOutcome::IgnoredSecondPaying;
            }
        }

        $this->subscriptionStatus = $paying ? $subscription->status : 'canceled';
        $this->stripeSubscriptionId = $subscription->id;
        $this->stripeCustomerId = $subscription->customerId ?? $this->stripeCustomerId;
        // The dates describe a subscription that is running; an ended one has none.
        $this->subscriptionPeriodEndsAt = $paying ? $subscription->periodEndsAt : null;
        $this->subscriptionCancelsAt = $paying ? $subscription->cancelsAt : null;

        $this->followPlan($paying ? Plan::PLUS : Plan::FREE, $plan);

        return SubscriptionOutcome::Applied;
    }

    private function followPlan(string $planTier, Plan $plan): void
    {
        if (!Plan::isManagedByPayment($this->planTier) || $planTier === $this->planTier) {
            return;
        }

        $this->planTier = $planTier;
        if (null !== $this->seatLimit) {
            $this->seatLimit = $plan->seatLimit($planTier);
        }
    }

    /** By what is stored here; `subscriptionStatus` alone is `active` for a company that never subscribed. */
    private function hasPayingSubscription(): bool
    {
        return null !== $this->stripeSubscriptionId
            && \in_array($this->subscriptionStatus, self::PAYING_SUBSCRIPTION_STATUSES, true);
    }
}
