<?php

namespace App\Repository;

use App\Entity\Anketa;
use App\Entity\Goal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Goal>
 */
class GoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Goal::class);
    }

    /**
     * @return Goal[]
     */
    public function findByAnketa(Anketa $anketa): array
    {
        return $this->findBy(['anketa' => $anketa]);
    }

    /**
     * @return Goal[]
     */
    public function findInProgressForAnketa(Anketa $anketa): array
    {
        return $this->findBy(['anketa' => $anketa, 'status' => Goal::STATUS_IN_PROGRESS]);
    }

    /**
     * Batch-fetches goals for multiple anketas in a single query and groups them by anketa ID.
     *
     * @param Anketa[] $anketas
     *
     * @return array<string, list<Goal>>
     */
    public function findByAnketasGroupedByAnketaId(array $anketas): array
    {
        if ([] === $anketas) {
            return [];
        }

        /** @var Goal[] $allGoals */
        $allGoals = $this->createQueryBuilder('g')
            ->where('g.anketa IN (:anketas)')
            ->setParameter('anketas', $anketas)
            ->getQuery()
            ->getResult();

        $goalsByAnketaId = [];
        foreach ($allGoals as $goal) {
            $goalsByAnketaId[$goal->getAnketa()->getId()][] = $goal;
        }

        return $goalsByAnketaId;
    }
}
