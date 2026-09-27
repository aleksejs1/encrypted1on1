<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/** GitHub issue #142: CustomTemplate and CustomTemplateVersion's own rules. */
class CustomTemplateTest extends TestCase
{
    private function user(Company $company): User
    {
        return new User('admin@example.com', 'hash', 'pub', 'enc', $company);
    }

    public function testANewTemplateStartsAtVersionOneInItsCreatorsCompany(): void
    {
        $company = new Company('Acme');
        $template = new CustomTemplate($this->user($company));

        self::assertSame(1, $template->getCurrentVersion());
        self::assertSame($company, $template->getCompany());
        self::assertFalse($template->isArchived());
        self::assertNull($template->getArchivedAt());
    }

    public function testArchivingKeepsTheFirstArchiveTimeAndRestoringClearsIt(): void
    {
        $template = new CustomTemplate($this->user(new Company('Acme')));

        $template->setArchived(true);
        $archivedAt = $template->getArchivedAt();
        self::assertNotNull($archivedAt);
        $template->setArchived(true);
        self::assertSame($archivedAt, $template->getArchivedAt());

        $template->setArchived(false);
        self::assertFalse($template->isArchived());
        self::assertNull($template->getArchivedAt());
    }

    public function testAVersionTakesItsTemplatesCompany(): void
    {
        $company = new Company('Acme');
        $admin = $this->user($company);
        $template = new CustomTemplate($admin);

        $version = new CustomTemplateVersion($template, 2, 'Name', 'Description', '{}', $admin);

        self::assertSame($company, $version->getCompany());
        self::assertSame($template, $version->getTemplate());
        self::assertSame(2, $version->getVersion());
        self::assertSame('Name', $version->getName());
        self::assertSame('Description', $version->getDescription());
        self::assertSame('{}', $version->getDefinition());
    }

    public function testAVersionCantBeWrittenByAnotherCompanysUser(): void
    {
        $template = new CustomTemplate($this->user(new Company('Acme')));

        $this->expectException(\InvalidArgumentException::class);

        new CustomTemplateVersion($template, 2, 'Name', '', '{}', $this->user(new Company('Other')));
    }
}
