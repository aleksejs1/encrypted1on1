<?php

namespace App\Repository;

use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Versions are only ever inserted (by AdminTemplateController) and read: by id for an
 * anketa, as the current one of a template, or as the company's library. Every read
 * takes the company explicitly, on top of CompanyFilter
 * (docs/architecture-invariants.md §3).
 *
 * @extends ServiceEntityRepository<CustomTemplateVersion>
 */
class CustomTemplateVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomTemplateVersion::class);
    }

    /** Any version of any of the company's templates, archived templates included. */
    public function findOneForCompany(string $id, Company $company): ?CustomTemplateVersion
    {
        return $this->findOneBy(['id' => $id, 'company' => $company]);
    }

    /**
     * The version a new anketa on template `$templateId` gets (GitHub issue #144, #133
     * §7.2): its current one, if the template is the company's and not archived. Null
     * otherwise, which the caller answers with 422 `template_unavailable`.
     */
    public function findCurrentActiveForCompany(string $templateId, Company $company): ?CustomTemplateVersion
    {
        /** @var CustomTemplateVersion|null $version */
        $version = $this->createQueryBuilder('v')
            ->addSelect('t')
            ->join('v.template', 't')
            ->where('t.id = :id')
            ->andWhere('t.company = :company')
            ->andWhere('v.version = t.currentVersion')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('id', $templateId)
            ->setParameter('company', $company->getId())
            ->getQuery()
            ->getOneOrNullResult();

        return $version;
    }

    public function findCurrent(CustomTemplate $template): ?CustomTemplateVersion
    {
        return $this->findOneBy(['template' => $template, 'version' => $template->getCurrentVersion()]);
    }

    /**
     * The picker's list (#133 §6): each active template's id with its current
     * version's name and description, and nothing else — definitions can be up to
     * 64 KiB each, and every member loads this list.
     *
     * @return list<array{id: string, name: string, description: string}>
     */
    public function findActiveSummariesForCompany(Company $company): array
    {
        /** @var list<array{id: string, name: string, description: string}> $rows */
        $rows = $this->createQueryBuilder('v')
            ->select('t.id AS id', 'v.name AS name', 'v.description AS description')
            ->join('v.template', 't')
            ->where('t.company = :company')
            ->andWhere('v.version = t.currentVersion')
            ->andWhere('t.archivedAt IS NULL')
            ->setParameter('company', $company->getId())
            ->orderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return $rows;
    }

    /**
     * The current version of each of the company's templates, template fetch-joined:
     * active ones first, then archived ones, most recently edited first within each.
     *
     * @return list<CustomTemplateVersion>
     */
    public function findCurrentForCompany(Company $company): array
    {
        $query = $this->createQueryBuilder('v')
            ->addSelect('t')
            ->addSelect('CASE WHEN t.archivedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN archivedLast')
            ->join('v.template', 't')
            ->where('t.company = :company')
            ->andWhere('v.version = t.currentVersion')
            ->setParameter('company', $company->getId())
            ->orderBy('archivedLast', 'ASC')
            ->addOrderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        /** @var list<CustomTemplateVersion> $versions */
        $versions = $query->getQuery()->getResult();

        return $versions;
    }
}
