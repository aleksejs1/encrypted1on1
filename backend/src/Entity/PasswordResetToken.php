<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Near-identical to ActivationToken — same one-time-link shape (SHA-256-hashed
 * token, TTL, single-use), minus grantsAdmin (resetting a password never
 * changes admin status). See PasswordResetController for the flow this
 * powers, and the "password reset" plan for why this exists at all: it's
 * the one piece of the crypto model that was completely missing — a user
 * who forgets their password had no way back into their account.
 */
#[ORM\Entity]
#[ORM\Table(name: 'password_reset_tokens')]
class PasswordResetToken
{
    public const TOKEN_TTL_HOURS = 2;

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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $tokenHash, string $email, \DateTimeImmutable $expiresAt)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->tokenHash = $tokenHash;
        $this->email = $email;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return array{0: self, 1: string} the entity to persist, and the raw token — the
     *                                   latter only ever exists here and in the emailed URL, never stored
     */
    public static function issue(string $email): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = new \DateTimeImmutable(sprintf('+%d hours', self::TOKEN_TTL_HOURS));

        return [new self($tokenHash, $email, $expiresAt), $rawToken];
    }

    public function getEmail(): string
    {
        return $this->email;
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
