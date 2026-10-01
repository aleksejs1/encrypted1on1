<?php

namespace App\Tests\Functional\Repository;

use App\Entity\ActivationToken;
use App\Entity\Company;
use App\Repository\ActivationTokenRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;

/** The one "still redeemable" rule, shared by SeatLimitChecker and GitHub issue #169's renewal flow. */
class ActivationTokenRepositoryTest extends ApiTestCase
{
    use CleansUpCompanies;

    private const array COMPANY_TABLES = ['activation_tokens'];

    public function testCountUsableCountsOnlyUnusedUnexpiredTokensOfTheCompany(): void
    {
        static::createClient();
        $company = $this->makeCompany('Token repo count');
        $this->token($company, 'a@example.com', '+1 hour');
        $this->token($company, 'b@example.com', '+1 hour');
        $this->token($company, 'c@example.com', '-1 minute');
        $this->token($company, 'd@example.com', '+1 hour', used: true);
        $this->token($this->makeCompany('Token repo other'), 'e@example.com', '+1 hour');

        self::assertSame(2, $this->repository()->countUsable($company, new \DateTimeImmutable()));
    }

    public function testHasUsableForChecksOnlyThatAddressInThatCompany(): void
    {
        static::createClient();
        $company = $this->makeCompany('Token repo usable for');
        $this->token($company, 'x@example.com', '-1 minute');
        $this->token($company, 'y@example.com', '+1 hour');
        $this->token($this->makeCompany('Token repo usable for 2'), 'x@example.com', '+1 hour');
        $now = new \DateTimeImmutable();

        self::assertFalse($this->repository()->hasUsableFor('x@example.com', $company, $now));

        $this->token($company, 'x@example.com', '+1 hour');
        self::assertTrue($this->repository()->hasUsableFor('x@example.com', $company, $now));
    }

    public function testHasUsableForWithoutACompanyLooksInEveryCompany(): void
    {
        static::createClient();
        $company = $this->makeCompany('Token repo any company');
        $email = $this->uniqueEmail('token-repo-any');
        $now = new \DateTimeImmutable();
        self::assertFalse($this->repository()->hasUsableFor($email, null, $now));

        $this->token($company, $email, '+1 hour');
        self::assertTrue($this->repository()->hasUsableFor($email, null, $now));
    }

    public function testFindSpentForReturnsUsedAndExpiredTokensOfThatAddressInThatCompany(): void
    {
        static::createClient();
        $company = $this->makeCompany('Token repo spent');
        $used = $this->token($company, 'z@example.com', '+1 hour', used: true);
        $expired = $this->token($company, 'z@example.com', '-1 minute');
        $this->token($company, 'z@example.com', '+1 hour');
        $this->token($company, 'other@example.com', '-1 minute');
        $this->token($this->makeCompany('Token repo spent 2'), 'z@example.com', '-1 minute');

        $ids = array_map(fn (ActivationToken $token) => $token->getId(), $this->repository()->findSpentFor('z@example.com', $company, new \DateTimeImmutable()));
        sort($ids);
        $expected = [$used->getId(), $expired->getId()];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    private function repository(): ActivationTokenRepository
    {
        return $this->entityManager()->getRepository(ActivationToken::class);
    }

    private function token(Company $company, string $email, string $expiresAt, bool $used = false): ActivationToken
    {
        $token = new ActivationToken(bin2hex(random_bytes(32)), $email, $company, false, new \DateTimeImmutable($expiresAt));
        if ($used) {
            $token->markUsed();
        }
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        return $token;
    }
}
