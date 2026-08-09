<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A goal's title/description/target date/status — plaintext, unlike every
 * other piece of anketa content, per the spec's deliberate exception (see
 * CLAUDE.md's non-negotiable constraints). Progress checkpoints stay
 * encrypted (Anketa::goalCheckpointsBlob).
 *
 * `id` is a per-anketa snapshot row, not the logical goal: carry-forward
 * (Phase 6c plan) creates a new row for the new anketa on every cycle a
 * goal stays in_progress, sharing the same `goalUuid` but a fresh `id` and
 * `anketa`. `goalUuid` is what stays stable — it's what a checkpoint
 * references, and what a future report aggregates progress history by.
 */
#[ORM\Entity]
#[ORM\Table(name: 'goals')]
class Goal
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ACHIEVED = 'achieved';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_ACHIEVED, self::STATUS_CANCELLED];

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    /** Client-generated, stable across carried-forward rows — see the class docblock. */
    #[ORM\Column(type: 'string', length: 36)]
    private string $goalUuid;

    #[ORM\ManyToOne(targetEntity: Anketa::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Anketa $anketa;

    /** Only this user may edit or close this goal — server-enforced, unlike the rest of the app's ownership conventions. Preserved across carry-forward. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    #[ORM\Column(type: 'string', length: 255)]
    private string $title;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $targetDate;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $goalUuid,
        Anketa $anketa,
        User $author,
        string $title,
        ?string $description,
        ?\DateTimeImmutable $targetDate,
        string $status = self::STATUS_IN_PROGRESS,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->goalUuid = $goalUuid;
        $this->anketa = $anketa;
        $this->author = $author;
        $this->title = $title;
        $this->description = $description;
        $this->targetDate = $targetDate;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGoalUuid(): string
    {
        return $this->goalUuid;
    }

    public function getAnketa(): Anketa
    {
        return $this->anketa;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getTargetDate(): ?\DateTimeImmutable
    {
        return $this->targetDate;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAuthor(User $user): bool
    {
        return $this->author->getId() === $user->getId();
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }

    public function setTargetDate(?\DateTimeImmutable $targetDate): void
    {
        $this->targetDate = $targetDate;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
