<?php

namespace App\Tests\Functional;

use App\Doctrine\CompanyFilter;
use App\Dto\SavePrivateNotesRequest;
use App\Entity\Company;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GET/PUT /api/anketas/{id}/private-notes and GET /api/me/private-notes — GitHub
 * issues #132 §5, #136. The backend never inspects the key or the blob, so opaque
 * placeholders stand in for real ciphertext, except that the key has the exact
 * length the DTO checks.
 */
class PrivateNotesTest extends ApiTestCase
{
    /** @var list<string> */
    private array $createdCompanyIds = [];

    protected function tearDown(): void
    {
        if ($this->entityManager()->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $this->entityManager()->getFilters()->disable(CompanyFilter::NAME);
        }

        if ([] !== $this->createdCompanyIds) {
            $connection = $this->entityManager()->getConnection();
            $placeholders = implode(',', array_fill(0, \count($this->createdCompanyIds), '?'));
            // FK-safe order: notes before the anketas and users they reference.
            $connection->executeStatement("DELETE FROM anketa_private_notes WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM anketas WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM activation_tokens WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM users WHERE company_id IN ({$placeholders})", $this->createdCompanyIds);
            $connection->executeStatement("DELETE FROM companies WHERE id IN ({$placeholders})", $this->createdCompanyIds);
        }

        parent::tearDown();
    }

    public function testGetReturnsNullBeforeAnyNotesAreSaved(): void
    {
        [$employeeClient, , , , $anketaId] = $this->makePairWithAnketa('get-null');

        $result = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/private-notes");

        self::assertSame(200, $result['status']);
        self::assertNull($result['json']);
        self::assertSame('null', $employeeClient->getResponse()->getContent());
    }

    public function testFirstSaveInsertsAtVersionOneAndGetReturnsIt(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('insert');
        $key = self::notesKey('k');

        $saved = $this->saveNotes($employeeClient, $anketaId, $employee['id'], $key, 'blob-1', 0);

        self::assertSame(200, $saved['status']);
        self::assertSame(['version' => 1], $saved['json']);

        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/private-notes");
        self::assertSame(['encryptedNotesKey' => $key, 'notesBlob' => 'blob-1', 'version' => 1], $get['json']);

        // The anketa's company is copied onto the row, which is what CompanyFilter scopes by.
        $companyId = $this->entityManager()->getConnection()->fetchOne('SELECT company_id FROM anketa_private_notes WHERE anketa_id = ?', [$anketaId]);
        self::assertSame($this->singleCompanyProvider()->get()->getId(), $companyId);
    }

    public function testSaveWithTheCurrentVersionOverwritesKeyAndBlobAndIncrementsIt(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('update');
        $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('a'), 'blob-1', 0);
        $newKey = self::notesKey('b');

        $saved = $this->saveNotes($employeeClient, $anketaId, $employee['id'], $newKey, 'blob-2', 1);

