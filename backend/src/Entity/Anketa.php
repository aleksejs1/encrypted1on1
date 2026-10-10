<?php

namespace App\Entity;

use App\Repository\AnketaRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One 1:1 meeting between two participants. Not an API Platform resource —
 * every operation (side-specific field selection, ownership checks,
 * one-way publish) has real logic, same reasoning as the Phase 4 auth
 * endpoints. See the Phase 5 plan for the crypto shape this implements.
 */
#[ORM\Entity(repositoryClass: AnketaRepository::class)]
#[ORM\Table(name: 'anketas')]
// Covers list()/bulk()'s `WHERE employee = :u OR manager = :u ORDER BY meetingDate DESC` —
// a composite index lets either branch of the OR use it for both the filter and the sort.
#[ORM\Index(columns: ['employee_id', 'manager_id', 'meetingDate'], name: 'idx_anketas_employee_manager_meeting_date')]
// Covers AnketaRepository::findDueForReminder()'s daily `WHERE archivedAt IS NULL
// AND meetingDate >= :start AND meetingDate < :end` (plus a reminderMeetingDay check on the few rows left),
// and findDueForFollowUp()'s, the same shape.
#[ORM\Index(columns: ['archivedAt', 'meetingDate'], name: 'idx_anketas_archived_meeting_date')]
class Anketa
{
    /**
     * The question-set template version this anketa was created against
     * (frontend/src/anketa/questions.ts's `getQuestionsForSide()`) — bumped
     * whenever the question set changes in a way that affects existing
     * answers (e.g. adding options to a checkbox field), so an anketa's
     * fields stay stable for its whole life instead of retroactively
     * changing shape underneath already-published answers. Every anketa is
     * created at CURRENT_FORM_VERSION (see the constructor); older rows keep
     * whatever version they were created with. Not user- or company-facing
     * yet — the spec's eventual per-company/per-user custom forms would
     * likely replace this with a real form-definition reference, but until
     * that's a real product decision this is the simplest thing that lets
     * the one global form change over time without breaking old anketas.
     */
    public const int CURRENT_FORM_VERSION = 3;

    /**
     * Which built-in question-set template this anketa uses (e.g. a different
     * agenda for a first 1:1 vs. a regular check-in vs. a career-growth
     * conversation) — a classifier the server needs to pick which question set
     * to serve, never an answer to any question. Same category as
     * `meetingDate`/`periodicityDays`/`formVersion`, all three already
     * plaintext on this entity for the identical reason (the server runs the
     * product using them: scheduling, auto-recreating cycles, serving the
     * right form) — not the separate, narrower Goal title/description/status/
     * target-date exception CLAUDE.md's non-negotiable constraints carve out
     * for actual content, which this doesn't touch or extend. A template
     * choice is never exposed in any admin/company report (this repo's own
     * `AllowPlaintext` isn't a license to surface a plaintext field just
     * because it exists) — see
     * private/anketa-meeting-templates-proposal.md §3 (not tracked in git,
     * this repo's own established place for this kind of product-decision
     * writeup) for the full accounting, including why that admin-visibility
     * restriction specifically matters here. Not every key is equally neutral: a
     * `'support_checkin'` anketa does hint at why a pair met, which is accepted and
     * disclosed in docs/encryption.md's threat model rather than hidden behind the
     * "classifier" framing. More are added one at a time as their own
     * template lands (see GitHub issue #104 for `'onboarding'`, the first). Must match
     * `ANKETA_TEMPLATES` in `frontend/src/anketa/questions.ts` —
     * `frontend/src/anketa/questions.test.ts` cross-checks the two lists by reading this
     * file, and `AnketaTest` checks every key here has an explicit
     * `NEXT_CYCLE_TEMPLATE_KEY` entry.
     *
     * `'custom'` (GitHub issue #144) is not a question set of its own: it means the
     * anketa uses a company template's version, `$customTemplateVersion`, which is set
     * if and only if the key is `'custom'` (see the constructor). The frontend's
     * cross-check compares this list with `[...ANKETA_TEMPLATES, 'custom']`, reading
     * it as a literal array of strings (so no `self::CUSTOM_TEMPLATE_KEY` here).
     */
    public const TEMPLATE_KEYS = ['regular', 'lightweight', 'onboarding', 'career_growth', 'support_checkin', 'custom'];

