<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Documents why an entity column intentionally holds plaintext content,
 * despite not matching the ciphertext naming convention (`*Blob`,
 * `*SealedKey`, `encrypted*`). Enforced by
 * App\PHPStan\EnforceEncryptedEntityFieldsRule — see
 * docs/architecture-invariants.md. A new plaintext-shaped column that has
 * neither a ciphertext name nor this attribute fails `composer stan`.
 *
 * Targets both TARGET_PROPERTY and TARGET_PARAMETER: the enforcing rule
 * also accepts this attribute on a constructor-promoted property, and PHP
 * fatals at reflection time (`ReflectionAttribute::newInstance()`) if an
 * attribute is used on a target its own declaration doesn't allow, even
 * though parsing it doesn't fail.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class AllowPlaintext
{
    public function __construct(
        public readonly string $reason,
    ) {
    }
}
