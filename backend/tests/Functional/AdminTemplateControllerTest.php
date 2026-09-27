<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use App\Entity\User;
use App\Http\TemplateRequestBody;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GitHub issue #142 (#133 §6, §8): the admin template library. Every test works in a
 * company of its own, so the per-company cap and the tenant boundary are exercised in
 * isolation; tearDown() removes those companies and everything in them.
 */
class AdminTemplateControllerTest extends ApiTestCase
{
    use CleansUpCompanies;

    /** Children first; see CleansUpCompanies. */
    private const COMPANY_TABLES = ['custom_template_versions', 'custom_templates', 'activation_tokens', 'users'];

    /**
     * A definition that passes TemplateDefinitionValidator, as the JSON the editor sends.
     *
     * @return array<string, mixed>
     */
    public static function definition(string $title = 'How is your week?'): array
    {
        return [
            'schemaVersion' => 1,
            'employee' => [
                ['kind' => 'builtin', 'questionId' => 'mood'],
                ['kind' => 'custom', 'id' => 'c_aaaaaaaaaa', 'title' => $title, 'field' => [
                    'id' => 'c_bbbbbbbbbb',
                    'type' => 'radio',
                    'options' => [['value' => 'o_00000001', 'label' => 'Good'], ['value' => 'o_00000002', 'label' => 'Bad']],
                ]],
            ],
            'manager' => [['kind' => 'builtin', 'questionId' => 'feedback']],
        ];
    }

