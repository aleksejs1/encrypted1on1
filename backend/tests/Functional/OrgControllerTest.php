<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/** GET /api/me/org (GitHub issue #268): my own manager and direct reports, nobody else's. */
class OrgControllerTest extends ApiTestCase
{
    use CleansUpCompanies;

    private const COMPANY_TABLES = ['activation_tokens', 'invite_records', 'users'];

    /**
     * Activates the named people in one company, the first on $client (whose session
     * the test then uses), the rest on a spare client.
     *
     * @param list<string> $names
     *
     * @return array<string, array{id: string, email: string, isAdmin: bool}>
     */
    private function people(KernelBrowser $client, string $label, array $names, ?Company $company = null): array
    {
        $spare = $this->secondClient();
        $people = [];
        foreach ($names as $index => $name) {
            $people[$name] = $this->activateUser(0 === $index ? $client : $spare, $this->uniqueEmail("org-{$label}-{$name}"), company: $company, displayName: ucfirst($name));
        }

        return $people;
    }

    /** @param array<string, array{id: string, email: string, isAdmin: bool}> $people */
    private function report(array $people, string $who, string $to): void
    {
        $this->user($people[$who]['id'])->setManager($this->user($people[$to]['id']));
        $this->entityManager()->flush();
    }

    private function user(string $id): User
    {
        $user = $this->entityManager()->find(User::class, $id);
        \assert($user instanceof User);

        return $user;
    }

    /**
     * @param array{id: string, email: string, isAdmin: bool} $person
     *
     * @return array{id: string, displayName: string, email: string}
     */
    private static function expected(array $person, string $name): array
    {
        return ['id' => $person['id'], 'displayName' => ucfirst($name), 'email' => $person['email']];
    }

    public function testRequires401WhenNotAuthenticated(): void
    {
        $result = $this->jsonRequest(static::createClient(), 'GET', '/api/me/org');

        self::assertSame(401, $result['status']);
    }

    public function testAUserWithNoLinksGetsNullAndAnEmptyList(): void
    {
        $client = static::createClient();
        $this->people($client, 'none', ['me']);

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');

        self::assertSame(200, $result['status']);
        self::assertSame(['manager' => null, 'directReports' => []], $result['json']);
    }

    public function testReturnsMyManagerAndMyDirectReports(): void
    {
        $client = static::createClient();
        $people = $this->people($client, 'both', ['me', 'head', 'zoe', 'adam']);
        $this->report($people, 'me', 'head');
        $this->report($people, 'zoe', 'me');
        $this->report($people, 'adam', 'me');

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');

        self::assertSame(200, $result['status']);
        self::assertSame([
            'manager' => self::expected($people['head'], 'head'),
            'directReports' => [self::expected($people['adam'], 'adam'), self::expected($people['zoe'], 'zoe')],
        ], $result['json']);
    }

    /** One level each way: not my manager's manager, not my reports' reports, not my manager's other reports. */
    public function testSaysNothingAboutAnyoneElsesLinks(): void
    {
        $client = static::createClient();
        $people = $this->people($client, 'far', ['me', 'head', 'top', 'peer', 'report', 'subreport']);
        $this->report($people, 'head', 'top');
        $this->report($people, 'me', 'head');
        $this->report($people, 'peer', 'head');
        $this->report($people, 'report', 'me');
        $this->report($people, 'subreport', 'report');

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');

        self::assertSame([
            'manager' => self::expected($people['head'], 'head'),
            'directReports' => [self::expected($people['report'], 'report')],
        ], $result['json']);
        $body = (string) json_encode($result['json']);
        foreach (['top', 'peer', 'subreport'] as $stranger) {
            self::assertStringNotContainsString($people[$stranger]['id'], $body);
        }
    }

