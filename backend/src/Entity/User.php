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
    /**
     * The 4 launch-required locales (spec: "обязательны на старте") — single source of
     * truth wherever a locale value needs validating (Phase 6i plan). Matches the
     * frontend's SUPPORTED_LOCALES (frontend/src/i18n/index.ts) but this list exists
     * independently since the two sides validate different things at different times.
     */
    public const SUPPORTED_LOCALES = ['en', 'ru', 'lv', 'es'];

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

    #[ORM\Column(type: 'boolean')]
    #[Groups(['user:read'])]
    private bool $isAdmin;

    /** Reversible, login-only gate (Phase 6g) — doesn't touch data, unlike account deletion. */
    #[ORM\Column(type: 'boolean')]
    #[Groups(['user:read'])]
    private bool $isBlocked = false;

    /**
     * Which language outbound emails to this user are sent in (Phase 6i) — deliberately
     * *not* what drives the frontend's displayed language (that's a client-only
     * localStorage preference, Phase 6h); this only answers "what language should an
     * email to this person be in." No serialization group — not needed by the frontend,
     * which never reads it back (see the Phase 6i plan for why that's a one-way flow).
     */
    #[ORM\Column(type: 'string', length: 5)]
    private string $locale = 'en';

    public function __construct(
        string $email,
        string $authHash,
        string $publicKey,
        string $encryptedPrivateKey,
        bool $isAdmin = false,
        string $locale = 'en',
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->email = $email;
        $this->authHash = $authHash;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->isAdmin = $isAdmin;
        $this->locale = \in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : 'en';
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

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setAdmin(bool $isAdmin): void
    {
        $this->isAdmin = $isAdmin;
    }

    public function setBlocked(bool $isBlocked): void
    {
        $this->isBlocked = $isBlocked;
    }

    /**
     * Replaces the auth verifier and both key-related fields at once — the one
     * mutation path outside the constructor for these three, used only by
     * password reset (PasswordResetController). A fresh keypair means every
     * anketa sealed under the old public key becomes unreadable until
     * re-shared (see the password-reset plan) — that's a client-side
     * consequence, not something this method needs to know about.
     */
    public function resetCredentials(string $authHash, string $publicKey, string $encryptedPrivateKey): void
    {
        $this->authHash = $authHash;
        $this->publicKey = $publicKey;
        $this->encryptedPrivateKey = $encryptedPrivateKey;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /** @throws \InvalidArgumentException if $locale isn't one of self::SUPPORTED_LOCALES — callers validate before calling this, this is a last-resort guard. */
    public function setLocale(string $locale): void
    {
        if (!\in_array($locale, self::SUPPORTED_LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported locale "%s".', $locale));
        }
        $this->locale = $locale;
    }
}