    public function testCreateAppendsVersionOneAndListShowsIt(): void
    {
        [$client] = $this->adminInNewCompany('create');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', [
            'name' => '  Weekly sync  ',
            'description' => 'Short and focused',
            'definition' => self::definition(" \tHow is your week?\n"),
        ]);

        self::assertSame(201, $result['status']);
        self::assertSame(1, $result['json']['version']);
        $list = $this->jsonRequest($client, 'GET', '/api/admin/templates');
        self::assertSame(200, $list['status']);
        self::assertCount(1, $list['json']);
        $row = $list['json'][0];
        self::assertSame($result['json']['id'], $row['id']);
        self::assertSame(1, $row['currentVersion']);
        self::assertNull($row['archivedAt']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $row['updatedAt']));
        // Stored trimmed, the definition's texts included.
        self::assertSame('Weekly sync', $row['name']);
        self::assertSame('Short and focused', $row['description']);
        self::assertSame(self::definition(), $row['definition']);
    }

    public function testDescriptionMayBeOmitted(): void
    {
        [$client] = $this->adminInNewCompany('no-description');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', ['name' => 'N', 'definition' => self::definition()]);

        self::assertSame(201, $result['status']);
        self::assertSame('', $this->jsonRequest($client, 'GET', '/api/admin/templates')['json'][0]['description']);
    }

    public function testAnInvalidDefinitionIsRejectedWithTheValidatorsErrors(): void
    {
        [$client] = $this->adminInNewCompany('bad-definition');
        $definition = self::definition();
        $definition['employee'][0]['questionId'] = 'feedback';

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', ['name' => 'N', 'definition' => $definition]);

        self::assertSame(400, $result['status']);
        self::assertSame("The template's questions are invalid.", $result['json']['error']);
        self::assertSame([['path' => 'employee/0/questionId', 'code' => 'builtin_not_allowed']], $result['json']['errors']);
        self::assertSame([], $this->jsonRequest($client, 'GET', '/api/admin/templates')['json']);
    }

    public function testAMissingDefinitionIsRejected(): void
    {
        [$client] = $this->adminInNewCompany('no-definition');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', ['name' => 'N']);

        self::assertSame(400, $result['status']);
        self::assertSame([['path' => '', 'code' => 'type']], $result['json']['errors']);
    }

    /** The {} vs [] distinction survives the request (the body is decoded non-associatively). */
    public function testAnEmptyObjectIsNotAListOfBlocks(): void
    {
        [$client] = $this->adminInNewCompany('empty-object');
        $body = '{"name":"N","definition":{"schemaVersion":1,"employee":{},"manager":[{"kind":"builtin","questionId":"feedback"}]}}';

        $client->request('POST', '/api/admin/templates', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($client),
        ], content: $body);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $json = json_decode((string) $client->getResponse()->getContent(), true);
        \assert(\is_array($json));
        self::assertSame([['path' => 'employee', 'code' => 'type']], $json['errors']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidTextProvider(): array
    {
        return [
            'empty name' => [['name' => '   '], 'name'],
            'name too long' => [['name' => str_repeat('ж', 121)], 'name'],
            'name with a newline' => [['name' => "a\nb"], 'name'],
            'description too long' => [['name' => 'N', 'description' => str_repeat('d', 301)], 'description'],
            'description with a bidi override' => [['name' => 'N', 'description' => "a\u{202E}b"], 'description'],
        ];
    }

    /** The limits are written into the messages, so they must match the constants. */
    public function testTheTextMessagesStateTheActualLimits(): void
    {
        [$client] = $this->adminInNewCompany('text-messages');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', [
            'name' => '',
            'description' => str_repeat('d', 301),
            'definition' => self::definition(),
        ]);

        self::assertSame(400, $result['status']);
        // Every message, one per line, as JsonExceptionListener gives DTO errors.
        self::assertSame(implode("\n", array_column($result['json']['violations'], 'message')), $result['json']['error']);
        self::assertSame([
            ['property' => 'name', 'message' => 'The name must be 1 to '.CustomTemplateVersion::MAX_NAME_LENGTH.' characters on one line, with no invisible or control characters.'],
            ['property' => 'description', 'message' => 'The description must be at most '.CustomTemplateVersion::MAX_DESCRIPTION_LENGTH.' characters on one line, with no invisible or control characters.'],
        ], $result['json']['violations']);
    }

    public function testAnUpdateWithAnInvalidNameIsRejected(): void
    {
        [$client] = $this->adminInNewCompany('update-bad-name');
        $id = $this->createTemplate($client, 'T');

        $result = $this->update($client, $id, '', 1);

        self::assertSame(400, $result['status']);
        self::assertSame(['name'], array_column($result['json']['violations'], 'property'));
        self::assertSame(['T'], $this->versionNames($id));
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidTextProvider')]
    public function testInvalidNameOrDescriptionIsRejected(array $fields, string $property): void
    {
        [$client] = $this->adminInNewCompany('bad-text');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', $fields + ['definition' => self::definition()]);

        self::assertSame(400, $result['status']);
        self::assertSame([$property], array_column($result['json']['violations'], 'property'));
    }

    public function testNameAndDescriptionAtTheirLimitsAreValid(): void
    {
        [$client] = $this->adminInNewCompany('text-limits');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', [
            'name' => str_repeat('ž', 120),
            'description' => str_repeat('ж', 300),
            'definition' => self::definition(),
        ]);

        self::assertSame(201, $result['status']);
    }

    public function testUpdateAppendsAVersionAndKeepsTheOldOne(): void
    {
        [$client] = $this->adminInNewCompany('update');
        $id = $this->createTemplate($client, 'Before');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", [
            'name' => 'After',
            'description' => '',
            'definition' => self::definition('Changed question'),
            'expectedVersion' => 1,
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame(2, $result['json']['version']);
        $row = $this->jsonRequest($client, 'GET', '/api/admin/templates')['json'][0];
        self::assertSame(2, $row['currentVersion']);
        self::assertSame('After', $row['name']);
        self::assertSame('Changed question', $row['definition']['employee'][1]['title']);
        // Version 1 is still there, unchanged.
        self::assertSame(['Before', 'After'], $this->versionNames($id));
    }

    public function testUpdateChangesTheDescription(): void
    {
        [$client] = $this->adminInNewCompany('update-description');
        $id = $this->createTemplate($client, 'T');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", [
            'name' => 'T',
            'description' => ' Now with a description ',
            'definition' => self::definition(),
            'expectedVersion' => 1,
        ]);

        self::assertSame(2, $result['json']['version']);
        self::assertSame('Now with a description', $this->jsonRequest($client, 'GET', '/api/admin/templates')['json'][0]['description']);
    }

    public function testAnUpdateWithAnInvalidDescriptionIsRejected(): void
    {
        [$client] = $this->adminInNewCompany('update-bad-description');
        $id = $this->createTemplate($client, 'T');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", [
            'name' => 'T',
            'description' => str_repeat('d', 301),
            'definition' => self::definition(),
            'expectedVersion' => 1,
        ]);

        self::assertSame(400, $result['status']);
        self::assertSame(['description'], array_column($result['json']['violations'], 'property'));
    }

    public function testAStaleExpectedVersionGetsAConflictWithTheCurrentRow(): void
    {
        [$client] = $this->adminInNewCompany('stale');
        $id = $this->createTemplate($client, 'First');
        $this->update($client, $id, 'Second', 1);

        $result = $this->update($client, $id, 'Mine', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('version_conflict', $result['json']['code']);
        self::assertSame('This template was changed by someone else. Reload to see their version.', $result['json']['error']);
        self::assertSame(2, $result['json']['current']['currentVersion']);
        self::assertSame('Second', $result['json']['current']['name']);
        self::assertSame(['First', 'Second'], $this->versionNames($id));
    }

    public function testASaveThatChangesNothingAppendsNothing(): void
    {
        [$client] = $this->adminInNewCompany('no-op');
        $id = $this->createTemplate($client, 'Same');

        // Untrimmed, but the same once trimmed: still nothing to save.
        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", [
            'name' => ' Same ',
            'description' => '',
            'definition' => self::definition(' How is your week? '),
            'expectedVersion' => 1,
        ]);

        self::assertSame(200, $result['status']);
        self::assertSame(1, $result['json']['version']);
        self::assertSame(['Same'], $this->versionNames($id));
    }

    public function testAStaleNoOpSaveStillGetsAConflict(): void
    {
        [$client] = $this->adminInNewCompany('stale-no-op');
        $id = $this->createTemplate($client, 'First');
        $this->update($client, $id, 'Second', 1);

        // The same content as the current version, but against an old one.
        $result = $this->update($client, $id, 'Second', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('version_conflict', $result['json']['code']);
    }

    public function testUpdateRequiresExpectedVersion(): void
    {
        [$client] = $this->adminInNewCompany('no-expected-version');
        $id = $this->createTemplate($client, 'T');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", ['name' => 'T2', 'description' => '', 'definition' => self::definition()]);

        self::assertSame(400, $result['status']);
        self::assertSame([['property' => 'expectedVersion', 'message' => 'Missing or invalid "expectedVersion".']], $result['json']['violations']);
    }

    /** Unlike on create, a missing description is an error, not a request to clear it. */
    public function testUpdateRequiresTheDescription(): void
    {
        [$client] = $this->adminInNewCompany('update-no-description');
        $id = $this->createTemplate($client, 'T');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", ['name' => 'T2', 'definition' => self::definition(), 'expectedVersion' => 1]);

        self::assertSame(400, $result['status']);
        self::assertSame([['property' => 'description', 'message' => 'Missing or invalid "description".']], $result['json']['violations']);
        self::assertSame(['T'], $this->versionNames($id));
    }

    /**
     * The template's state is the answer whatever else is wrong with the body: a 404,
     * then an archived template, then a stale version (#133 §8.1).
     */
    public function testTheTemplatesStateIsCheckedBeforeTheTextFields(): void
    {
        [$client] = $this->adminInNewCompany('state-first');
        $archivedId = $this->createTemplate($client, 'Archived');
        $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$archivedId}/archived", ['archived' => true]);
        $staleId = $this->createTemplate($client, 'Stale');
        $this->update($client, $staleId, 'Stale 2', 1);
        $badBody = ['name' => '', 'definition' => self::definition(), 'expectedVersion' => 1];

        self::assertSame(404, $this->jsonRequest($client, 'PUT', '/api/admin/templates/no-such-template', $badBody)['status']);
        $archived = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$archivedId}", $badBody);
        self::assertSame([409, 'template_archived'], [$archived['status'], $archived['json']['code']]);
        $stale = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$staleId}", $badBody);
        self::assertSame([409, 'version_conflict'], [$stale['status'], $stale['json']['code']]);
    }

    /** A save that changes nothing, racing another admin's save, still gets its conflict. */
    public function testANoOpSaveThatLosesTheRaceGetsAConflict(): void
    {
        [$client, $admin] = $this->adminInNewCompany('race-no-op');
        $id = $this->createTemplate($client, 'T');

        $this->raceOnLoad($client, $id, static function (Connection $connection) use ($id, $admin): void {
            $connection->executeStatement('UPDATE custom_templates SET currentVersion = 2 WHERE id = ?', [$id]);
            $connection->insert('custom_template_versions', [
                'id' => '00000000-0000-7000-8000-000000000003',
                'template_id' => $id,
                'company_id' => $admin->getCompany()->getId(),
                'version' => 2,
                'name' => 'Theirs',
                'description' => '',
                'definition' => '{}',
                'createdBy_id' => $admin->getId(),
                'createdAt' => new \DateTimeImmutable(),
            ], ['createdAt' => Types::DATETIME_IMMUTABLE]);
        });
        $result = $this->update($client, $id, 'T', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('Theirs', $result['json']['current']['name']);
    }

    /** Same, with an archive in between. */
    public function testANoOpSaveRacingAnArchiveGetsTemplateArchived(): void
    {
        [$client] = $this->adminInNewCompany('race-no-op-archive');
        $id = $this->createTemplate($client, 'T');

        $this->raceOnLoad($client, $id, static function (Connection $connection) use ($id): void {
            $connection->executeStatement('UPDATE custom_templates SET archivedAt = ? WHERE id = ?', [new \DateTimeImmutable(), $id], [Types::DATETIME_IMMUTABLE]);
        });
        $result = $this->update($client, $id, 'T', 1);

        self::assertSame([409, 'template_archived'], [$result['status'], $result['json']['code']]);
    }

    /** A body that isn't one JSON object is rejected before anything else is read. */
    public function testABodyThatIsNotAnObjectIsRejected(): void
    {
        [$client] = $this->adminInNewCompany('not-an-object');
        $id = $this->createTemplate($client, 'T');

        foreach (['[1]', '"text"', '{broken'] as $content) {
            $client->request('PUT', "/api/admin/templates/{$id}", server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->csrfToken($client),
            ], content: $content);
            self::assertSame(400, $client->getResponse()->getStatusCode(), $content);
            self::assertSame(['error' => 'The request body is not valid JSON.'], json_decode((string) $client->getResponse()->getContent(), true));
        }
    }

    /** An archived template is the answer even when the body is also invalid (#133 §8.1). */
    public function testAnArchivedTemplateIsReportedBeforeAnInvalidDefinition(): void
    {
        [$client] = $this->adminInNewCompany('archived-invalid');
        $id = $this->createTemplate($client, 'T');
        $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}/archived", ['archived' => true]);

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", [
            'name' => 'T2',
            'description' => '',
            'definition' => ['schemaVersion' => 2],
            'expectedVersion' => 1,
        ]);

        self::assertSame(409, $result['status']);
        self::assertSame('template_archived', $result['json']['code']);
    }

    /**
     * PHP's non-associative decode can't read an object key starting with a NUL byte
     * (an associative one could). A plain 400, not a definition error.
     */
    public function testABodyTheDefinitionDecoderCantReadIsRejected(): void
    {
        [$client] = $this->adminInNewCompany('undecodable');

        $client->request('POST', '/api/admin/templates', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($client),
        ], content: '{"name":"T","definition":{"\u0000x":1}}');

        self::assertSame(400, $client->getResponse()->getStatusCode());
        self::assertSame(['error' => 'The request body is not valid JSON.'], json_decode((string) $client->getResponse()->getContent(), true));
    }

    public function testExpectedVersionMustBeAVersion(): void
    {
        [$client] = $this->adminInNewCompany('expected-version-zero');
        $id = $this->createTemplate($client, 'T');

        $result = $this->update($client, $id, 'T2', 0);

        self::assertSame(400, $result['status']);
        self::assertSame(['expectedVersion'], array_column($result['json']['violations'], 'property'));
    }

    public function testArchiveAndRestore(): void
    {
        [$client] = $this->adminInNewCompany('archive');
        $archivedId = $this->createTemplate($client, 'Old');
        $activeId = $this->createTemplate($client, 'Current');

        $archived = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$archivedId}/archived", ['archived' => true]);
        self::assertSame(200, $archived['status']);
        self::assertSame($archivedId, $archived['json']['id']);
        self::assertNotNull($archived['json']['archivedAt']);

        // Archived templates are listed after the active ones.
        $list = $this->jsonRequest($client, 'GET', '/api/admin/templates')['json'];
        self::assertSame([$activeId, $archivedId], array_column($list, 'id'));

        $restored = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$archivedId}/archived", ['archived' => false]);
        self::assertSame(200, $restored['status']);
        self::assertNull($restored['json']['archivedAt']);
    }

    public function testArchivingRequiresTheFlag(): void
    {
        [$client] = $this->adminInNewCompany('archive-flag');
        $id = $this->createTemplate($client, 'T');

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}/archived", ['archived' => 'yes']);

        self::assertSame(400, $result['status']);
        self::assertSame([['property' => 'archived', 'message' => 'Missing or invalid "archived".']], $result['json']['violations']);
    }

    public function testAnArchivedTemplateCantBeEdited(): void
    {
        [$client] = $this->adminInNewCompany('archived-edit');
        $id = $this->createTemplate($client, 'T');
        $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}/archived", ['archived' => true]);

        $result = $this->update($client, $id, 'T2', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('template_archived', $result['json']['code']);
        self::assertSame('This template is archived. Restore it before editing.', $result['json']['error']);
        self::assertSame(['T'], $this->versionNames($id));
    }

    /** The archived state is checked before the version: an archived template is the answer even to a stale save. */
    public function testAStaleSaveOfAnArchivedTemplateGetsTemplateArchived(): void
    {
        [$client] = $this->adminInNewCompany('archived-stale');
        $id = $this->createTemplate($client, 'T');
        $this->update($client, $id, 'T2', 1);
        $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}/archived", ['archived' => true]);

        $result = $this->update($client, $id, 'Mine', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('template_archived', $result['json']['code']);
    }

    /**
     * #133 §8.1: another save lands between this request's checks and its write. The
     * conditional UPDATE matches nothing, and the answer is a conflict with the row
     * that won.
     */
    public function testASaveThatLosesTheRaceGetsAConflict(): void
    {
        [$client, $admin] = $this->adminInNewCompany('race-save');
        $id = $this->createTemplate($client, 'T');

        $this->raceOnLoad($client, $id, static function (Connection $connection) use ($id, $admin): void {
            $connection->executeStatement('UPDATE custom_templates SET currentVersion = 2 WHERE id = ?', [$id]);
            $connection->insert('custom_template_versions', [
                'id' => '00000000-0000-7000-8000-000000000002',
                'template_id' => $id,
                'company_id' => $admin->getCompany()->getId(),
                'version' => 2,
                'name' => 'Theirs',
                'description' => '',
                'definition' => '{}',
                'createdBy_id' => $admin->getId(),
                'createdAt' => new \DateTimeImmutable(),
            ], ['createdAt' => Types::DATETIME_IMMUTABLE]);
        });
        $result = $this->update($client, $id, 'Mine', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('version_conflict', $result['json']['code']);
        self::assertSame(2, $result['json']['current']['currentVersion']);
        self::assertSame('Theirs', $result['json']['current']['name']);
        self::assertSame(['T', 'Theirs'], $this->versionNames($id));
    }

    /** Same, but the template is archived in between: that's the answer, not a version conflict. */
    public function testASaveRacingAnArchiveGetsTemplateArchived(): void
    {
        [$client] = $this->adminInNewCompany('race-archive');
        $id = $this->createTemplate($client, 'T');

        $this->raceOnLoad($client, $id, static function (Connection $connection) use ($id): void {
            $connection->executeStatement('UPDATE custom_templates SET archivedAt = ? WHERE id = ?', [new \DateTimeImmutable(), $id], [Types::DATETIME_IMMUTABLE]);
        });
        $result = $this->update($client, $id, 'Mine', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('template_archived', $result['json']['code']);
        self::assertSame(['T'], $this->versionNames($id));
    }

    /**
     * The unique (template_id, version) constraint is the backstop behind the
     * conditional UPDATE: a version row already there for the next number is mapped to
     * the same 409.
     */
    public function testTheUniqueVersionConstraintIsMappedToAConflict(): void
    {
        [$client, $admin] = $this->adminInNewCompany('unique');
        $id = $this->createTemplate($client, 'T');
        $connection = $this->entityManager()->getConnection();
        $connection->insert('custom_template_versions', [
            'id' => '00000000-0000-7000-8000-000000000001',
            'template_id' => $id,
            'company_id' => $admin->getCompany()->getId(),
            'version' => 2,
            'name' => 'Stray',
            'description' => '',
            'definition' => '{}',
            'createdBy_id' => $admin->getId(),
            'createdAt' => new \DateTimeImmutable(),
        ], ['createdAt' => Types::DATETIME_IMMUTABLE]);

        $result = $this->update($client, $id, 'Mine', 1);

        self::assertSame(409, $result['status']);
        self::assertSame('version_conflict', $result['json']['code']);
        self::assertSame('This template was changed by someone else. Reload to see their version.', $result['json']['error']);
        // The bump was rolled back with the failed insert.
        self::assertSame(1, $connection->fetchOne('SELECT currentVersion FROM custom_templates WHERE id = ?', [$id]));
    }

    public function testTheCompanyTemplateCapIncludesArchivedOnes(): void
    {
        [$client, $admin] = $this->adminInNewCompany('cap');
        $entityManager = $this->entityManager();
        $admin = $entityManager->find(User::class, $admin->getId());
        \assert($admin instanceof User);
        for ($i = 0; $i < CustomTemplate::MAX_PER_COMPANY; ++$i) {
            $template = new CustomTemplate($admin);
            $template->setArchived(0 === $i % 2);
            $entityManager->persist($template);
            $entityManager->persist(new CustomTemplateVersion($template, 1, "T{$i}", '', '{}', $admin));
        }
        $entityManager->flush();

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', ['name' => 'One more', 'definition' => self::definition()]);

        self::assertSame(422, $result['status']);
        self::assertSame('template_limit', $result['json']['code']);
        self::assertStringContainsString('50', $result['json']['error']);
    }

    /**
     * The limit counts saves that write a version. Rejected and unchanged saves don't
     * count, so an admin at the template cap isn't locked out of editing by their own
     * failed attempts.
     */
    public function testSavesThatWriteAreRateLimitedPerAdmin(): void
    {
        [$client] = $this->adminInNewCompany('rate');
        $id = $this->createTemplate($client, 'T0');
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(400, $this->jsonRequest($client, 'POST', '/api/admin/templates', ['name' => ''])['status']);
            self::assertSame(200, $this->update($client, $id, 'T0', 1)['status']);
        }

        // The configured limit (backend/.env), of which the create was the first.
        $limit = (int) $_ENV['TEMPLATE_SAVE_RATE_LIMIT'];
        for ($version = 1; $version < $limit; ++$version) {
            self::assertSame(200, $this->update($client, $id, "T{$version}", $version)['status'], "save {$version}");
        }
        $result = $this->update($client, $id, 'One too many', $limit);

        self::assertSame(429, $result['status']);
        self::assertSame('Too many requests. Please try again later.', $result['json']['error']);
    }

    public function testAnOversizedBodyIsRejectedUndecoded(): void
    {
        [$client] = $this->adminInNewCompany('oversized');

        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', [
            'name' => 'N',
            'description' => str_repeat('d', TemplateRequestBody::MAX_BODY_BYTES),
            'definition' => self::definition(),
        ]);

        self::assertSame(413, $result['status']);
        self::assertSame('The request is too large.', $result['json']['error']);
    }

    /** A declared length over the cap is refused without reading the body. */
    public function testAnOversizedDeclaredLengthIsRejected(): void
    {
        [$client] = $this->adminInNewCompany('declared-length');

        $client->request('POST', '/api/admin/templates', server: [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) (TemplateRequestBody::MAX_BODY_BYTES + 1),
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($client),
        ], content: (string) json_encode(['name' => 'N', 'definition' => self::definition()]));

        self::assertSame(413, $client->getResponse()->getStatusCode());
    }

    /** Exactly the cap is still read: JSON allows trailing whitespace to pad to it. */
    public function testABodyOfExactlyTheCapIsRead(): void
    {
        [$client] = $this->adminInNewCompany('body-at-cap');
        $json = (string) json_encode(['name' => 'N', 'definition' => self::definition()]);

        $client->request('POST', '/api/admin/templates', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->csrfToken($client),
            'CONTENT_LENGTH' => (string) TemplateRequestBody::MAX_BODY_BYTES,
        ], content: str_pad($json, TemplateRequestBody::MAX_BODY_BYTES));

        self::assertSame(201, $client->getResponse()->getStatusCode());
    }

    public function testNonAdminsAreForbiddenAndGuestsUnauthenticated(): void
    {
        [$adminClient, $admin] = $this->adminInNewCompany('perm');
        $id = $this->createTemplate($adminClient, 'T');
        $memberClient = $this->secondClient();
        $this->activateUser($memberClient, $this->uniqueEmail('perm-member'), company: $admin->getCompany());

        $requests = [
            ['GET', '/api/admin/templates', null],
            ['POST', '/api/admin/templates', ['name' => 'N', 'definition' => self::definition()]],
            ['PUT', "/api/admin/templates/{$id}", ['name' => 'N', 'description' => '', 'definition' => self::definition(), 'expectedVersion' => 1]],
            ['PUT', "/api/admin/templates/{$id}/archived", ['archived' => true]],
        ];
        foreach ($requests as [$method, $path, $body]) {
            self::assertSame(403, $this->jsonRequest($memberClient, $method, $path, $body)['status'], "{$method} {$path}");
            // The admin gate comes before anything about the body.
            if (null !== $body) {
                self::assertSame(403, $this->jsonRequest($memberClient, $method, $path, [])['status'], "{$method} {$path} with an empty body");
            }
        }

        $this->jsonRequest($memberClient, 'POST', '/api/logout');
        self::assertSame(401, $this->jsonRequest($memberClient, 'GET', '/api/admin/templates')['status']);
    }

    public function testAnotherCompanysAdminCantSeeOrTouchTheTemplate(): void
    {
        [$clientA] = $this->adminInNewCompany('tenant-a');
        $id = $this->createTemplate($clientA, 'A only');
        $clientB = $this->secondClient();
        $this->activateUser($clientB, $this->uniqueEmail('tenant-b'), admin: true, company: $this->makeCompany('Tenant B'));

        self::assertSame([], $this->jsonRequest($clientB, 'GET', '/api/admin/templates')['json']);
        self::assertSame(404, $this->update($clientB, $id, 'Stolen', 1)['status']);
        self::assertSame(404, $this->jsonRequest($clientB, 'PUT', "/api/admin/templates/{$id}/archived", ['archived' => true])['status']);
        self::assertSame(['A only'], $this->versionNames($id));
        self::assertNull($this->jsonRequest($clientA, 'GET', '/api/admin/templates')['json'][0]['archivedAt']);
    }

    /**
     * @return array{KernelBrowser, User}
     */
    private function adminInNewCompany(string $label): array
    {
        $client = static::createClient();
        $company = $this->makeCompany("Templates {$label}");
        $admin = $this->activateUser($client, $this->uniqueEmail("templates-{$label}"), admin: true, company: $company);
        $user = $this->entityManager()->find(User::class, $admin['id']);
        \assert($user instanceof User);

        return [$client, $user];
    }

    private function createTemplate(KernelBrowser $client, string $name): string
    {
        $result = $this->jsonRequest($client, 'POST', '/api/admin/templates', ['name' => $name, 'definition' => self::definition()]);
        self::assertSame(201, $result['status'], (string) json_encode($result));
        \assert(\is_string($result['json']['id']));

        return $result['json']['id'];
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function update(KernelBrowser $client, string $id, string $name, int $expectedVersion): array
    {
        return $this->jsonRequest($client, 'PUT', "/api/admin/templates/{$id}", [
            'name' => $name,
            'description' => '',
            'definition' => self::definition(),
            'expectedVersion' => $expectedVersion,
        ]);
    }

    /** @return list<string> every version's name, oldest first */
    private function versionNames(string $templateId): array
    {
        /** @var list<string> $names */
        $names = $this->entityManager()->getConnection()->fetchFirstColumn(
            'SELECT name FROM custom_template_versions WHERE template_id = ? ORDER BY version',
            [$templateId],
        );

        return $names;
    }

    /**
     * Runs $race behind the EntityManager's back the first time the next request loads
     * the template, i.e. after the controller's own checks and before its conditional
     * UPDATE: the same technique as AnketaControllerTest's archive race.
     *
     * @param \Closure(Connection): void $race
     */
    private function raceOnLoad(KernelBrowser $client, string $templateId, \Closure $race): void
    {
        // Keeps this kernel, and so the listener, for the next request.
        $client->disableReboot();
        $listener = new class($this->entityManager()->getConnection(), $templateId, $race) {
            private bool $done = false;

            /** @param \Closure(Connection): void $race */
            public function __construct(private readonly Connection $connection, private readonly string $templateId, private readonly \Closure $race)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                $template = $args->getObject();
                if ($this->done || !$template instanceof CustomTemplate || $template->getId() !== $this->templateId) {
                    return;
                }
                $this->done = true;
                ($this->race)($this->connection);
            }
        };
        $this->entityManager()->getEventManager()->addEventListener(Events::postLoad, $listener);
        $this->entityManager()->clear();
    }
}
