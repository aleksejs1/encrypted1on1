<?php

declare(strict_types=1);

namespace App\Tests\PHPStan;

use App\PHPStan\EnforceEncryptedEntityFieldsRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<EnforceEncryptedEntityFieldsRule>
 */
final class EnforceEncryptedEntityFieldsRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new EnforceEncryptedEntityFieldsRule();
    }

    public function testRule(): void
    {
        $message = 'is a plaintext-shaped column (type "%s") with no ciphertext/identifier name and no '
            .'#[AllowPlaintext(reason: ...)]. Per CLAUDE.md, assume encrypted by default — see '
            .'docs/architecture-invariants.md.';

        $this->analyse([__DIR__.'/Data/enforce-encrypted-entity-fields.php'], [
            [
                sprintf('Property $FixtureEntity::$authHash %s', sprintf($message, 'string')),
                30,
            ],
            [
                sprintf('Property $FixtureEntity::$companyId %s', sprintf($message, 'string')),
                33,
            ],
            [
                sprintf('Property $FixtureEntity::$goalUuid %s', sprintf($message, 'string')),
                36,
            ],
            [
                sprintf('Property $FixtureEntity::$nationalId %s', sprintf($message, 'string')),
                40,
            ],
            [
                sprintf('Property $FixtureEntity::$unannotatedContent %s', sprintf($message, 'string')),
                68,
            ],
            [
                sprintf('Property $FixtureEntity::$description %s', sprintf($message, 'text')),
                71,
            ],
            [
                sprintf('Property $FixtureEntity::$unannotatedTypesConstant %s', sprintf($message, 'string')),
                75,
            ],
            [
                sprintf('Property $FixtureEntity::$unannotatedInferredString %s', sprintf($message, 'string')),
                79,
            ],
            [
                sprintf('Property $FixtureEntity::$unannotatedCustomType %s', sprintf($message, 'string')),
                83,
            ],
            [
                sprintf('Property $FixtureEntity::$unannotatedUnionType %s', sprintf($message, 'string')),
                91,
            ],
            [
                sprintf('Property $FixtureEntity::$unannotatedCustomStringLiteralType %s', sprintf($message, 'string')),
                100,
            ],
            [
                sprintf('Property $FixtureEntityWithPromotedProperties::$unannotatedPromotedContent %s', sprintf($message, 'string')),
                119,
            ],
        ]);
    }
}
