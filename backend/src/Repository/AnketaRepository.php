<?php

namespace App\Repository;

use App\Entity\Anketa;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     * of which one played employee/manager last time.
     */
    public function findMostRecentArchivedForPair(User $a, User $b): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->createQueryBuilder('anketa')
            ->select('anketa')
            ->where('(anketa.employee = :a AND anketa.manager = :b) OR (anketa.employee = :b AND anketa.manager = :a)')
            ->andWhere('anketa.archivedAt IS NOT NULL')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->orderBy('anketa.meetingDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }
}
