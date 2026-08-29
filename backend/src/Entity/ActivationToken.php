<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The single mechanism behind account creation — the CLI bootstrap
 * (bin/console app:create-activation-link) and the Phase 6g invite endpoint
 * both go through issue() below. Not an API Platform resource: accessed
 * only through the two custom activation controllers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activation_tokens')]
class ActivationToken
{
    public const TOKEN_TTL_HOURS = 24;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    /** SHA-256 of the actual token. The raw token is never stored — it only ever exists in the URL. */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    #[AllowPlaintext(reason: 'A SHA-256 hash, not the token itself.')]
    private string $tokenHash;

    #[ORM\Column(type: 'string', length: 255)]
    #[AllowPlaintext(reason: 'Same as User::$email — always plaintext.')]
    private string $email;

    /**
     * Which company the new account joins on completion — set once, at issue time, from
     * whoever/whatever is doing the inviting (the current session's own company for
     * InviteController, the single seeded company for the CLI bootstrap and
     * SignupController — see private/cloud-service-plan.md). ActivationController::complete()
     * always reads this rather than trusting anything client-submitted.
     */
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(type: 'boolean')]
    private bool $grantsAdmin;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tokenHash, string $email, Company $company, bool $grantsAdmin, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->tokenHash = $tokenHash;
        $this->email = $email;
        $this->company = $company;
        $this->grantsAdmin = $grantsAdmin;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return array{0: self, 1: string} the entity to persist, and the raw token — the
     *                                   latter only ever exists here and in the emailed/printed URL, never stored
     */
    public static function issue(string $email, Company $company, bool $grantsAdmin = false): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', self::TOKEN_TTL_HOURS));

        return [new self($tokenHash, $email, $company, $grantsAdmin, $expiresAt), $rawToken];
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function grantsAdmin(): bool
    {
        return $this->grantsAdmin;
    }

    public function isUsable(): bool
    {
        return null === $this->usedAt && $this->expiresAt > new \DateTimeImmutable();
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }
}
