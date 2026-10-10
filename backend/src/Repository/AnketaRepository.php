<?php

namespace App\Repository;

use App\Entity\Anketa;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Anketa>
 */
class AnketaRepository extends ServiceEntityRepository
{
    /** An open anketa meeting on the `:start`–`:end` day. See onDay(). */
    private const string OPEN_ON_DAY = 'a.archivedAt IS NULL AND a.meetingDate >= :start AND a.meetingDate < :end';
    /** ...not yet reminded for that `:day`. */
    private const string DUE_FOR_REMINDER = self::OPEN_ON_DAY.' AND (a.reminderMeetingDay IS NULL OR a.reminderMeetingDay <> :day)';
    /** ...not yet followed up for that `:day` (GitHub issue #202). */
    private const string DUE_FOR_FOLLOW_UP = self::OPEN_ON_DAY.' AND (a.followUpMeetingDay IS NULL OR a.followUpMeetingDay <> :day)';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Anketa::class);
    }

    /**
     * Eager-joins employee and manager rather than a plain find() — same reasoning as
     * findAllForUser()'s eager-join (summarize()/serializeDetail() always read both sides'
     * User), doubly worth it since liveState() calls this every ~4s per open tab instead
     * of once per page load.
     */
    public function findWithParticipants(string $id): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->createQueryBuilder('a')
            ->select('a', 'e', 'm')
            ->innerJoin('a.employee', 'e')
            ->innerJoin('a.manager', 'm')
            ->where('a.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }

    /**
     * Every anketa the user participates in, ordered by meetingDate DESC,
     * eager-joining employee and manager, and a custom anketa's template version and
     * template: the list shows the version's name, and the detail's next-template
     * default reads whether the template is archived (GitHub issue #144), so without
     * the join each custom row would cost two more queries.
     *
     * @return Anketa[]
     */
    public function findAllForUser(User $user): array
    {
        /** @var Anketa[] $result */
        $result = $this->createQueryBuilder('a')
            ->select('a', 'e', 'm', 'ctv', 'ct')
            ->innerJoin('a.employee', 'e')
            ->innerJoin('a.manager', 'm')
            ->leftJoin('a.customTemplateVersion', 'ctv')
            ->leftJoin('ctv.template', 'ct')
            ->where('a.employee = :user OR a.manager = :user')
            ->setParameter('user', $user)
            ->orderBy('a.meetingDate', 'DESC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * "Pair" is the unordered set of two users — roles aren't verified, they're just a
     * per-anketa choice (see the Phase 5 plan), so carry-forward must match regardless
     * of which one played employee/manager last time. One-off anketas (GitHub issue #111,
     * see Anketa::$oneOff) are skipped: they sit outside the pair's chain, so carrying
     * forward from one would drop the chain's own open goals/outcomes.
     */
    public function findMostRecentArchivedForPair(User $a, User $b): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->chainAnketasForPair($a, $b)
            ->andWhere('anketa.archivedAt IS NOT NULL')
            ->orderBy('anketa.meetingDate', 'DESC')
            // Same creation-order tie-break as findOpenForPair(), mirrored by pairChain.ts.
            ->addOrderBy('anketa.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }

    /**
     * The pair's open (non-archived) chain anketa, if any — same unordered-pair matching
     * and one-off exclusion as findMostRecentArchivedForPair(), so a pair whose chain has
     * ended can restart it even while a one-off is still open. Normally there's at most
     * one; if several are open (a pair that forked before issue #111's fix), the earliest
     * meeting wins (then the earliest-created), just to be deterministic. AnketaController::create() uses it to mark a
     * hand-created anketa as a one-off — see Anketa::$oneOff.
     */
    public function findOpenForPair(User $a, User $b): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->chainAnketasForPair($a, $b)
            ->andWhere('anketa.archivedAt IS NULL')
            ->orderBy('anketa.meetingDate', 'ASC')
            // UUIDv7 ids sort by creation time — a real tie-break, mirrored by
            // frontend/src/anketa/pairChain.ts.
            ->addOrderBy('anketa.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }

    /**
     * Ids of open anketas meeting on the day starting at `$dayStart` whose reminder for
     * that day hasn't been sent — the SendRemindersCommand selection (GitHub issue #167).
     * Ids only: the command loads each anketa fresh right before claiming it, since a
     * batch loaded up front goes stale while earlier emails send. Cross-tenant by design:
     * the command disables CompanyFilter, since it reminds every company's meetings.
     *
     * @return list<string>
     */
    public function findDueForReminder(\DateTimeImmutable $dayStart): array
    {
        return $this->idsDueOn(self::DUE_FOR_REMINDER, $dayStart);
    }

    /**
     * Stamps the anketa as reminded for the day starting at `$dayStart`, only if it still
     * matches findDueForReminder()'s conditions, as one conditional UPDATE, and reports
     * whether this call did it. The command claims before sending, so two overlapping runs
     * can't both send, and a meeting moved to another day (or archived) since the select
     * is skipped rather than reminded with its old date. The price: a crash between the
     * claim and the send (a killed process; the command releases it on an exception, see
     * releaseReminder()) loses that reminder for good, and a Friday claim for Monday also
     * keeps Sunday's fallback from retrying it, where the old stamp-after-send sent a
     * duplicate on the rerun instead.
     *
     * Cross-tenant by design, like the select and releaseReminder(): the only caller, the
     * reminder CLI command, serves every company and disables CompanyFilter first. With
     * the filter on, it would scope these UPDATEs too and skip other companies' anketas.
     */
    public function claimReminder(string $id, \DateTimeImmutable $dayStart, \DateTimeImmutable $sentAt): bool
    {
        $affected = $this->onDay($this->getEntityManager()->createQuery(
            'UPDATE '.Anketa::class.' a SET a.reminderSentAt = :sentAt, a.reminderMeetingDay = :day'
            .' WHERE a.id = :id AND '.self::DUE_FOR_REMINDER
        ), $dayStart)
            ->setParameter('sentAt', $sentAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $id)
            ->execute();

        return 1 === $affected;
    }

    /**
     * Undoes claimReminder() for the day starting at `$dayStart` when sending failed after
     * the claim, restoring the day the anketa was reminded for before it, so the reminder
     * is due again instead of lost (and a meeting later moved back to `$previousDay`
     * isn't reminded twice). reminderSentAt keeps the failed attempt's time. Either
     * participant may already have had their email; a rerun sends it again, the lesser
     * failure.
     */
    public function releaseReminder(string $id, \DateTimeImmutable $dayStart, ?\DateTimeImmutable $previousDay): void
    {
        $this->restoreClaimedDay('reminderMeetingDay', $id, $dayStart, $previousDay);
    }

    /**
     * Ids of still-open anketas that met on the day starting at `$dayStart` and haven't had
     * their follow-up email for that day (GitHub issue #202). The follow-up counterpart of
     * findDueForReminder(), with the same reasons for returning ids only and for being
     * cross-tenant.
     *
     * @return list<string>
     */
    public function findDueForFollowUp(\DateTimeImmutable $dayStart): array
    {
        return $this->idsDueOn(self::DUE_FOR_FOLLOW_UP, $dayStart);
    }

    /**
     * claimReminder() for the follow-up email: stamps the anketa as followed up for the
     * day starting at `$dayStart` only if it is still open, still on that day and not yet
     * followed up for it, and reports whether this call did it. A meeting archived or
     * moved since the select is skipped. Cross-tenant by design, like claimReminder().
     */
    public function claimFollowUp(string $id, \DateTimeImmutable $dayStart): bool
    {
        $affected = $this->onDay($this->getEntityManager()->createQuery(
            'UPDATE '.Anketa::class.' a SET a.followUpMeetingDay = :day WHERE a.id = :id AND '.self::DUE_FOR_FOLLOW_UP
        ), $dayStart)
            ->setParameter('id', $id)
            ->execute();

        return 1 === $affected;
    }

    /**
     * Undoes claimFollowUp() when sending failed after the claim, restoring the day the
     * anketa was followed up for before it, like releaseReminder().
     */
    public function releaseFollowUp(string $id, \DateTimeImmutable $dayStart, ?\DateTimeImmutable $previousDay): void
    {
        $this->restoreClaimedDay('followUpMeetingDay', $id, $dayStart, $previousDay);
    }

    /**
     * @param self::DUE_FOR_* $due
     *
     * @return list<string>
     */
    private function idsDueOn(string $due, \DateTimeImmutable $dayStart): array
    {
        /** @var list<string> $ids */
        $ids = $this->onDay($this->createQueryBuilder('a')
            ->select('a.id')
            ->where($due)
            ->getQuery(), $dayStart)
            ->getSingleColumnResult();

        return $ids;
    }

    /**
     * Puts `$previousDay` back into a claim column, only while it still holds `$dayStart`.
     *
     * @param 'reminderMeetingDay'|'followUpMeetingDay' $column
     */
    private function restoreClaimedDay(string $column, string $id, \DateTimeImmutable $dayStart, ?\DateTimeImmutable $previousDay): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE '.Anketa::class." a SET a.$column = :previousDay WHERE a.id = :id AND a.$column = :day"
        )
            ->setParameter('previousDay', $previousDay, Types::DATE_IMMUTABLE)
            ->setParameter('id', $id)
            ->setParameter('day', $dayStart, Types::DATE_IMMUTABLE)
            ->execute();
    }

    /**
     * Binds OPEN_ON_DAY's and the two DUE_FOR_* conditions' parameters for the UTC day starting at `$dayStart`.
     *
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult> $query
     *
     * @return Query<TKey, TResult>
     */
    private function onDay(Query $query, \DateTimeImmutable $dayStart): Query
    {
        return $query
            ->setParameter('start', $dayStart, Types::DATETIME_IMMUTABLE)
            ->setParameter('end', $dayStart->modify('+1 day'), Types::DATETIME_IMMUTABLE)
            ->setParameter('day', $dayStart, Types::DATE_IMMUTABLE);
    }

    /**
     * Marks the anketa archived only if it still isn't, as one conditional UPDATE, and
     * reports whether this call was the one that did it (GitHub issue #130). An
     * in-memory isArchived() check can't stop two concurrent archive requests — both
     * load the row before either writes — and each would then create a successor,
     * forking the pair's chain. The database serializes the two UPDATEs (a row lock on
     * MySQL, the write lock plus busy_timeout on SQLite), so the second one matches
     * no row.
     *
     * With `$expectedTopicsVersion` (GitHub issue #206), also only if the topics list is
     * still at that version: the archiving browser built the successor's carried-forward
     * topics from it, and a topic saved since would otherwise be missing from the next
     * meeting. The same statement, so no topics save can land in between. False then
     * means "already archived" or "topics changed"; the caller re-reads which.
     */
    public function markArchivedIfOpen(Anketa $anketa, \DateTimeImmutable $archivedAt, bool $missed, ?int $expectedTopicsVersion = null): bool
    {
        $dql = 'UPDATE '.Anketa::class.' a SET a.archivedAt = :archivedAt, a.missed = :missed WHERE a.id = :id AND a.archivedAt IS NULL';
        if (null !== $expectedTopicsVersion) {
            $dql .= ' AND a.topicsVersion = :expectedTopicsVersion';
        }
        $query = $this->getEntityManager()->createQuery($dql)
            ->setParameter('archivedAt', $archivedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('missed', $missed, Types::BOOLEAN)
            ->setParameter('id', $anketa->getId());
        if (null !== $expectedTopicsVersion) {
            $query->setParameter('expectedTopicsVersion', $expectedTopicsVersion);
        }

        return 1 === $query->execute();
    }

    /**
     * Overwrites the discussed-questions blob (GitHub issue #168) only if it's still at
     * `$expectedVersion` and the anketa is still open, as one conditional UPDATE, and
     * reports whether it did, so of two concurrent saves against the same version only
     * one can match. Both participants ticking boxes at the same moment is this blob's
     * main use case. The same approach as markArchivedIfOpen() and
     * AnketaPrivateNoteRepository::overwriteIfVersion().
     */
    public function saveDiscussedIfVersion(Anketa $anketa, string $blob, int $expectedVersion): bool
    {
        return $this->saveIfVersion(
            'UPDATE '.Anketa::class.' a SET a.discussedBlob = :blob, a.discussedVersion = a.discussedVersion + 1'
            .' WHERE a.id = :id AND a.discussedVersion = :expectedVersion AND a.archivedAt IS NULL',
            $anketa, $blob, $expectedVersion,
        );
    }

    /** The same for the shared topics list (GitHub issue #206), which both participants add to and tick off during the meeting. */
    public function saveTopicsIfVersion(Anketa $anketa, string $blob, int $expectedVersion): bool
    {
        return $this->saveIfVersion(
            'UPDATE '.Anketa::class.' a SET a.topicsBlob = :blob, a.topicsVersion = a.topicsVersion + 1'
            .' WHERE a.id = :id AND a.topicsVersion = :expectedVersion AND a.archivedAt IS NULL',
            $anketa, $blob, $expectedVersion,
        );
    }

    /** Runs one of the two conditional UPDATEs above and reports whether it matched the row. */
    private function saveIfVersion(string $dql, Anketa $anketa, string $blob, int $expectedVersion): bool
    {
        $affected = $this->getEntityManager()->createQuery($dql)
            ->setParameter('blob', $blob)
            ->setParameter('id', $anketa->getId())
            ->setParameter('expectedVersion', $expectedVersion)
            ->execute();

        return 1 === $affected;
    }

    private function chainAnketasForPair(User $a, User $b): QueryBuilder
    {
        return $this->createQueryBuilder('anketa')
            ->select('anketa')
            ->where('(anketa.employee = :a AND anketa.manager = :b) OR (anketa.employee = :b AND anketa.manager = :a)')
            ->andWhere('anketa.oneOff = false')
            ->setParameter('a', $a)
            ->setParameter('b', $b);
    }
}
