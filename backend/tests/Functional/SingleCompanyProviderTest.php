<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Tests\Support\ApiTestCase;

/**
 * Needs a real EntityManager/database to exercise get()'s row-count branches, so this
 * is a functional test, not a pure unit one, unlike most of this project's other
 * Unit/Entity/*Test.php coverage.
 */
class SingleCompanyProviderTest extends ApiTestCase
{
    public function testGetReturnsTheSingleSeededCompanyByDefault(): void
    {
        static::createClient();

        $company = $this->singleCompanyProvider()->get();

        self::assertSame('invite', $company->getRegistrationMode());
        self::assertSame('', $company->getAllowedEmailDomain());
    }

    public function testGetThrowsOnceMoreThanOneCompanyExists(): void
    {
        static::createClient();
        $extra = new Company('Extra Co');
        $this->entityManager()->persist($extra);
        $this->entityManager()->flush();

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessageMatches('/More than one Company row exists/');

            $this->singleCompanyProvider()->get();
        } finally {
            // Same FK-safe cleanup CompanyIsolationTest's own tearDown() uses — this
            // extra row must not survive into later tests in the same suite run.
            $this->entityManager()->getConnection()->executeStatement(
                'DELETE FROM companies WHERE id = ?',
                [$extra->getId()],
            );
        }
    }
}
