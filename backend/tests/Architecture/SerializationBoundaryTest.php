<?php

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\ApiResource;
use App\Entity\ActivationToken;
use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Reflection-based checks that directly encode CLAUDE.md's non-negotiable
 * constraints — the server must never be able to expose plaintext/secret
 * material through the API — rather than generic dependency-layering rules
 * (Deptrac and similar), which wouldn't catch anything real at this app's
 * size and can't express a data-exposure concern like this one anyway.
 */
class SerializationBoundaryTest extends TestCase
{
    public function testAuthHashCarriesNoSerializationGroup(): void
    {
        self::assertPropertyHasNoGroupsAttribute(User::class, 'authHash');
    }

    public function testEncryptedPrivateKeyCarriesNoSerializationGroup(): void
    {
        self::assertPropertyHasNoGroupsAttribute(User::class, 'encryptedPrivateKey');
    }

    /** @return array<string, array{string}> */
    public static function ciphertextBearingAnketaProperties(): array
    {
        return [
            'employeeSealedKey' => ['employeeSealedKey'],
            'managerSealedKey' => ['managerSealedKey'],
            'employeeBlob' => ['employeeBlob'],
            'managerBlob' => ['managerBlob'],
            'commentsBlob' => ['commentsBlob'],
            'outcomesBlob' => ['outcomesBlob'],
            'goalCheckpointsBlob' => ['goalCheckpointsBlob'],
        ];
    }

    #[DataProvider('ciphertextBearingAnketaProperties')]
    public function testAnketaCiphertextPropertyCarriesNoSerializationGroup(string $property): void
    {
        self::assertPropertyHasNoGroupsAttribute(Anketa::class, $property);
    }

    public function testOnlyUserIsRegisteredAsAnApiPlatformResource(): void
    {
        self::assertTrue(self::hasApiResourceAttribute(User::class), 'User is expected to be the one ApiResource in this app.');
        self::assertFalse(self::hasApiResourceAttribute(Anketa::class), 'Anketa holds encrypted blobs — it must stay a plain controller, not generic API Platform CRUD.');
        self::assertFalse(self::hasApiResourceAttribute(Goal::class));
        self::assertFalse(self::hasApiResourceAttribute(ActivationToken::class));
    }

    /** @param class-string $class */
    private static function assertPropertyHasNoGroupsAttribute(string $class, string $property): void
    {
        $reflectionProperty = new \ReflectionProperty($class, $property);
        $groupsAttributes = $reflectionProperty->getAttributes(Groups::class);

        self::assertSame(
            [],
            $groupsAttributes,
            sprintf('%s::$%s must never carry a Groups attribute — it holds secret/ciphertext material that must never reach the API.', $class, $property),
        );
    }

    /** @param class-string $class */
    private static function hasApiResourceAttribute(string $class): bool
    {
        $reflectionClass = new \ReflectionClass($class);

        return [] !== $reflectionClass->getAttributes(ApiResource::class);
    }
}
