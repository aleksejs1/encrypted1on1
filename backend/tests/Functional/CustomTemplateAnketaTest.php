<?php

namespace App\Tests\Functional;

use App\Entity\Anketa;
use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GitHub issue #144 (#133 §5.3, §7.2–7.4, §8): anketas on a company template. Each test
 * works in a company of its own, with an admin (the manager) who writes templates
 * through the admin API and an employee; tearDown() removes both companies' rows.
 */
class CustomTemplateAnketaTest extends ApiTestCase
{
    use CleansUpCompanies;

    /** Children first; see CleansUpCompanies. */
    private const COMPANY_TABLES = ['anketas', 'custom_template_versions', 'custom_templates', 'activation_tokens', 'users'];

    public function testCreateResolvesTheTemplateToItsCurrentVersion(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('create');
        $templateId = $this->createTemplate($adminClient, 'Weekly sync');
        $this->updateTemplate($adminClient, $templateId, 'Weekly sync v2', 1);

        $created = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $templateId]);
        self::assertSame(201, $created['status'], (string) json_encode($created));
        $anketaId = $created['json']['id'];

        $detail = $this->jsonRequest($employeeClient, 'GET', "/api/anketas/{$anketaId}")['json'];
        self::assertSame('custom', $detail['templateKey']);
        self::assertSame('Weekly sync v2', $detail['customTemplateName']);
        self::assertSame($this->currentVersionId($templateId), $detail['customTemplateVersionId']);
        self::assertSame('custom', $detail['nextCycleTemplateKey']);
        self::assertSame($templateId, $detail['nextCustomTemplateId']);

        $row = self::findById($this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json'], $anketaId);
        self::assertSame('Weekly sync v2', $row['customTemplateName']);

        // The member endpoint serves the version the anketa references.
        $version = $this->jsonRequest($employeeClient, 'GET', "/api/template-versions/{$detail['customTemplateVersionId']}");
        self::assertSame(200, $version['status']);
        self::assertSame('Weekly sync v2', $version['json']['name']);
    }

    public function testCreateRefusesAnArchivedTemplate(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('create-archived');
        $templateId = $this->createTemplate($adminClient, 'Retired');
        $this->setArchived($adminClient, $templateId, true);

        $created = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $templateId]);

        $this->assertTemplateUnavailable($created);
        self::assertSame([], $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);
    }

    public function testCreateRefusesAnotherCompanysTemplate(): void
    {
        [$employeeClient, , $managerId] = $this->company('create-foreign');
        [, $otherAdminClient] = $this->company('create-foreign-other');
        $foreignTemplateId = $this->createTemplate($otherAdminClient, 'Theirs');

        $created = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $foreignTemplateId]);

        $this->assertTemplateUnavailable($created);
    }

    public function testCreateRefusesAnUnknownTemplate(): void
    {
        [$employeeClient, , $managerId] = $this->company('create-unknown');

        $created = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => '00000000-0000-0000-0000-000000000000']);

        $this->assertTemplateUnavailable($created);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mismatchedCreateProvider(): array
    {
        return [
            'custom without an id' => [['templateKey' => 'custom']],
            'custom with an empty id' => [['templateKey' => 'custom', 'customTemplateId' => '']],
            'an id with a built-in key' => [['templateKey' => 'regular', 'customTemplateId' => 'some-id']],
            'an id with the default key' => [['customTemplateId' => 'some-id']],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedCreateProvider')]
    public function testCreateRequiresAnIdWithTheCustomKeyAndOnlyThen(array $fields): void
    {
        [$employeeClient, , $managerId] = $this->company('create-mismatch');

        $created = $this->createAnketa($employeeClient, $managerId, $fields);

        self::assertSame(400, $created['status']);
        self::assertSame('customTemplateId', $created['json']['violations'][0]['property']);
        self::assertSame('"customTemplateId" is required with the "custom" template key, and only with it.', $created['json']['violations'][0]['message']);
    }

    /** §7.4: a custom anketa recurs on its template's latest version by default. */
    public function testTheDefaultSuccessorIsOnTheTemplatesCurrentVersion(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('recur');
        $templateId = $this->createTemplate($adminClient, 'Weekly sync');
        $anketaId = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $templateId])['json']['id'];
        $firstVersionId = $this->detail($employeeClient, $anketaId)['customTemplateVersionId'];
        $this->updateTemplate($adminClient, $templateId, 'Weekly sync v2', 1);

        // The open anketa keeps the version it was created on.
        self::assertSame($firstVersionId, $this->detail($employeeClient, $anketaId)['customTemplateVersionId']);
        self::assertSame('Weekly sync', $this->detail($employeeClient, $anketaId)['customTemplateName']);

        $successor = $this->detail($employeeClient, $this->archive($employeeClient, $anketaId));
        self::assertSame('custom', $successor['templateKey']);
        self::assertSame($this->currentVersionId($templateId), $successor['customTemplateVersionId']);
        self::assertNotSame($firstVersionId, $successor['customTemplateVersionId']);
        self::assertSame('Weekly sync v2', $successor['customTemplateName']);
    }

    /** §7.4: once the template is archived, the default goes back to Regular. */
    public function testAnArchivedTemplatesAnketaDefaultsToRegular(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('recur-archived');
        $templateId = $this->createTemplate($adminClient, 'Retired');
        $anketaId = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $templateId])['json']['id'];
        $this->setArchived($adminClient, $templateId, true);

        $detail = $this->detail($employeeClient, $anketaId);
        // The anketa itself still renders its version.
        self::assertSame('Retired', $detail['customTemplateName']);
        self::assertSame('regular', $detail['nextCycleTemplateKey']);
        self::assertNull($detail['nextCustomTemplateId']);

        $successor = $this->detail($employeeClient, $this->archive($employeeClient, $anketaId));
        self::assertSame('regular', $successor['templateKey']);
        self::assertNull($successor['customTemplateVersionId']);
    }

    /** P1's picker with a company template chosen, from a Regular anketa. */
    public function testArchiveCanMoveThePairOntoATemplate(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('archive-choose');
        $templateId = $this->createTemplate($adminClient, 'Weekly sync');
        $anketaId = $this->createAnketa($employeeClient, $managerId)['json']['id'];

        $successor = $this->detail($employeeClient, $this->archive($employeeClient, $anketaId, [
            'nextTemplateKey' => 'custom',
            'nextCustomTemplateId' => $templateId,
        ]));

        self::assertSame('custom', $successor['templateKey']);
        self::assertSame($this->currentVersionId($templateId), $successor['customTemplateVersionId']);
    }

    /** And off one again: an explicit built-in choice for a custom anketa. */
    public function testArchiveCanMoveThePairOffATemplate(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('archive-leave');
        $templateId = $this->createTemplate($adminClient, 'Weekly sync');
        $anketaId = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $templateId])['json']['id'];

        $successor = $this->detail($employeeClient, $this->archive($employeeClient, $anketaId, ['nextTemplateKey' => 'career_growth']));

        self::assertSame('career_growth', $successor['templateKey']);
        self::assertNull($successor['customTemplateVersionId']);
    }

    /**
     * §7.4, §8.1: the chosen template was archived while the form was open. The check
     * runs before anything is mutated, so the anketa stays open, and the refreshed
     * default then archives it.
     */
    public function testArchiveWithAChosenTemplateArchivedMeanwhileLeavesTheAnketaOpen(): void
    {
        [$employeeClient, $adminClient, $managerId] = $this->company('archive-race');
        $templateId = $this->createTemplate($adminClient, 'Weekly sync');
        $anketaId = $this->createAnketa($employeeClient, $managerId)['json']['id'];
        $this->setArchived($adminClient, $templateId, true);

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", $this->archiveBody([
            'nextTemplateKey' => 'custom',
            'nextCustomTemplateId' => $templateId,
        ]));

        $this->assertTemplateUnavailable($result);
        self::assertNull($this->detail($employeeClient, $anketaId)['archivedAt']);
        self::assertCount(1, $this->jsonRequest($employeeClient, 'GET', '/api/anketas')['json']);

        $successor = $this->detail($employeeClient, $this->archive($employeeClient, $anketaId));
        self::assertSame('regular', $successor['templateKey']);
    }

    public function testArchiveRefusesAnotherCompanysTemplate(): void
    {
        [$employeeClient, , $managerId] = $this->company('archive-foreign');
        [, $otherAdminClient] = $this->company('archive-foreign-other');
        $foreignTemplateId = $this->createTemplate($otherAdminClient, 'Theirs');
        $anketaId = $this->createAnketa($employeeClient, $managerId)['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", $this->archiveBody([
            'nextTemplateKey' => 'custom',
            'nextCustomTemplateId' => $foreignTemplateId,
        ]));

        $this->assertTemplateUnavailable($result);
        self::assertNull($this->detail($employeeClient, $anketaId)['archivedAt']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mismatchedArchiveProvider(): array
    {
        return [
            'custom without an id' => [['nextTemplateKey' => 'custom']],
            'an id with a built-in key' => [['nextTemplateKey' => 'regular', 'nextCustomTemplateId' => 'some-id']],
            'an id without a key' => [['nextCustomTemplateId' => 'some-id']],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mismatchedArchiveProvider')]
    public function testArchiveRequiresAnIdWithTheCustomKeyAndOnlyThen(array $fields): void
    {
        [$employeeClient, , $managerId] = $this->company('archive-mismatch');
        $anketaId = $this->createAnketa($employeeClient, $managerId)['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", $this->archiveBody($fields));

        self::assertSame(400, $result['status']);
        self::assertSame('nextCustomTemplateId', $result['json']['violations'][0]['property']);
        self::assertNull($this->detail($employeeClient, $anketaId)['archivedAt']);
    }

    /** An empty id with a built-in key counts as none, as the DTO treats it. */
    public function testAnEmptyTemplateIdWithABuiltInKeyIsIgnored(): void
    {
        [$employeeClient, , $managerId] = $this->company('empty-id');

        $created = $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'regular', 'customTemplateId' => '']);
        self::assertSame(201, $created['status'], (string) json_encode($created));

        $successor = $this->detail($employeeClient, $this->archive($employeeClient, $created['json']['id'], [
            'nextTemplateKey' => 'career_growth',
            'nextCustomTemplateId' => '',
        ]));
        self::assertSame('career_growth', $successor['templateKey']);
    }

    /** Like the other next-meeting fields, ignored when no next meeting is created. */
    public function testArchiveIgnoresTheTemplateFieldsWithSkipNextMeeting(): void
    {
        [$employeeClient, , $managerId] = $this->company('archive-skip');
        $anketaId = $this->createAnketa($employeeClient, $managerId)['json']['id'];

        $result = $this->jsonRequest($employeeClient, 'POST', "/api/anketas/{$anketaId}/archive", $this->archiveBody([
            'skipNextMeeting' => true,
            'nextTemplateKey' => 'custom',
        ]));

        self::assertSame(200, $result['status'], (string) json_encode($result));
    }

    /**
     * §5.4: the list and bulk query fetch-joins a custom anketa's version and template,
     * so rendering them costs no query per row.
     */
    public function testTheListQueryLoadsTheVersionAndTemplate(): void
    {
        [$employeeClient, $adminClient, $managerId, $employeeId] = $this->company('fetch-join');
        $templateId = $this->createTemplate($adminClient, 'Weekly sync');
        $this->createAnketa($employeeClient, $managerId, ['templateKey' => 'custom', 'customTemplateId' => $templateId]);

        $entityManager = $this->entityManager();
        $entityManager->clear();
        $employee = $entityManager->find(User::class, $employeeId);
        \assert($employee instanceof User);
        [$anketa] = $entityManager->getRepository(Anketa::class)->findAllForUser($employee);
        $version = $anketa->getCustomTemplateVersion();
        self::assertNotNull($version);
        $unitOfWork = $entityManager->getUnitOfWork();
        self::assertFalse($unitOfWork->isUninitializedObject($version));
        self::assertFalse($unitOfWork->isUninitializedObject($version->getTemplate()));
    }

    /**
     * A new company with an admin, who plays the manager, and an employee.
     *
     * @return array{KernelBrowser, KernelBrowser, string, string} the employee's client, the admin's client, the admin's id and the employee's id
     */
    private function company(string $label): array
    {
        // createClient() boots the kernel, and may only run once per test.
        $employeeClient = null === self::$kernel ? static::createClient() : $this->secondClient();
        $company = $this->makeCompany("Custom anketas {$label}");
        $employee = $this->activateUser($employeeClient, $this->uniqueEmail("custom-{$label}-emp"), company: $company);
        $adminClient = $this->secondClient();
        $admin = $this->activateUser($adminClient, $this->uniqueEmail("custom-{$label}-admin"), admin: true, company: $company);

        return [$employeeClient, $adminClient, $admin['id'], $employee['id']];
    }

    private function createTemplate(KernelBrowser $adminClient, string $name): string
    {
        $result = $this->jsonRequest($adminClient, 'POST', '/api/admin/templates', ['name' => $name, 'definition' => AdminTemplateControllerTest::definition()]);
        self::assertSame(201, $result['status'], (string) json_encode($result));

        return $result['json']['id'];
    }

    private function updateTemplate(KernelBrowser $adminClient, string $templateId, string $name, int $expectedVersion): void
    {
        $result = $this->jsonRequest($adminClient, 'PUT', "/api/admin/templates/{$templateId}", [
            'name' => $name,
            'description' => '',
            'definition' => AdminTemplateControllerTest::definition("{$name}?"),
            'expectedVersion' => $expectedVersion,
        ]);
        self::assertSame(200, $result['status'], (string) json_encode($result));
    }

    private function setArchived(KernelBrowser $adminClient, string $templateId, bool $archived): void
    {
        $result = $this->jsonRequest($adminClient, 'PUT', "/api/admin/templates/{$templateId}/archived", ['archived' => $archived]);
        self::assertSame(200, $result['status'], (string) json_encode($result));
    }

    private function currentVersionId(string $templateId): string
    {
        $id = $this->entityManager()->getConnection()->fetchOne(
            'SELECT v.id FROM custom_template_versions v JOIN custom_templates t ON t.id = v.template_id WHERE t.id = ? AND v.version = t.currentVersion',
            [$templateId],
        );
        \assert(\is_string($id));

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{status: int, json: mixed}
     */
    private function createAnketa(KernelBrowser $employeeClient, string $managerId, array $fields = []): array
    {
        return $this->jsonRequest($employeeClient, 'POST', '/api/anketas', $fields + [
            'counterpartId' => $managerId,
            'myRole' => 'employee',
            'meetingDate' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeImmutable::ATOM),
            'mySealedKey' => str_repeat('e', 44),
            'counterpartSealedKey' => str_repeat('m', 44),
            'periodicityDays' => 14,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(KernelBrowser $client, string $anketaId): array
    {
        $result = $this->jsonRequest($client, 'GET', "/api/anketas/{$anketaId}");
        self::assertSame(200, $result['status']);

        return $result['json'];
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function archiveBody(array $fields = []): array
    {
        return $fields + [
            'missed' => false,
            'skipNextMeeting' => false,
            'mySealedKey' => str_repeat('n', 44),
            'counterpartSealedKey' => str_repeat('o', 44),
        ];
    }

    /**
     * Archives with a successor and returns the successor's id.
     *
     * @param array<string, mixed> $fields
     */
    private function archive(KernelBrowser $client, string $anketaId, array $fields = []): string
    {
        $before = array_column($this->jsonRequest($client, 'GET', '/api/anketas')['json'], 'id');
        $result = $this->jsonRequest($client, 'POST', "/api/anketas/{$anketaId}/archive", $this->archiveBody($fields));
        self::assertSame(200, $result['status'], (string) json_encode($result));
        $new = array_values(array_diff(array_column($this->jsonRequest($client, 'GET', '/api/anketas')['json'], 'id'), $before));
        self::assertCount(1, $new);

        return $new[0];
    }

    /**
     * @param array{status: int, json: mixed} $result
     */
    private function assertTemplateUnavailable(array $result): void
    {
        self::assertSame(422, $result['status'], (string) json_encode($result));
        self::assertSame('template_unavailable', $result['json']['code']);
        self::assertSame('This template is no longer available. Choose another meeting type.', $result['json']['error']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function findById(array $rows, string $id): array
    {
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        self::fail("No row with id {$id}");
    }
}
