<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Company;
use PHPUnit\Framework\TestCase;

class CompanyTest extends TestCase
{
    public function testConstructorDefaultsToInviteModeAndUnrestrictedDomain(): void
    {
        $company = new Company('Acme');

        self::assertSame('Acme', $company->getName());
        self::assertSame('invite', $company->getRegistrationMode());
        self::assertSame('', $company->getAllowedEmailDomain());
    }

    public function testConstructorAcceptsAnyValidRegistrationMode(): void
    {
        foreach (Company::REGISTRATION_MODES as $mode) {
            $company = new Company('Acme', $mode);
            self::assertSame($mode, $company->getRegistrationMode());
        }
    }

    public function testConstructorRejectsAnUnsupportedRegistrationMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Company('Acme', 'bogus-mode');
    }

    public function testConstructorAcceptsAnAllowedEmailDomain(): void
    {
        $company = new Company('Acme', 'domain', 'acme.example');

        self::assertSame('acme.example', $company->getAllowedEmailDomain());
    }
}
