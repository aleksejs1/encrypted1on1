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
     * When employeeSealedKey/managerSealedKey were last set — initialized to createdAt,
     * bumped by resealKeyFor() on a re-share. Compared against User::$publicKeyUpdatedAt
     * (password-reset plan, part 2) to compute whether a side's sealed key still matches
     * that participant's current public key, without the server ever needing to look at
     * the sealed key's own (opaque) contents.
     */
    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $employeeSealedKeyUpdatedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $managerSealedKeyUpdatedAt;

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

    /** Set once SendRemindersCommand (Phase 6e) has sent the day-before reminder batch for this anketa — guards against double-sending on a cron rerun. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    /** Set true only via the "cancel as missed" overdue action (Phase 6d) — skips the normal publish/discuss expectation but still auto-recreates the next anketa. */
    #[ORM\Column(type: 'boolean')]
    private bool $missed = false;

    /**
     * Days between meetings for this pair, set once on the pair's first anketa and
     * inherited by every later one (AnketaController::create()/archive() — see the
     * Phase 6d plan). Nullable only because anketas created before this phase shipped
     * have no periodicity on record.
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $periodicityDays = null;

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

    /** Goals' progress checkpoints — same shape again, still not unified (see the Phase 6b plan). Goal title/description/targetDate/status are NOT here — see Goal.php. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $goalCheckpointsBlob = null;

    #[ORM\Column(type: 'integer')]
    private int $goalCheckpointsVersion = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $employee,
        User $manager,
        \DateTimeImmutable $meetingDate,
        string $employeeSealedKey,
        string $managerSealedKey,
        int $periodicityDays,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->employee = $employee;
        $this->manager = $manager;
        $this->meetingDate = $meetingDate;
        $this->employeeSealedKey = $employeeSealedKey;
        $this->managerSealedKey = $managerSealedKey;
        $this->periodicityDays = $periodicityDays;
        $this->createdAt = new \DateTimeImmutable();
        $this->employeeSealedKeyUpdatedAt = $this->createdAt;
        $this->managerSealedKeyUpdatedAt = $this->createdAt;
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

    public function sealedKeyUpdatedAtFor(User $user): \DateTimeImmutable
    {
        return $user->getId() === $this->employee->getId() ? $this->employeeSealedKeyUpdatedAt : $this->managerSealedKeyUpdatedAt;
    }

    /**
     * Re-seals this anketa's key for $recipient (a participant, but never the caller
     * themselves — see AnketaController::reshareKey()) to their current public key,
     * after their old one stopped matching (most commonly: they went through a
     * password reset). The caller already did the actual crypto client-side — this
     * just stores the result and records when, so a future staleness check
     * ($recipient's User::$publicKeyUpdatedAt vs. this timestamp) reads as current.
     */
    public function resealKeyFor(User $recipient, string $newSealedKey): void
    {
        $now = new \DateTimeImmutable();
        if ($recipient->getId() === $this->employee->getId()) {
            $this->employeeSealedKey = $newSealedKey;
            $this->employeeSealedKeyUpdatedAt = $now;
        } else {
            $this->managerSealedKey = $newSealedKey;
            $this->managerSealedKeyUpdatedAt = $now;
        }
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

    /**
     * Account deletion (AuthController::deleteAccount()) — an unpublished side is
     * encrypted with its author's own master key and never seen by anyone else, exactly
     * what "delete my drafts" means. A *published* side is shared history the counterpart
     * already has access to, so it's left untouched — no-op here if $user is published,
     * matching "no cascade to the pair's anketas.".
     */
    public function clearUnpublishedDraftFor(User $user): void
    {
        if ($this->isPublished($user)) {
            return;
        }
        if ($this->isEmployee($user)) {
            $this->employeeBlob = null;
        } else {
            $this->managerBlob = null;
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

    public function archive(bool $missed = false): void
    {
        $this->archivedAt = new \DateTimeImmutable();
        $this->missed = $missed;
    }

    public function isMissed(): bool
    {
        return $this->missed;
    }

    public function getPeriodicityDays(): ?int
    {
        return $this->periodicityDays;
    }

    public function reschedule(\DateTimeImmutable $meetingDate): void
    {
        $this->meetingDate = $meetingDate;
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

    /** Seeds an initial outcomesBlob at creation time (carry-forward, Phase 6c) without touching outcomesVersion — it stays 0, same as a freshly created anketa with no blob at all. */
    public function seedOutcomes(string $blob): void
    {
        $this->outcomesBlob = $blob;
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

    public function getGoalCheckpointsBlob(): ?string
    {
        return $this->goalCheckpointsBlob;
    }

    public function getGoalCheckpointsVersion(): int
    {
        return $this->goalCheckpointsVersion;
    }

    /** @return bool true if saved, false on a version mismatch (caller should return 409). */
    public function saveGoalCheckpoints(string $blob, int $expectedVersion): bool
    {
        if ($expectedVersion !== $this->goalCheckpointsVersion) {
            return false;
        }
        $this->goalCheckpointsBlob = $blob;
        ++$this->goalCheckpointsVersion;

        return true;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function markReminderSent(): void
    {
        $this->reminderSentAt = new \DateTimeImmutable();
    }
}
