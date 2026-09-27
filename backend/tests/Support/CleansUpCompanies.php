<?php

namespace App\Tests\Support;

use App\Entity\Company;

/**
 * For test classes that work in companies of their own (so per-company limits and the
 * tenant boundary are exercised in isolation): makeCompany() creates one, and tearDown()
 * deletes those companies and their rows from COMPANY_TABLES, which the using class
 * lists children first, the order foreign keys need. Written for the company
 * template tests (GitHub issue #142); several older test classes still hand-roll the
 * same cleanup and could move onto it.
 */
trait CleansUpCompanies
{
    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            foreach (self::COMPANY_TABLES as $table) {
                $connection->executeStatement("DELETE FROM {$table} WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            }
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    private function makeCompany(string $name, bool $flush = true): Company
    {
        $company = new Company($name);
        $this->entityManager()->persist($company);
        if ($flush) {
            $this->entityManager()->flush();
        }
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }
}
