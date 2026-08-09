<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A fully registered user: keys already generated client-side (see the
 * crypto model in the spec). Registration/invite flows are a later phase
 * and may introduce a separate pending-registration concept — this entity
 * only represents the end state.
 */
#[ORM\Entity]
#[ORM\Table(name: 'users')]
#[ApiResource(
    operations: [new GetCollection(), new Get()],
    normalizationContext: ['groups' => ['user:read']],
)]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    #[Groups(['user:read'])]
    private string $id;

    #[ORM\Column(type: 'string', length: 255, unique: true)]
    #[Groups(['user:read'])]
    private string $email;

    /**
     * The HKDF "auth" verifier — never the password, never the master-key.
     * Deliberately has no serialization group: must never be exposed via the API.
     */
    #[ORM\Column(type: 'string')]
    private string $authHash;

    #[ORM\Column(type: 'text')]
    #[Groups(['user:read'])]
    private string $publicKey;

    /**
     * The user's private key, encrypted client-side with their master-key.
     * Deliberately has no serialization group: must never be exposed via the API.
     */
    #[ORM\Column(type: 'text')]
    private string $encryptedPrivateKey;

    #[ORM\Column(type: 'datetime_immutable')]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $authHash, string $publicKey, string $encryptedPrivateKey)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->email = $email;
        $this->authHash = $authHash;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
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

    public function getAuthHash(): string
    {
        return $this->authHash;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function getEncryptedPrivateKey(): string
    {
        return $this->encryptedPrivateKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