    /** An anketa on a company template's version (GitHub issue #144, #133 §5.3). */
    public const CUSTOM_TEMPLATE_KEY = 'custom';

    /** The template a new anketa gets when none is explicitly chosen — one named
     * constant instead of the literal `'regular'` repeated across this class,
     * `CreateAnketaRequest`, and `AnketaLifecycleService`'s two creation methods, so
     * changing the default later is one edit, not a search for every copy. */
    public const DEFAULT_TEMPLATE_KEY = 'regular';

    /**
     * What `AnketaLifecycleService::createNextAnketa()` (the auto-recreation on
     * `archive()`) should stamp the next cycle's anketa with, keyed by the
     * just-archived anketa's own `templateKey` — deliberately **not** a blind carry-
     * forward the way `periodicityDays` is. A non-recurring template auto-recreating itself
     * forever would be wrong (see the per-template notes below).
     * `'regular' => 'regular'` was this map's only
     * real entry before a second template existed, per
     * private/anketa-meeting-templates-proposal.md §7.3/§14 (not tracked in git).
     * `'onboarding'` (GitHub issue #104) is the first template to actually exercise the
     * non-recurring branch: a first 1:1 only happens once, so its auto-recreated successor
     * degrades back to `'regular'` rather than repeating the onboarding questions
     * forever for that pair. `'career_growth'` (GitHub issue #105) degrades to
     * `'regular'` too, deliberately deviating from that issue's own "repeats itself"
     * sketch: the next cycle is scheduled at the pair's inherited `periodicityDays`,
     * which the UI only ever sets to 7/14/30, so self-recurrence would turn a
     * quarterly career conversation into a weekly/monthly one and permanently replace
     * the pair's regular check-in. See
     * docs/decisions/2026-09-23-career-growth-template-does-not-recur.md.
     * `'support_checkin'` (GitHub issue #106) degrades to `'regular'` for the second
     * half of that reason, deviating from its issue's "repeats itself" sketch too: a
     * weekly/biweekly support check-in is a fine cadence, but self-recurrence would
     * still keep the pair on it indefinitely, until someone noticed and switched back
     * by hand. See docs/decisions/2026-09-23-support-checkin-template-does-not-recur.md.
     * `'lightweight'` (GitHub issue #208) is the one built-in template besides
     * `'regular'` that repeats itself: it is a pair's regular check-in in a shorter
     * form, not a phase that ends, so staying on it is the point. See
     * docs/decisions/2026-10-10-lightweight-template.md.
     */
    private const NEXT_CYCLE_TEMPLATE_KEY = [
        'regular' => 'regular',
        'lightweight' => 'lightweight',
        'onboarding' => 'regular',
        'career_growth' => 'regular',
        'support_checkin' => 'regular',
        // Only so every key has an entry: a custom anketa's successor comes from
        // AnketaLifecycleService::defaultNextTemplate(), which resolves the template
        // (or falls back to Regular once it's archived). Nothing else may ask this map
        // for 'custom', since a 'custom' key alone can't create an anketa.
        self::CUSTOM_TEMPLATE_KEY => self::CUSTOM_TEMPLATE_KEY,
    ];

    /**
     * What the next auto-recreated cycle should use after an anketa created with
     * $templateKey archives — see NEXT_CYCLE_TEMPLATE_KEY's own docblock. A key with
     * no entry in the map (stale data from a template that's since been retired,
     * or a future key this map hasn't been extended for yet) degrades to
     * DEFAULT_TEMPLATE_KEY rather than throwing, the same defensive fallback
     * `frontend/src/anketa/questions.ts::getQuestionsForSide()` already uses for an
     * unrecognized templateKey.
     */
    public static function nextCycleTemplateKeyFor(string $templateKey): string
    {
        return self::NEXT_CYCLE_TEMPLATE_KEY[$templateKey] ?? self::DEFAULT_TEMPLATE_KEY;
    }

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $employee;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $manager;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

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
     * Draft-key-encrypted (unpublished; the author's own key, derived from their
     * private key client-side — older drafts may still be under their master key) or
     * anketa-key-encrypted (published) — the
     * server can't tell which; only publishedAt distinguishes them. See the
     * Phase 5 plan.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $employeeBlob = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $employeePublishedAt = null;

