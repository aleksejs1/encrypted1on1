<?php

namespace App\Repository;

use App\Entity\Anketa;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Anketa>
 */
class AnketaRepository extends ServiceEntityRepository
{
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
     * eager-joining employee and manager.
     *
     * @return Anketa[]
     */
    public function findAllForUser(User $user): array
    {
        /** @var Anketa[] $result */
        $result = $this->createQueryBuilder('a')
            ->select('a', 'e', 'm')
            ->innerJoin('a.employee', 'e')
            ->innerJoin('a.manager', 'm')
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
