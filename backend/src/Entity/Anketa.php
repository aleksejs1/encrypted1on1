<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One 1:1 meeting between two participants. Not an API Platform resource —
 * every operation (side-specific field selection, ownership checks,
 * one-way publish) has real logic, same reasoning as the Phase 4 auth
 * endpoints. See the Phase 5 plan for the crypto shape this implements.
 */
#[ORM\Entity]
#[ORM\Table(name: 'anketas')]
class Anketa
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $employee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $manager;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $meetingDate;

    /** crypto_box_seal of the anketa key to the employee's public key. */
    #[ORM\Column(type: 'text')]
    private string $employeeSealedKey;

    /** crypto_box_seal of the anketa key to the manager's public key. */
    #[ORM\Column(type: 'text')]
    private string $managerSealedKey;

    /**
     * Master-key-encrypted (draft) or anketa-key-encrypted (published) — the
     * server can't tell which; only publishedAt distinguishes them. See the
     * Phase 5 plan.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $employeeBlob = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $employeePublishedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $managerBlob = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $managerPublishedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $employee,
        User $manager,
        \DateTimeImmutable $meetingDate,
        string $employeeSealedKey,
        string $managerSealedKey,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->employee = $employee;
        $this->manager = $manager;
        $this->meetingDate = $meetingDate;
        $this->employeeSealedKey = $employeeSealedKey;
        $this->managerSealedKey = $managerSealedKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmployee(): User
    {
        return $this->employee;
    }

    public function getManager(): User
    {
        return $this->manager;
    }

    public function getMeetingDate(): \DateTimeImmutable
    {
        return $this->meetingDate;
    }

    public function isParticipant(User $user): bool
    {
        return $user->getId() === $this->employee->getId() || $user->getId() === $this->manager->getId();
    }

    public function sealedKeyFor(User $user): string
    {
        return $user->getId() === $this->employee->getId() ? $this->employeeSealedKey : $this->managerSealedKey;
    }

    public function isEmployee(User $user): bool
    {
        return $user->getId() === $this->employee->getId();
    }

    public function getEmployeeBlob(): ?string
    {
        return $this->employeeBlob;
    }

    public function getEmployeePublishedAt(): ?\DateTimeImmutable
    {
        return $this->employeePublishedAt;
    }

    public function getManagerBlob(): ?string
    {
        return $this->managerBlob;
    }

    public function getManagerPublishedAt(): ?\DateTimeImmutable
    {
        return $this->managerPublishedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPublished(User $user): bool
    {
        return null !== ($this->isEmployee($user) ? $this->employeePublishedAt : $this->managerPublishedAt);
    }

    public function saveDraft(User $user, string $blob): void
    {
        if ($this->isEmployee($user)) {
            $this->employeeBlob = $blob;
        } else {
            $this->managerBlob = $blob;
        }
    }

    public function publish(User $user, string $blob): void
    {
        if ($this->isEmployee($user)) {
            $this->employeeBlob = $blob;
            $this->employeePublishedAt = new \DateTimeImmutable();
        } else {
            $this->managerBlob = $blob;
            $this->managerPublishedAt = new \DateTimeImmutable();
        }
    }

    public function archive(): void
    {
        $this->archivedAt = new \DateTimeImmutable();
    }
}
