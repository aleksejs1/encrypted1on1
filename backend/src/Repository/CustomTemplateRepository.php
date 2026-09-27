<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\CustomTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every lookup takes the company explicitly, on top of CompanyFilter
 * (docs/architecture-invariants.md §3).
 *
 * @extends ServiceEntityRepository<CustomTemplate>
 */
class CustomTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomTemplate::class);
    }

    public function findOneForCompany(string $id, Company $company): ?CustomTemplate
    {
        return $this->findOneBy(['id' => $id, 'company' => $company]);
    }

    /** Archived templates included: the cap counts them too (CustomTemplate::MAX_PER_COMPANY). */
    public function countForCompany(Company $company): int
    {
        return $this->count(['company' => $company]);
    }

    /**
     * Moves the template to the next version only if it's still at `$expectedVersion`
     * and not archived, as one conditional UPDATE, and reports whether it did. Of two
     * concurrent saves against the same version the database lets only one match, and
     * an archive landing in between makes it match nothing (#133 §8.1). The caller
     * inserts the new version row in the same transaction. Scoped to the requester's
     * company, not the template's own, so it's a real check on top of CompanyFilter.
     */
    public function bumpVersionIfCurrent(CustomTemplate $template, int $expectedVersion, Company $requesterCompany): bool
    {
        $affected = $this->getEntityManager()->createQuery(
            'UPDATE '.CustomTemplate::class.' t SET t.currentVersion = t.currentVersion + 1, t.updatedAt = :now'
            .' WHERE t.id = :id AND t.company = :company AND t.currentVersion = :expectedVersion AND t.archivedAt IS NULL'
        )
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $template->getId())
            ->setParameter('company', $requesterCompany->getId())
            ->setParameter('expectedVersion', $expectedVersion)
            ->execute();

        return 1 === $affected;
    }
}
