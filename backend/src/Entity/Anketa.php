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

    /**
     * Shared blob, both sides can write to it (comments only ever get added,
     * not edited by someone else) — protected by commentsVersion, not a
     * per-side split like employeeBlob/managerBlob. See the Phase 6a plan.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $commentsBlob = null;

    #[ORM\Column(type: 'integer')]
    private int $commentsVersion = 0;

    /** Same shape as commentsBlob/commentsVersion — see the Phase 6b plan for why this isn't unified with it. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $outcomesBlob = null;

    #[ORM\Column(type: 'integer')]
    private int $outcomesVersion = 0;

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

    public function getCommentsBlob(): ?string
    {
        return $this->commentsBlob;
    }

    public function getCommentsVersion(): int
    {
        return $this->commentsVersion;
    }

    /** @return bool true if saved, false on a version mismatch (caller should return 409). */
    public function saveComments(string $blob, int $expectedVersion): bool
    {
        if ($expectedVersion !== $this->commentsVersion) {
            return false;
        }
        $this->commentsBlob = $blob;
        ++$this->commentsVersion;

        return true;
    }

    public function getOutcomesBlob(): ?string
    {
        return $this->outcomesBlob;
    }

    public function getOutcomesVersion(): int
    {
        return $this->outcomesVersion;
    }

    /** @return bool true if saved, false on a version mismatch (caller should return 409). */
    public function saveOutcomes(string $blob, int $expectedVersion): bool
    {
        if ($expectedVersion !== $this->outcomesVersion) {
            return false;
        }
        $this->outcomesBlob = $blob;
        ++$this->outcomesVersion;

        return true;
    }
}
