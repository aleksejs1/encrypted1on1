<?php

namespace App\Tests\Functional;

use App\Doctrine\CompanyFilter;
use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use App\Entity\User;
use App\Repository\CustomTemplateRepository;
use App\Repository\CustomTemplateVersionRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;

/**
 * GitHub issue #142: the template repositories scope every lookup themselves, not only
 * through CompanyFilter (docs/architecture-invariants.md §3's defense in depth). Run with
 * the filter off, the only way to tell the two apart.
 */
class CustomTemplateRepositoryTest extends ApiTestCase
{
    use CleansUpCompanies;

    /** Children first; see CleansUpCompanies. */
    private const COMPANY_TABLES = ['custom_template_versions', 'custom_templates', 'users'];

    public function testLookupsAreScopedWithoutTheCompanyFilter(): void
    {
        static::createClient();
        $entityManager = $this->entityManager();
        if ($entityManager->getFilters()->isEnabled(CompanyFilter::NAME)) {
            $entityManager->getFilters()->disable(CompanyFilter::NAME);
        }
        $adminA = $this->admin('A');
        $adminB = $this->admin('B');
        [$firstA, $firstAVersion] = $this->template($adminA, 'First A');
        [$secondA, $secondAVersion] = $this->template($adminA, 'Second A');
        [$templateB, $versionB] = $this->template($adminB, 'B');
        $entityManager->flush();

        $templates = self::getContainer()->get(CustomTemplateRepository::class);
        \assert($templates instanceof CustomTemplateRepository);
        $versions = self::getContainer()->get(CustomTemplateVersionRepository::class);
        \assert($versions instanceof CustomTemplateVersionRepository);

        self::assertSame(2, $templates->countForCompany($adminA->getCompany()));
        self::assertSame($firstA, $templates->findOneForCompany($firstA->getId(), $adminA->getCompany()));
        self::assertNull($templates->findOneForCompany($templateB->getId(), $adminA->getCompany()));

        self::assertSame($versionB, $versions->findOneForCompany($versionB->getId(), $adminB->getCompany()));
        self::assertNull($versions->findOneForCompany($versionB->getId(), $adminA->getCompany()));
        // Both of company A's templates are at version 1: the template decides.
        self::assertSame($secondAVersion, $versions->findCurrent($secondA));
        self::assertSame($firstAVersion, $versions->findCurrent($firstA));

        // Edited in the same second: the newer template first (ids are time-ordered).
        $entityManager->getConnection()->executeStatement('UPDATE custom_templates SET updatedAt = ? WHERE company_id = ?', ['2026-09-01 12:00:00', $adminA->getCompany()->getId()]);
        self::assertSame(['Second A', 'First A'], array_column($versions->findActiveSummariesForCompany($adminA->getCompany()), 'name'));
        self::assertSame(['Second A', 'First A'], array_map(static fn (CustomTemplateVersion $version) => $version->getName(), $versions->findCurrentForCompany($adminA->getCompany())));

        // The picker's list: this company's active templates only.
        $secondA->setArchived(true);
        $entityManager->flush();
        self::assertSame(
            [['id' => $firstA->getId(), 'name' => 'First A', 'description' => '']],
            $versions->findActiveSummariesForCompany($adminA->getCompany()),
        );
        self::assertSame(2, \count($versions->findCurrentForCompany($adminA->getCompany())));

        // The bump is scoped to the requester's company, not the template's own.
        self::assertFalse($templates->bumpVersionIfCurrent($firstA, 1, $adminB->getCompany()));
        self::assertTrue($templates->bumpVersionIfCurrent($firstA, 1, $adminA->getCompany()));
    }

    private function admin(string $label): User
    {
        $company = $this->makeCompany("Repository {$label}", flush: false);
        $admin = new User($this->uniqueEmail("repository-{$label}"), 'hash', 'pub', 'enc', $company);
        $this->entityManager()->persist($admin);

        return $admin;
    }

    /**
     * @return array{CustomTemplate, CustomTemplateVersion}
     */
    private function template(User $admin, string $name): array
    {
        $template = new CustomTemplate($admin);
        $version = new CustomTemplateVersion($template, 1, $name, '', '{}', $admin);
        $this->entityManager()->persist($template);
        $this->entityManager()->persist($version);

        return [$template, $version];
    }
}