    /**
     * Guards updateAnswers() the same way commentsVersion/outcomesVersion/goalCheckpointsVersion
     * guard their own blobs — but unlike those, employeeBlob/managerBlob are never written by
     * both participants (only the employee ever writes employeeBlob), so a version conflict here
     * can only happen against the *same* user's own other tab, never against the counterpart.
     * Deliberately never touched by publish() itself — see
     * docs/decisions/2026-09-07-editable-published-anketa-answers.md for why an edit must not
     * look like a fresh publish.
     */
    #[ORM\Column(type: 'integer')]
    private int $employeeBlobVersion = 0;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $managerBlob = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $managerPublishedAt = null;

    #[ORM\Column(type: 'integer')]
    private int $managerBlobVersion = 0;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * When SendRemindersCommand (Phase 6e) last claimed this anketa's reminder batch —
     * just before sending it, even if every participant has opted out or the send then
     * failed, so it isn't proof an email went out. See reminderMeetingDay.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    /**
     * The meeting day (UTC) that reminder was for (GitHub issue #167). A reminder is due
     * while this differs from the meeting's current day, so moving the meeting to another
     * day makes a new one due and moving it back doesn't, with no reset to get right.
     * The meeting's stored date is the picked calendar day at midnight, so its date part is
     * the day. Stamped by AnketaRepository::claimReminder() before the emails go out.
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $reminderMeetingDay = null;

    /**
     * The meeting day (UTC) the "did your 1:1 happen?" follow-up email was for (GitHub
     * issue #202), the same scheme as reminderMeetingDay: a follow-up is due while this
     * differs from the meeting's current day, so a meeting moved after its follow-up is
     * due again for its new day. Stamped by AnketaRepository::claimFollowUp().
     */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $followUpMeetingDay = null;

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
     * Shared blob, both sides can write to it — protected by commentsVersion,
     * not a per-side split like employeeBlob/managerBlob. See the Phase 6a
     * plan. The server never inspects blob contents, so "can only edit/delete
     * your own comment" is enforced client-side (frontend/src/anketa/comments.ts).
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

    /**
     * Question IDs marked as discussed during the meeting (GitHub issue #168), encrypted
     * under anketaKey. Written only by AnketaRepository::saveDiscussedIfVersion(), an
     * atomic conditional UPDATE, so there's no setter here. DB-level default for the
     * same populated-table reason as templateKey's.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $discussedBlob = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $discussedVersion = 0;

    /**
     * The shared "Topics to discuss" list (GitHub issue #206), encrypted under anketaKey:
     * either participant adds topics at any time, before or after publishing. Written
     * only by AnketaRepository::saveTopicsIfVersion(), like discussedBlob, apart from
     * seedTopics() at creation. DB-level default for the same reason as discussedVersion's.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $topicsBlob = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $topicsVersion = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'integer')]
    private int $formVersion;

    /** See TEMPLATE_KEYS's own docblock. DB-level default backfills every pre-existing
     * row for free on migration and, per docs/deployment.md's own documented MySQL
     * populated-table footgun (a bare NOT NULL silently zero-value-backfills instead of
     * rejecting), is what makes the auto-generated MySQL migration safe as-is. */
    #[ORM\Column(type: 'string', length: 40, options: ['default' => 'regular'])]
    #[AllowPlaintext(reason: 'Which built-in question-set template this anketa uses — a classifier like formVersion/meetingDate, never anketa content, though some keys hint at why a pair met (docs/encryption.md). See TEMPLATE_KEYS\'s own docblock.')]
    private string $templateKey;

    /**
     * Set once, at creation, when the anketa was created by hand while the pair already
     * had another open one (typically an ad-hoc template next to the auto-created regular
     * anketa) — AnketaController::create(). A one-off anketa gets no carry-forward and
     * never auto-recreates a successor (AnketaLifecycleService::shouldCreateNext()), so a
     * pair's chain can't fork into two. Persisted rather than re-derived at archive time
     * from "does the pair have another open anketa right now", which would let the
     * regular anketa's own successor be suppressed by the one-off — see GitHub issue #111
     * and docs/decisions/2026-09-23-one-open-anketa-chain-per-pair.md. DB-level default
     * for the same populated-table reason as templateKey's. Not the same thing as a
     * non-recurring *template* (NEXT_CYCLE_TEMPLATE_KEY): that decides which template a
     * chain anketa's successor gets; this decides whether there's a successor at all.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $oneOff;

    /**
     * The company template version this anketa renders, set if and only if templateKey
     * is 'custom' (GitHub issue #144, #133 §5.3), and never changed afterwards: versions
     * are immutable, so later edits or archiving of the template leave this anketa as it
     * was created. A plaintext reference, like templateKey: the server can see which of
     * the company's templates, by name, each anketa uses (maintainer decision D4, see
     * docs/encryption.md). Versions are never deleted, so it can't dangle.
     */
    #[ORM\ManyToOne(targetEntity: CustomTemplateVersion::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CustomTemplateVersion $customTemplateVersion;

    public function __construct(
        User $employee,
        User $manager,
        \DateTimeImmutable $meetingDate,
        string $employeeSealedKey,
        string $managerSealedKey,
        int $periodicityDays,
        string $templateKey = self::DEFAULT_TEMPLATE_KEY,
        bool $oneOff = false,
        ?CustomTemplateVersion $customTemplateVersion = null,
    ) {
        $employeeCompany = $employee->getCompany();
        $managerCompany = $manager->getCompany();
        if ($employeeCompany !== $managerCompany && $employeeCompany->getId() !== $managerCompany->getId()) {
            throw new \InvalidArgumentException('Employee and manager must belong to the same company.');
        }
        if ((self::CUSTOM_TEMPLATE_KEY === $templateKey) !== (null !== $customTemplateVersion)) {
            throw new \InvalidArgumentException('A template version is required for the custom template key, and only for it.');
        }
        if (null !== $customTemplateVersion && $customTemplateVersion->getCompany()->getId() !== $employeeCompany->getId()) {
            throw new \InvalidArgumentException('The template version must belong to the anketa\'s company.');
        }

        $this->id = Uuid::v7()->toRfc4122();
        $this->employee = $employee;
        $this->manager = $manager;
        $this->company = $employeeCompany;
        $this->meetingDate = $meetingDate;
        $this->employeeSealedKey = $employeeSealedKey;
        $this->managerSealedKey = $managerSealedKey;
        $this->periodicityDays = $periodicityDays;
        $this->createdAt = new \DateTimeImmutable();
        $this->employeeSealedKeyUpdatedAt = $this->createdAt;
        $this->managerSealedKeyUpdatedAt = $this->createdAt;
        $this->formVersion = self::CURRENT_FORM_VERSION;
        $this->templateKey = $templateKey;
        $this->oneOff = $oneOff;
        $this->customTemplateVersion = $customTemplateVersion;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getFormVersion(): int
    {
        return $this->formVersion;
    }

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function isOneOff(): bool
    {
        return $this->oneOff;
    }

    public function getCustomTemplateVersion(): ?CustomTemplateVersion
    {
        return $this->customTemplateVersion;
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

    public function getEmployeeBlobVersion(): int
    {
        return $this->employeeBlobVersion;
    }

    public function getManagerBlob(): ?string
    {
        return $this->managerBlob;
    }

    public function getManagerPublishedAt(): ?\DateTimeImmutable
    {
        return $this->managerPublishedAt;
    }

    public function getManagerBlobVersion(): int
    {
        return $this->managerBlobVersion;
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
     * encrypted with its author's own draft key and never seen by anyone else, exactly
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

    /**
     * Re-saves an already-published side's answers, re-encrypted with the same anketa key
     * (see docs/decisions/2026-09-07-editable-published-anketa-answers.md) — the caller is
     * responsible for checking isPublished($user) and !isArchived() first, same division of
     * responsibility as saveDraft()/publish() vs. AnketaController.
     *
     * @return bool true if saved, false on a version mismatch (caller should return 409)
     */
    public function updateAnswers(User $user, string $blob, int $expectedVersion): bool
    {
        if ($this->isEmployee($user)) {
            if ($expectedVersion !== $this->employeeBlobVersion) {
                return false;
            }
            $this->employeeBlob = $blob;
            ++$this->employeeBlobVersion;
        } else {
            if ($expectedVersion !== $this->managerBlobVersion) {
                return false;
            }
            $this->managerBlob = $blob;
            ++$this->managerBlobVersion;
        }

        return true;
    }

    /**
     * For building test fixtures only. Production archives through
     * AnketaLifecycleService::archive(), which uses AnketaRepository::markArchivedIfOpen()
     * (a conditional UPDATE) because an in-memory check like the one here, plus a flush,
     * can't stop two concurrent requests from both archiving and both creating a
     * successor (GitHub issue #130). The throw keeps fixtures honest: archiving is
     * one-way and happens once.
     */
    public function archive(bool $missed = false): void
    {
        if ($this->isArchived()) {
            throw new \LogicException('Anketa is already archived.');
        }

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

    public function getDiscussedBlob(): ?string
    {
        return $this->discussedBlob;
    }

    public function getDiscussedVersion(): int
    {
        return $this->discussedVersion;
    }

    public function getTopicsBlob(): ?string
    {
        return $this->topicsBlob;
    }

    /** Seeds the topics carried forward from the previous meeting at creation, like seedOutcomes(): topicsVersion stays 0. */
    public function seedTopics(string $blob): void
    {
        $this->topicsBlob = $blob;
    }

    public function getTopicsVersion(): int
    {
        return $this->topicsVersion;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function getReminderMeetingDay(): ?\DateTimeImmutable
    {
        return $this->reminderMeetingDay;
    }

    public function getFollowUpMeetingDay(): ?\DateTimeImmutable
    {
        return $this->followUpMeetingDay;
    }

    /**
     * Used only by bin/console app:reset-demo-data to set a freshly
     * constructed demo anketa's content to its seeded state in one shot
     * (each reset deletes and recreates every demo anketa from scratch —
     * see the command's own docblock for why). Bypasses the normal one-way
     * publish()/saveComments()/saveOutcomes()/saveGoalCheckpoints()
     * version-guarded mutators (and the repository's conditional topics save) and archive()'s "now" timestamp entirely on
     * purpose — those exist to protect real concurrent edits and record a
     * genuine archive moment, neither of which applies to a scheduled
     * reset replaying fixed, already-encrypted bytes. Blob/publishedAt
     * fields are nullable to support the demo's current (never-archived,
     * not-yet-filled-in) cycle. Not reachable from any HTTP endpoint.
     */
    public function resetForDemo(
        ?string $employeeBlob,
        ?\DateTimeImmutable $employeePublishedAt,
        ?string $managerBlob,
        ?\DateTimeImmutable $managerPublishedAt,
        ?string $commentsBlob,
        int $commentsVersion,
        ?string $outcomesBlob,
        int $outcomesVersion,
        ?string $goalCheckpointsBlob,
        int $goalCheckpointsVersion,
        ?string $topicsBlob,
        int $topicsVersion,
        bool $archived,
        bool $missed,
    ): void {
        $this->employeeBlob = $employeeBlob;
        $this->employeePublishedAt = $employeePublishedAt;
        $this->managerBlob = $managerBlob;
        $this->managerPublishedAt = $managerPublishedAt;
        $this->archivedAt = $archived ? new \DateTimeImmutable() : null;
        $this->missed = $missed;
        $this->reminderSentAt = null;
        $this->reminderMeetingDay = null;
        $this->followUpMeetingDay = null;
        $this->commentsBlob = $commentsBlob;
        $this->commentsVersion = $commentsVersion;
        $this->outcomesBlob = $outcomesBlob;
        $this->outcomesVersion = $outcomesVersion;
        $this->goalCheckpointsBlob = $goalCheckpointsBlob;
        $this->goalCheckpointsVersion = $goalCheckpointsVersion;
        $this->topicsBlob = $topicsBlob;
        $this->topicsVersion = $topicsVersion;
    }
}
