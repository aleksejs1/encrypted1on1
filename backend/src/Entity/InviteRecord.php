<?php

namespace App\Entity;

use App\Repository\InviteRecordRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin-facing invite history — deliberately separate from ActivationToken, which
 * app:cleanup-expired-tokens hard-deletes ActivationToken::RETENTION_DAYS_AFTER_EXPIRY
 * (14) days after expiry regardless of used/expired status (see that command's own
 * docblock). Coupling admin visibility to that table would mean growing its retention
 * to this table's 90 days for a second, unrelated reason. This entity carries no
 * tokenHash and is never used to redeem anything. It exists so `GET /api/admin/invites`/
 * `GET /api/platform-admin/invites` have something to read after the token itself is
 * long gone (see GitHub issue #24 for the full design discussion), and, since GitHub
 * issue #169, to tell an expired link to its address who to ask for a new invite
 * (invitedBy) and to hold the per-address renewal cooldown (renewalRequestedAt), on the
 * newest row for that address (InviteRenewal). An address with no row here at all can't
 * be renewed, so every invite path that should be renewable must write one.
 *
 * `id` deliberately shares the value of the ActivationToken it's issued alongside
 * (InviteController::create()/SignupController::signup() generate one via
 * ActivationToken::issue()/getId(), pass it here) so ActivationController::complete()
 * can find and stamp the matching row without a separate lookup table.
 *
 * Only two call sites write here: InviteController::create() (invitedBy = the
 * authenticated inviter) and SignupController::signup() (invitedBy = null, open
 * self-registration). The CLI bootstrap (CreateActivationLinkCommand) and cloud
 * self-service company creation (CompanyController) deliberately do not — see this
 * entity's own non-goals in GitHub issue #24's "Scope" section.
 *
 * The `company`/`invitedBy` foreign keys have no ON DELETE behavior (RESTRICT, the
 * DB default) — harmless today since nothing in this app ever hard-deletes a Company
 * or User row (User::delete() anonymizes in place; only test suites hard-delete their
 * own throwaway rows, and their teardowns purge invite_records first for exactly this
 * reason). Worth remembering if a real company-deletion feature is ever added: it
 * would need to purge/reassign invite_records first too, or the FK will reject the
 * delete outright rather than fail gracefully.
 */
#[ORM\Entity(repositoryClass: InviteRecordRepository::class)]
#[ORM\Table(name: 'invite_records')]
// AccountDeleter looks up rows by email (scrubbing on account deletion); the daily
// cleanup command deletes by createdAt (the retention cutoff, above) — both would
// otherwise be full-table scans as this table grows.
#[ORM\Index(columns: ['email'], name: 'idx_invite_records_email')]
#[ORM\Index(columns: ['createdAt'], name: 'idx_invite_records_created_at')]
class InviteRecord
{
    /**
     * How long a row survives past its own createdAt, independent of the invite's
     * 24h expiresAt — an admin needs to see "this expired and nobody used it" for
     * a while after expiry, not just up to it. Proposed default per GitHub issue
     * #24's Open Question 2; not yet configurable per company.
     */
    public const RETENTION_DAYS = 90;

    /** How long a renewal request blocks the next one for the same invite (GitHub issue #169). */
    public const RENEWAL_COOLDOWN_HOURS = 24;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 255)]
    #[AllowPlaintext(reason: 'Same as ActivationToken::$email — always plaintext — but retained longer: up to RETENTION_DAYS (90d) for a never-accepted invite, against ActivationToken\'s 24h TTL plus RETENTION_DAYS_AFTER_EXPIRY (14d). A deliberate tradeoff (admin-facing invite history needs to outlive the token itself), not an oversight — see GitHub issue #24.')]
    private string $email;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    /**
     * Null means self-registered (SignupController), not "sent by nobody" — the
     * frontend renders that case as "Self-registered", not a blank cell. Left
     * pointing at the inviter's User row even after that account is later deleted
     * (User::delete() anonymizes in place rather than removing the row) — the API
     * layer is responsible for rendering "Sender account deleted" once it sees
     * deletedAt set, rather than silently showing the anonymized placeholder address.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $invitedBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** Copied from the ActivationToken at issue time, so status is derivable even after that token row is gone. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    /** Stamped by ActivationController::complete() once the invited person actually activates. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    /**
     * When the invitee last asked, from the expired activation link, for a new invite
     * (GitHub issue #169). Written only by InviteRecordRepository::claimRenewalRequest(),
     * a conditional UPDATE, so two concurrent requests can't both notify the inviter.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $renewalRequestedAt = null;

    public function __construct(string $id, string $email, Company $company, ?User $invitedBy, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->email = $email;
        $this->company = $company;
        $this->invitedBy = $invitedBy;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function getRenewalRequestedAt(): ?\DateTimeImmutable
    {
        return $this->renewalRequestedAt;
    }

    /**
     * A renewal request made after this instant is still within its cooldown, the one
     * rule InviteRenewal (reading) and InviteRecordRepository::claimRenewalRequest()
     * (writing) both use.
     */
    public static function renewalCooldownStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d hours', self::RENEWAL_COOLDOWN_HOURS));
    }

    public function markAccepted(): void
    {
        $this->acceptedAt = new \DateTimeImmutable();
    }

    /** pending/accepted/expired, derived rather than stored — see this class's own docblock. */
    public function status(\DateTimeImmutable $now): string
    {
        if (null !== $this->acceptedAt) {
            return 'accepted';
        }

        return $this->expiresAt <= $now ? 'expired' : 'pending';
    }

    /**
     * Overwrites the recipient's email in place — mirrors User::delete()'s own
     * anonymization, so a deleted user's real address doesn't keep sitting in this
     * table for the rest of its retention window (see AccountDeleter).
     */
    public function scrubEmail(): void
    {
        $this->email = $this->scrubbedEmail();
    }

    /** True once scrubEmail() has replaced the address: there's nobody left to re-invite. */
    public function isScrubbed(): bool
    {
        return $this->email === $this->scrubbedEmail();
    }

    private function scrubbedEmail(): string
    {
        return User::scrubbedEmailFor($this->id);
    }
}