    public function testABlockedManagerReadsAsNoManagerAndABlockedReportIsLeftOut(): void
    {
        $client = static::createClient();
        $people = $this->people($client, 'blocked', ['me', 'head', 'gone', 'here']);
        $this->report($people, 'me', 'head');
        $this->report($people, 'gone', 'me');
        $this->report($people, 'here', 'me');
        $this->user($people['head']['id'])->setBlocked(true);
        $this->user($people['gone']['id'])->setBlocked(true);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');

        self::assertSame(['manager' => null, 'directReports' => [self::expected($people['here'], 'here')]], $result['json']);
        // Blocking is reversible: the links themselves are still stored.
        self::assertSame($people['head']['id'], $this->user($people['me']['id'])->getManager()?->getId());
    }

    /** A link an assignment racing the deletion left behind: deleted, and unblocked to show it isn't the blocked flag that hides it. */
    public function testADeletedAccountIsLeftOutOnBothSides(): void
    {
        $client = static::createClient();
        $people = $this->people($client, 'deleted', ['me', 'head', 'gone']);
        foreach (['head', 'gone'] as $name) {
            $this->user($people[$name]['id'])->delete();
            $this->user($people[$name]['id'])->setBlocked(false);
        }
        $this->report($people, 'me', 'head');
        $this->report($people, 'gone', 'me');

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');

        self::assertSame(['manager' => null, 'directReports' => []], $result['json']);
    }

    public function testReportsComeInEmailOrder(): void
    {
        $client = static::createClient();
        $me = $this->activateUser($client, $this->uniqueEmail('org-order-me'));
        $spare = $this->secondClient();
        // Activated in reverse email order, and named the other way round, so the
        // order can come from neither insertion nor the name.
        $later = $this->activateUser($spare, 'zz-'.$this->uniqueEmail('org-order'), displayName: 'Adam');
        $earlier = $this->activateUser($spare, 'aa-'.$this->uniqueEmail('org-order'), displayName: 'Zoe');
        foreach ([$later, $earlier] as $report) {
            $this->user($report['id'])->setManager($this->user($me['id']));
        }
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');

        self::assertSame([$earlier['id'], $later['id']], array_column($result['json']['directReports'], 'id'));
    }

    /** A link across companies can't be made through the API; written straight in, it still isn't served. */
    public function testAnotherCompanysUsersNeverAppear(): void
    {
        $client = static::createClient();
        $mine = $this->people($client, 'mine', ['me', 'colleague']);
        $this->report($mine, 'colleague', 'me');
        $theirs = $this->people($this->secondClient(), 'theirs', ['worker'], $this->makeCompany('Org Other Co'));
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE users SET manager_id = ? WHERE id = ?',
            [$mine['me']['id'], $theirs['worker']['id']],
        );

        // And the other way: my own link pointing out of the company.
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE users SET manager_id = ? WHERE id = ?',
            [$theirs['worker']['id'], $mine['me']['id']],
        );

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');
        // The other company's rows go in tearDown(); don't leave a link to one behind.
        $this->entityManager()->getConnection()->executeStatement('UPDATE users SET manager_id = NULL WHERE id = ?', [$mine['me']['id']]);

        self::assertSame(200, $result['status']);
        self::assertSame(['manager' => null, 'directReports' => [self::expected($mine['colleague'], 'colleague')]], $result['json']);
    }

    /** No foreign key is enforced on SQLite, so a row removed by hand can leave this behind. */
    public function testAManagerLinkToAMissingRowReadsAsNoManager(): void
    {
        $client = static::createClient();
        $people = $this->people($client, 'dangling', ['me']);
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE users SET manager_id = ? WHERE id = ?',
            ['00000000-0000-0000-0000-000000000000', $people['me']['id']],
        );

        $result = $this->jsonRequest($client, 'GET', '/api/me/org');
        $this->entityManager()->getConnection()->executeStatement('UPDATE users SET manager_id = NULL WHERE id = ?', [$people['me']['id']]);

        self::assertSame(200, $result['status']);
        self::assertSame(['manager' => null, 'directReports' => []], $result['json']);
    }
}
