<?php

namespace App\Entity;

use App\Repository\CustomTemplateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A company-authored anketa template (GitHub issue #142, part C2 of the design in #133
 * §5.2): the pointer to its latest version plus its archived state. The content itself
 * (name, description, question definition) lives in CustomTemplateVersion rows, which
 * are append-only: every save adds one, so an anketa created on a version keeps
 * rendering it after later edits.
 *
 * `currentVersion` only ever moves forward through
 * CustomTemplateRepository::bumpVersionIfCurrent(), a conditional UPDATE, so two admins
 * saving at once can't both append the same version number; this entity has no setter
 * for it. `createdBy` survives account deletion (User::delete() anonymizes in place).
 * The `company`/`createdBy` foreign keys have no ON DELETE behavior, like
 * InviteRecord's: nothing hard-deletes a company or user row.
 */
#[ORM\Entity(repositoryClass: CustomTemplateRepository::class)]
#[ORM\Table(name: 'custom_templates')]
class CustomTemplate
{
    /**
     * Per company, archived ones included (#133 §6): self-service company creation
     * means anyone can become an admin, and versions are never deleted, so the
     * library has to be bounded.
     */
    public const MAX_PER_COMPANY = 50;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(type: 'integer')]
    private int $currentVersion;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** When a version was last added; archiving and restoring don't touch it. */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $createdBy)
    {
        $this->id = Uuid::v7()->toRfc4122();
        $this->company = $createdBy->getCompany();
        $this->currentVersion = 1;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getCurrentVersion(): int
    {
        return $this->currentVersion;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function setArchived(bool $archived): void
    {
        if ($archived !== $this->isArchived()) {
            $this->archivedAt = $archived ? new \DateTimeImmutable() : null;
        }
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
