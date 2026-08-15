<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The tenant boundary — see private/cloud-service-plan.md (not tracked in git) for the
 * full design writeup this implements Phase A of. Every self-hosted deployment gets
 * exactly one row here, auto-seeded by the migration that introduced this table; nothing
 * in this phase creates a second one. `registrationMode`/`allowedEmailDomain` are moved
 * here from what used to be global env-bound scalars (`config/services.php`) — same
 * values, same meaning, just now a per-company setting instead of a per-instance one.
 *
 * Deliberately not an ApiResource (see SerializationBoundaryTest) and has no setters yet
 * for registrationMode/allowedEmailDomain/name: nothing in this phase mutates them after
 * creation — that's a company-admin-settings endpoint, out of scope here (see the plan's
 * phasing). Billing/plan/suspension fields are also deliberately absent — a different,
 * later phase.
 */
#[ORM\Entity]
#[ORM\Table(name: 'companies')]
class Company
{
    /** Mirrors the tri-state InviteController/SignupController already validate against. */
    public const REGISTRATION_MODES = ['invite', 'domain', 'admin_only'];

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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $registrationMode = 'invite', string $allowedEmailDomain = '')
    {
        if (!\in_array($registrationMode, self::REGISTRATION_MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported registration mode "%s".', $registrationMode));
        }

        $this->id = Uuid::v7()->toRfc4122();
        $this->name = $name;
        $this->registrationMode = $registrationMode;
        $this->allowedEmailDomain = $allowedEmailDomain;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
