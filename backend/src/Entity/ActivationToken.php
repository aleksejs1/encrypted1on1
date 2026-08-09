<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The single mechanism behind account creation, whether that's the CLI
 * bootstrap of the first admin or (in a later phase) an emailed invite —
 * see bin/console app:create-activation-link. Not an API Platform resource:
 * accessed only through the two custom activation controllers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'activation_tokens')]
class ActivationToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    /** SHA-256 of the actual token. The raw token is never stored — it only ever exists in the URL. */
    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    #[ORM\Column(type: 'boolean')]
    private bool $grantsAdmin;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tokenHash, string $email, bool $grantsAdmin, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->tokenHash = $tokenHash;
        $this->email = $email;
        $this->grantsAdmin = $grantsAdmin;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getEmail(): string
    {
        return $this->email;
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
