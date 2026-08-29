<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;

/**
 * Resolves an #[ORM\Column]'s Doctrine type — the sole reason
 * EnforceEncryptedEntityFieldsRule needs this much logic just to answer "is this
 * column's type content-shaped." Three forms are recognized: an explicit `type: '...'`
 * string literal that names one of Doctrine's own built-in types, an explicit
 * `type: Types::STRING`-style class-constant reference (Doctrine's own recommended
 * modern style, resolved against the real Types class — any of its constants, not
 * just STRING/TEXT), or — if `type:` is omitted, or given as some other expression
 * this class can't vouch for (e.g. a custom Doctrine type's own class constant, or an
 * unrecognized string that might be a custom type's registered name) — Doctrine's own
 * inference from the property's native PHP type (only 'string' is inferable this way;
 * 'text' always requires a recognized explicit `type:`). Falling back rather than
 * trusting an unverified `type:` expression to mean "not content-shaped" keeps the
 * enforcing rule from silently going blind on exactly the columns it can't fully
 * resolve.
 */
final class DoctrineColumnTypeResolver
{
    private const DOCTRINE_TYPES_CLASS = 'Doctrine\DBAL\Types\Types';

    /** @var list<string>|null */
    private ?array $knownDoctrineTypeValuesCache = null;

    public function resolve(Node\Attribute $columnAttribute, Node\ComplexType|Node\Identifier|Node\Name|null $declaredType, Scope $scope): ?string
    {
        foreach ($columnAttribute->args as $arg) {
            if ('type' !== $arg->name?->toString()) {
                continue;
            }

            return $this->resolveTypeArgument($arg->value, $scope) ?? $this->inferTypeFromDeclaration($declaredType);
        }

        return $this->inferTypeFromDeclaration($declaredType);
    }

    /**
     * Resolves the literal/constant to a real Doctrine type value, or null if it isn't
     * one this method can vouch for (an unrecognized string, or a class-constant
     * reference to something other than Doctrine\DBAL\Types\Types) — the caller then
     * falls back to native-PHP-type inference rather than trusting an unverified value.
     * Reflects the real, installed Types class (doctrine/dbal is a production
     * dependency of doctrine/orm, always loaded) instead of hand-maintaining a copy of
     * its constant list, so this stays correct as Doctrine adds new types.
     */
    private function resolveTypeArgument(Node\Expr $value, Scope $scope): ?string
    {
        if ($value instanceof Node\Scalar\String_) {
            return \in_array($value->value, $this->knownDoctrineTypeValues(), true) ? $value->value : null;
        }

        if (
            $value instanceof Node\Expr\ClassConstFetch
            && $value->class instanceof Node\Name
            && $value->name instanceof Node\Identifier
            && self::DOCTRINE_TYPES_CLASS === ltrim($scope->resolveName($value->class), '\\')
        ) {
            $fqcn = self::DOCTRINE_TYPES_CLASS.'::'.$value->name->toString();
            if (!\defined($fqcn)) {
                return null;
            }

            $constantValue = \constant($fqcn);

            return \is_string($constantValue) ? $constantValue : null;
        }

        return null;
    }

    /** @return list<string> */
    private function knownDoctrineTypeValues(): array
    {
        if (null === $this->knownDoctrineTypeValuesCache) {
            // Reflects the real, always-loaded Doctrine\DBAL\Types\Types class (a
            // production dependency, not code under analysis) to read its constant list.
            $constants = class_exists(self::DOCTRINE_TYPES_CLASS)
                ? (new \ReflectionClass(self::DOCTRINE_TYPES_CLASS))->getConstants()
                : [];

            $this->knownDoctrineTypeValuesCache = array_values(array_filter(
                $constants,
                static fn (mixed $value): bool => \is_string($value),
            ));
        }

        return $this->knownDoctrineTypeValuesCache;
    }

    private function inferTypeFromDeclaration(Node\ComplexType|Node\Identifier|Node\Name|null $type): ?string
    {
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Node\Identifier) {
            return 'string' === strtolower($type->toString()) ? 'string' : null;
        }

        // A union like `string|null` (rather than the `?string` shorthand `NullableType`
        // already handled above) — Doctrine's own attribute driver still infers a plain
        // 'string' column from it as long as 'string' is one of the union's members.
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $part) {
                if ($part instanceof Node\Identifier && 'string' === strtolower($part->toString())) {
                    return 'string';
                }
            }
        }

        return null;
    }
}
