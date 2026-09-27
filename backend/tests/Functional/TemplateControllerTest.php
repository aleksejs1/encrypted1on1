<?php

namespace App\Tests\Functional;

use App\Entity\Company;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * GitHub issue #142 (#133 §6, §8.2): the member endpoints of the company template
 * library. Each test works in companies of its own; see AdminTemplateControllerTest.
 */
class TemplateControllerTest extends ApiTestCase
{
    use CleansUpCompanies;

    /** Children first; see CleansUpCompanies. */
    private const COMPANY_TABLES = ['custom_template_versions', 'custom_templates', 'activation_tokens', 'users'];

    public function testMembersListActiveTemplatesOnlyWithoutDefinitions(): void
    {
        [$admin, $member] = $this->adminAndMember('list');
        $olderId = $this->createTemplate($admin, 'Monthly', '');
        $activeId = $this->createTemplate($admin, 'Weekly', 'Short');
        $archivedId = $this->createTemplate($admin, 'Retired', '');
        $this->jsonRequest($admin, 'PUT', "/api/admin/templates/{$archivedId}/archived", ['archived' => true]);

        $result = $this->jsonRequest($member, 'GET', '/api/templates');

        self::assertSame(200, $result['status']);
        self::assertEqualsCanonicalizing([
            ['id' => $activeId, 'name' => 'Weekly', 'description' => 'Short'],
            ['id' => $olderId, 'name' => 'Monthly', 'description' => ''],
        ], $result['json']);
    }

    public function testTheListShowsEachTemplatesCurrentVersion(): void
    {
        [$admin, $member] = $this->adminAndMember('current');
        $id = $this->createTemplate($admin, 'Old name', '');
        $this->jsonRequest($admin, 'PUT', "/api/admin/templates/{$id}", [
            'name' => 'New name',
            'description' => '',
            'definition' => AdminTemplateControllerTest::definition(),
            'expectedVersion' => 1,
        ]);

        self::assertSame(['New name'], array_column($this->jsonRequest($member, 'GET', '/api/templates')['json'], 'name'));
    }

    /** An existing anketa must keep rendering its version, even once edited or archived. */
    public function testAnyVersionIsReadableByIdEvenOnceArchived(): void
    {
        [$admin, $member] = $this->adminAndMember('version');
        $id = $this->createTemplate($admin, 'First', '');
        $versionOneId = $this->versionId($id, 1);
        $this->jsonRequest($admin, 'PUT', "/api/admin/templates/{$id}", [
            'name' => 'Second',
            'description' => '',
            'definition' => AdminTemplateControllerTest::definition('Other question'),
            'expectedVersion' => 1,
        ]);
        $this->jsonRequest($admin, 'PUT', "/api/admin/templates/{$id}/archived", ['archived' => true]);

        $result = $this->jsonRequest($member, 'GET', "/api/template-versions/{$versionOneId}");

        self::assertSame(200, $result['status']);
        self::assertSame(['name' => 'First', 'definition' => AdminTemplateControllerTest::definition()], $result['json']);
    }

    public function testAnotherCompanysTemplatesAreInvisible(): void
    {
        [$admin] = $this->adminAndMember('tenant-a');
        $id = $this->createTemplate($admin, 'A only', '');
        $otherMember = $this->secondClient();
        $this->activateUser($otherMember, $this->uniqueEmail('templates-tenant-b'), company: $this->makeCompany('Templates tenant B'));

        self::assertSame([], $this->jsonRequest($otherMember, 'GET', '/api/templates')['json']);
        $result = $this->jsonRequest($otherMember, 'GET', '/api/template-versions/'.$this->versionId($id, 1));
        self::assertSame(404, $result['status']);
        self::assertSame('Template not found.', $result['json']['error']);
    }

    public function testAnUnknownVersionIsNotFound(): void
    {
        [, $member] = $this->adminAndMember('unknown');

        self::assertSame(404, $this->jsonRequest($member, 'GET', '/api/template-versions/no-such-version')['status']);
    }

    public function testGuestsAreUnauthenticated(): void
    {
        $client = static::createClient();

        self::assertSame(401, $this->jsonRequest($client, 'GET', '/api/templates')['status']);
        self::assertSame(401, $this->jsonRequest($client, 'GET', '/api/template-versions/anything')['status']);
    }

    /**
     * An admin and a plain member of a new company, each logged in on their own client.
     *
     * @return array{KernelBrowser, KernelBrowser}
     */
    private function adminAndMember(string $label): array
    {
        $admin = static::createClient();
        $company = $this->makeCompany("Templates {$label}");
        $this->activateUser($admin, $this->uniqueEmail("templates-{$label}-admin"), admin: true, company: $company);
        $member = $this->secondClient();
        $this->activateUser($member, $this->uniqueEmail("templates-{$label}-member"), company: $company);

        return [$admin, $member];
    }

    private function createTemplate(KernelBrowser $admin, string $name, string $description): string
    {
        $result = $this->jsonRequest($admin, 'POST', '/api/admin/templates', [
            'name' => $name,
            'description' => $description,
            'definition' => AdminTemplateControllerTest::definition(),
        ]);
        self::assertSame(201, $result['status'], (string) json_encode($result));
        \assert(\is_string($result['json']['id']));

        return $result['json']['id'];
    }

    private function versionId(string $templateId, int $version): string
    {
        $id = $this->entityManager()->getConnection()->fetchOne(
            'SELECT id FROM custom_template_versions WHERE template_id = ? AND version = ?',
            [$templateId, $version],
        );
        \assert(\is_string($id));

        return $id;
    }
}
