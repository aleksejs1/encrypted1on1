<?php

namespace App\Tests\Unit\Doctrine;

use App\Doctrine\CompanyFilter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

class CompanyFilterTest extends TestCase
{
    public function testFilterAppendsConstraintForEntitiesWithCompanyAssociation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('quote')
            ->with('comp-uuid-123')
            ->willReturn("'comp-uuid-123'");

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        $filter = new CompanyFilter($em);
        $filter->setParameter(CompanyFilter::PARAMETER_NAME, 'comp-uuid-123');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('hasAssociation')
            ->with('company')
            ->willReturn(true);

        $sql = $filter->addFilterConstraint($metadata, 't0');
        self::assertSame("t0.company_id = 'comp-uuid-123'", $sql);
    }

    public function testFilterReturnsEmptyStringForEntitiesWithoutCompanyAssociation(): void
    {
        $em = self::createStub(EntityManagerInterface::class);

        $filter = new CompanyFilter($em);
        $filter->setParameter(CompanyFilter::PARAMETER_NAME, 'comp-uuid-123');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('hasAssociation')
            ->with('company')
            ->willReturn(false);

        $sql = $filter->addFilterConstraint($metadata, 't0');
        self::assertSame('', $sql);
    }

    public function testFilterThrowsIfParameterNotSet(): void
    {
        $em = self::createStub(EntityManagerInterface::class);

        $filter = new CompanyFilter($em);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects(self::once())
            ->method('hasAssociation')
            ->with('company')
            ->willReturn(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Parameter \'company_id\' does not exist/');

        $filter->addFilterConstraint($metadata, 't0');
    }
}