        self::assertSame(200, $saved['status']);
        self::assertSame(['version' => 2], $saved['json']);
        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/private-notes");
        self::assertSame(['encryptedNotesKey' => $newKey, 'notesBlob' => 'blob-2', 'version' => 2], $get['json']);
    }

    public function testStaleVersionReturns409WithTheCurrentRowAndChangesNothing(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('stale');
        $key = self::notesKey('c');
        $this->saveNotes($employeeClient, $anketaId, $employee['id'], $key, 'blob-1', 0);
        $this->saveNotes($employeeClient, $anketaId, $employee['id'], $key, 'blob-2', 1);

        $stale = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('d'), 'blob-stale', 1);

        self::assertSame(409, $stale['status']);
        self::assertSame('Your private notes changed since you last read them.', $stale['json']['error']);
        self::assertSame($key, $stale['json']['encryptedNotesKey']);
        self::assertSame('blob-2', $stale['json']['notesBlob']);
        self::assertSame(2, $stale['json']['version']);
        $get = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/private-notes");
        self::assertSame('blob-2', $get['json']['notesBlob']);
    }

    public function testInsertingAgainAtVersionZeroReturns409WithTheExistingRow(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('reinsert');
        $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('e'), 'blob-1', 0);

        $again = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('f'), 'blob-other-tab', 0);

        self::assertSame(409, $again['status']);
        self::assertSame('blob-1', $again['json']['notesBlob']);
        self::assertSame(1, $again['json']['version']);
    }

    public function testNonZeroVersionWithNoRowReturns409WithNullsAndInsertsNothing(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('no-row');

        $result = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('g'), 'blob-1', 3);

        self::assertSame(409, $result['status']);
        self::assertNull($result['json']['encryptedNotesKey']);
        self::assertNull($result['json']['notesBlob']);
        self::assertNull($result['json']['version']);
        self::assertNull($this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/private-notes")['json']);
    }

    /**
     * Two tabs both inserting at version 0: the loser's flush hits the unique
     * (anketa, author) index. Simulated by inserting the other tab's row behind the
     * EntityManager's back just before this request's flush.
     */
    public function testFirstInsertThatLosesARaceGetsA409WithNulls(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('insert-race');

        // Keeps this kernel, and so the listener registered below, for the next request.
        $employeeClient->disableReboot();
        $connection = $this->entityManager()->getConnection();
        $companyId = $this->singleCompanyProvider()->get()->getId();
        $listener = new class($connection, $anketaId, $employee['id'], $companyId) {
            public int $racedFlushes = 0;

            public function __construct(
                private readonly Connection $connection,
                private readonly string $anketaId,
                private readonly string $authorId,
                private readonly string $companyId,
            ) {
            }

            public function preFlush(PreFlushEventArgs $args): void
            {
                if (0 !== $this->racedFlushes) {
                    return;
                }
                ++$this->racedFlushes;
                $this->connection->insert('anketa_private_notes', [
                    'id' => 'raced-'.bin2hex(random_bytes(8)),
                    'anketa_id' => $this->anketaId,
                    'author_id' => $this->authorId,
                    'company_id' => $this->companyId,
                    'encryptedNotesKey' => base64_encode(str_repeat('w', 72)),
                    'notesBlob' => 'winner-blob',
                    'version' => 1,
                    'updatedAt' => new \DateTimeImmutable(),
                ], ['updatedAt' => Types::DATETIME_IMMUTABLE]);
            }
        };
        $this->entityManager()->getEventManager()->addEventListener(Events::preFlush, $listener);

        $result = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('h'), 'loser-blob', 0);

        self::assertSame(1, $listener->racedFlushes);
        self::assertSame(409, $result['status']);
        self::assertNull($result['json']['encryptedNotesKey']);
        self::assertNull($result['json']['notesBlob']);
        self::assertNull($result['json']['version']);

        $this->entityManager()->getEventManager()->removeEventListener(Events::preFlush, $listener);
        $rows = $connection->fetchAllAssociative('SELECT notesBlob FROM anketa_private_notes WHERE anketa_id = ?', [$anketaId]);
        self::assertSame([['notesBlob' => 'winner-blob']], $rows);
    }

    public function testSaveIsAllowedOnAnArchivedAnketa(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('archived');
        $archive = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", [
            'missed' => false,
            'skipNextMeeting' => true,
        ]);
        self::assertSame(200, $archive['status']);

        $saved = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('i'), 'after-the-meeting', 0);

        self::assertSame(200, $saved['status']);
        self::assertSame(1, $saved['json']['version']);
    }

    public function testSaveForAnotherAccountIsRejectedWith403AndWritesNothing(): void
    {
        [$employeeClient, , , $manager, $anketaId] = $this->makePairWithAnketa('wrong-account');

        // A stale tab whose notes were encrypted for the manager, now running under the
        // employee's session.
        $result = $this->saveNotes($employeeClient, $anketaId, $manager['id'], self::notesKey('j'), 'wrong-account-blob', 0);

        self::assertSame(403, $result['status']);
        self::assertSame('These notes belong to a different account than the one logged in.', $result['json']['error']);
        self::assertSame(0, $this->countRows($anketaId));
    }

    public function testNonParticipantGets403(): void
    {
        [, , , , $anketaId] = $this->makePairWithAnketa('outsider');
        $outsiderClient = $this->secondClient();
        $outsider = $this->activateUser($outsiderClient, $this->uniqueEmail('notes-outsider'));

        self::assertSame(403, $this->jsonRequest($outsiderClient, 'GET', "/api/anketas/{$anketaId}/private-notes")['status']);
        $put = $this->saveNotes($outsiderClient, $anketaId, $outsider['id'], self::notesKey('k'), 'outsider-blob', 0);
        self::assertSame(403, $put['status']);
        self::assertSame(0, $this->countRows($anketaId));
    }

    public function testUnknownAnketaGets404(): void
    {
        $client = static::createClient();
        $user = $this->activateUser($client, $this->uniqueEmail('notes-unknown'));

        self::assertSame(404, $this->jsonRequest($client, 'GET', '/api/anketas/no-such-anketa/private-notes')['status']);
        self::assertSame(404, $this->saveNotes($client, 'no-such-anketa', $user['id'], self::notesKey('l'), 'blob', 0)['status']);
    }

    public function testAnotherCompanysAnketaGets404(): void
    {
        $clientA = static::createClient();
        $companyA = $this->makeCompany('Notes Company A');
        $companyB = $this->makeCompany('Notes Company B');
        $userA = $this->activateUser($clientA, $this->uniqueEmail('notes-tenant-a'), company: $companyA);
        $clientB = $this->secondClient();
        $this->activateUser($clientB, $this->uniqueEmail('notes-tenant-b-emp'), company: $companyB);
        $managerB = $this->activateUser($this->secondClient(), $this->uniqueEmail('notes-tenant-b-mgr'), company: $companyB);
        $anketaB = $this->createAnketa($clientB, $managerB['id'])['json']['id'];

        self::assertSame(404, $this->jsonRequest($clientA, 'GET', "/api/anketas/{$anketaB}/private-notes")['status']);
        self::assertSame(404, $this->saveNotes($clientA, $anketaB, $userA['id'], self::notesKey('m'), 'blob', 0)['status']);
        self::assertSame(0, $this->countRows($anketaB));
    }

    public function testBlobOverTheCapIsRejectedWithATranslatedMessage(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('too-large');
        $tooLarge = str_repeat('x', SavePrivateNotesRequest::MAX_NOTES_BLOB_LENGTH + 1);

        $result = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('n'), $tooLarge, 0);

        self::assertSame(400, $result['status']);
        self::assertSame([['property' => 'notesBlob', 'message' => 'Private notes are too long to save.']], $result['json']['violations']);
        self::assertSame(0, $this->countRows($anketaId));
    }

    public function testBlobAtTheCapIsAccepted(): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('at-cap');
        $atCap = str_repeat('x', SavePrivateNotesRequest::MAX_NOTES_BLOB_LENGTH);

        $result = $this->saveNotes($employeeClient, $anketaId, $employee['id'], self::notesKey('o'), $atCap, 0);

        self::assertSame(200, $result['status']);
    }

    /** @return array<string, array{string}> */
    public static function invalidNotesKeys(): array
    {
        return [
            'too short' => [base64_encode(str_repeat('k', 71))],
            'too long' => [base64_encode(str_repeat('k', 73))],
            'not base64' => [str_repeat('!', 96)],
            'whitespace inside' => [substr(base64_encode(str_repeat('k', 72)), 0, 95).' '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidNotesKeys')]
    public function testMalformedNotesKeyIsRejected(string $key): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('bad-key');

        $result = $this->saveNotes($employeeClient, $anketaId, $employee['id'], $key, 'blob', 0);

        self::assertSame(400, $result['status']);
        self::assertSame('encryptedNotesKey', $result['json']['violations'][0]['property']);
        self::assertSame('"encryptedNotesKey" is not a valid encrypted key.', $result['json']['violations'][0]['message']);
        self::assertSame(0, $this->countRows($anketaId));
    }

    /**
     * Each case breaks one field rule; `withAuthor` adds the session user's id as
     * `authorId`, so only the named field is wrong.
     *
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function invalidPayloads(): array
    {
        $valid = ['encryptedNotesKey' => base64_encode(str_repeat('k', 72)), 'notesBlob' => 'blob', 'expectedVersion' => 0];

        return [
            'missing authorId' => [$valid, false],
            'null notesBlob' => [['notesBlob' => null] + $valid, true],
            'blank notesBlob' => [['notesBlob' => ''] + $valid, true],
            'blank encryptedNotesKey' => [['encryptedNotesKey' => ''] + $valid, true],
            'missing expectedVersion' => [array_diff_key($valid, ['expectedVersion' => true]), true],
            'negative expectedVersion' => [['expectedVersion' => -1] + $valid, true],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejectedAndWritesNothing(array $payload, bool $withAuthor): void
    {
        [$employeeClient, $employee, , , $anketaId] = $this->makePairWithAnketa('invalid');
        if ($withAuthor) {
            $payload['authorId'] = $employee['id'];
        }

        $result = $this->jsonRequest($employeeClient, 'PUT', "/api/anketas/{$anketaId}/private-notes", $payload);

        self::assertSame(400, $result['status']);
        self::assertSame(0, $this->countRows($anketaId));
    }

    /**
     * §5.3: the counterpart learns nothing about the author's notes, not even that they
     * exist, from any endpoint, and their own save creates their own row without
     * touching the author's.
     */
    public function testTheCounterpartSeesNothingOfTheAuthorsNotes(): void
    {
        [$employeeClient, $employee, $managerClient, $manager, $anketaId] = $this->makePairWithAnketa('counterpart');
        $employeeKey = self::notesKey('p');
        $employeeBlob = 'employee-private-blob-'.bin2hex(random_bytes(8));
        $this->saveNotes($employeeClient, $anketaId, $employee['id'], $employeeKey, $employeeBlob, 0);
        $this->saveNotes($employeeClient, $anketaId, $employee['id'], $employeeKey, $employeeBlob, 1);

        self::assertNull($this->jsonRequest($managerClient, 'GET', "/api/anketas/{$anketaId}/private-notes")['json']);
        self::assertSame([], $this->jsonRequest($managerClient, 'GET', '/api/me/private-notes')['json']);

        foreach (["/api/anketas/{$anketaId}", '/api/anketas/bulk', "/api/anketas/{$anketaId}/live-state", '/api/anketas'] as $path) {
            $response = $this->jsonRequest($managerClient, 'GET', $path);
            self::assertSame(200, $response['status'], $path);
            $body = (string) $managerClient->getResponse()->getContent();
            self::assertStringNotContainsString($employeeKey, $body, $path);
            self::assertStringNotContainsString($employeeBlob, $body, $path);
            // Not even the field names, which would reveal that notes exist as a concept here.
            self::assertStringNotContainsString('encryptedNotesKey', $body, $path);
            self::assertStringNotContainsString('notesBlob', $body, $path);
        }

        // Sending the author's current version doesn't reach the author's row: the
        // overwrite is scoped to the requester as author, who has no row yet.
        $overwrite = $this->saveNotes($managerClient, $anketaId, $manager['id'], self::notesKey('v'), 'overwrite-attempt', 2);
        self::assertSame(409, $overwrite['status']);
        self::assertNull($overwrite['json']['notesBlob']);

        $managerKey = self::notesKey('q');
        $managerSave = $this->saveNotes($managerClient, $anketaId, $manager['id'], $managerKey, 'manager-blob', 0);
        self::assertSame(['version' => 1], $managerSave['json']);

        $employeeView = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}/private-notes")['json'];
        self::assertSame(['encryptedNotesKey' => $employeeKey, 'notesBlob' => $employeeBlob, 'version' => 2], $employeeView);
        $managerView = $this->jsonRequest($managerClient, 'GET', "/api/anketas/{$anketaId}/private-notes")['json'];
        self::assertSame(['encryptedNotesKey' => $managerKey, 'notesBlob' => 'manager-blob', 'version' => 1], $managerView);
    }

    public function testListOwnReturnsOnlyTheRequestersRowsAcrossAnketas(): void
    {
        [$employeeClient, $employee, $managerClient, $manager, $firstAnketaId] = $this->makePairWithAnketa('list-own');
        // A second, one-off anketa for the same pair.
        $secondAnketaId = $this->createAnketa($employeeClient, $manager['id'])['json']['id'];
        $firstKey = self::notesKey('r');
        $secondKey = self::notesKey('s');
        $this->saveNotes($employeeClient, $firstAnketaId, $employee['id'], $firstKey, 'first-blob', 0);
        $this->saveNotes($employeeClient, $secondAnketaId, $employee['id'], $secondKey, 'second-blob', 0);
        $this->saveNotes($managerClient, $firstAnketaId, $manager['id'], self::notesKey('t'), 'manager-blob', 0);

        $own = $this->jsonRequest($employeeClient, 'GET', '/api/me/private-notes');

        self::assertSame(200, $own['status']);
        $rows = $own['json'];
        usort($rows, static fn (array $a, array $b) => strcmp($a['notesBlob'], $b['notesBlob']));
        self::assertSame([
            ['anketaId' => $firstAnketaId, 'encryptedNotesKey' => $firstKey, 'notesBlob' => 'first-blob'],
            ['anketaId' => $secondAnketaId, 'encryptedNotesKey' => $secondKey, 'notesBlob' => 'second-blob'],
        ], $rows);
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $client = static::createClient();

        self::assertSame(401, $this->jsonRequest($client, 'GET', '/api/anketas/any/private-notes')['status']);
        self::assertSame(401, $this->jsonRequest($client, 'GET', '/api/me/private-notes')['status']);
        $put = $this->saveNotes($client, 'any', 'anyone', self::notesKey('u'), 'blob', 0);
        self::assertSame(401, $put['status']);
    }

    /**
     * @return array{0: KernelBrowser, 1: array{id: string, email: string, isAdmin: bool}, 2: KernelBrowser, 3: array{id: string, email: string, isAdmin: bool}, 4: string}
     */
    private function makePairWithAnketa(string $label): array
    {
        $employeeClient = static::createClient();
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail("notes-{$label}-emp"));
        $managerClient = $this->secondClient();
        $manager = $this->activateUser($managerClient, $this->uniqueEmail("notes-{$label}-mgr"));
        $created = $this->createAnketa($employeeClient, $manager['id']);
        self::assertSame(201, $created['status']);

        return [$employeeClient, $employee, $managerClient, $manager, $created['json']['id']];
    }

    /** @return array{status: int, json: mixed} */
    private function createAnketa(KernelBrowser $employeeClient, string $counterpartId): array
    {
        return $this->jsonRequest($employeeClient, 'POST', '/api/anketas', [
            'counterpartId' => $counterpartId,
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('e', 44),
            'counterpartSealedKey' => str_repeat('m', 44),
            'periodicityDays' => 30,
        ]);
    }

    /** @return array{status: int, json: mixed} */
    private function saveNotes(KernelBrowser $client, string $anketaId, string $authorId, string $key, string $blob, int $expectedVersion): array
    {
        return $this->jsonRequest($client, 'PUT', "/api/anketas/{$anketaId}/private-notes", [
            'authorId' => $authorId,
            'encryptedNotesKey' => $key,
            'notesBlob' => $blob,
            'expectedVersion' => $expectedVersion,
        ]);
    }

    /** A well-formed wrapped key (72 bytes, base64): distinct per `$fill`, so a leak test can tell them apart. */
    private static function notesKey(string $fill): string
    {
        return base64_encode(str_repeat($fill, 40).random_bytes(32));
    }

    private function countRows(string $anketaId): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM anketa_private_notes WHERE anketa_id = ?',
            [$anketaId],
        );
    }

    private function makeCompany(string $name): Company
    {
        $company = new Company($name);
        $this->entityManager()->persist($company);
        $this->entityManager()->flush();
        $this->createdCompanyIds[] = $company->getId();

        return $company;
    }
}
