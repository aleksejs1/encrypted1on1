<?php

declare(strict_types=1);

namespace App\PHPStan;

use App\Entity\AllowPlaintext;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Machine-enforces CLAUDE.md's "assume encrypted" default: an #[ORM\Entity]
 * column typed 'string'/'text' (the only types that could hold arbitrary
 * content) must either be named like ciphertext/an identifier, or carry an
 * explicit #[AllowPlaintext(reason: ...)] documenting why plaintext is fine.
 * See docs/architecture-invariants.md.
 *
 * Checks both classic properties and constructor-promoted properties — this
 * codebase's entities only use the former today, but App\Entity\AllowPlaintext
 * itself is a promoted-property class, so the style is already in the repo.
 * Column-type resolution (string literal / Types:: constant / inferred from the
 * native PHP type) lives in DoctrineColumnTypeResolver, kept separate since it's
 * a genuinely distinct concern from this class's AST-traversal job.
 *
 * Known, accepted blind spot: a column declared on an `#[ORM\MappedSuperclass]`
 * parent or composed in via a trait isn't checked, since neither appears in the
 * entity class's own AST node. Not built (as of this writing, no entity in this
 * codebase uses either pattern) — see docs/architecture-invariants.md §1 for why
 * walking cross-file composition is deferred rather than spec'd speculatively.
 *
 * Also not handled: a multi-property declaration statement (`private string $a,
 * $b;`) shares one set of attributes across every property it declares, so one
 * #[AllowPlaintext] would cover all of them. This codebase's entities (and Doctrine
 * mapping generally) always declare one property per statement, so this is a
 * theoretical gap, not a real one today.
 *
 * @implements Rule<Node\Stmt\Class_>
 */
final class EnforceEncryptedEntityFieldsRule implements Rule
{
    private const ORM_ENTITY_ATTRIBUTE = 'Doctrine\ORM\Mapping\Entity';
    private const ORM_COLUMN_ATTRIBUTE = 'Doctrine\ORM\Mapping\Column';

    /** Column types that CAN hold arbitrary content — the only ones this rule applies to. */
    private const CONTENT_SHAPED_TYPES = ['string', 'text'];

    /**
     * Property-name suffixes that unambiguously mark a column as ciphertext, not content.
     * Deliberately narrow — a broader identifier-shaped suffix like `*Id`/`*Uuid`/`*Hash`
     * would also match a genuinely sensitive field (e.g. `nationalId`, `taxpayerId`),
     * silently exempting it from ever needing an #[AllowPlaintext] justification. Only the
     * exact property name `id` (every entity's own primary key) gets that kind of
     * structural pass — see looksSafeByName().
     */
    private const SAFE_NAME_SUFFIXES = ['Blob', 'SealedKey'];

    private DoctrineColumnTypeResolver $typeResolver;

    public function __construct()
    {
        $this->typeResolver = new DoctrineColumnTypeResolver();
    }

    public function getNodeType(): string
    {
        return Node\Stmt\Class_::class;
    }

    /**
     * @param Node\Stmt\Class_ $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (null === $this->findAttribute($node->attrGroups, $scope, self::ORM_ENTITY_ATTRIBUTE)) {
            return [];
        }

        $errors = [];
        foreach ($node->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $error = $this->checkColumn($node, $property->attrGroups, $prop->name->toString(), $property->getStartLine(), $property->type, $scope);
                if (null !== $error) {
                    $errors[] = $error;
                }
            }
        }

        foreach ($this->promotedParams($node) as $param) {
            /** @var Node\Expr\Variable $var */
            $var = $param->var;
            /** @var string $name */
            $name = $var->name;
            $error = $this->checkColumn($node, $param->attrGroups, $name, $param->getStartLine(), $param->type, $scope);
            if (null !== $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /** @return list<Node\Param> */
    private function promotedParams(Node\Stmt\Class_ $node): array
    {
        $constructor = $node->getMethod('__construct');
        if (null === $constructor) {
            return [];
        }

        return array_values(array_filter(
            $constructor->getParams(),
            static fn (Node\Param $param): bool => $param->isPromoted() && $param->var instanceof Node\Expr\Variable && \is_string($param->var->name),
        ));
    }

    /** @param array<Node\AttributeGroup> $attrGroups */
    private function checkColumn(Node\Stmt\Class_ $node, array $attrGroups, string $name, int $line, Node\ComplexType|Node\Identifier|Node\Name|null $declaredType, Scope $scope): ?IdentifierRuleError
    {
        $columnAttribute = $this->findAttribute($attrGroups, $scope, self::ORM_COLUMN_ATTRIBUTE);
        if (null === $columnAttribute) {
            return null; // not a mapped column (e.g. an association)
        }

        $type = $this->typeResolver->resolve($columnAttribute, $declaredType, $scope);
        if (null === $type || !\in_array($type, self::CONTENT_SHAPED_TYPES, true)) {
            return null; // can't hold arbitrary content — outside this rule's scope
        }

        if (null !== $this->findAttribute($attrGroups, $scope, AllowPlaintext::class)) {
            return null;
        }

        if ($this->looksSafeByName($name)) {
            return null;
        }

        return RuleErrorBuilder::message(sprintf(
            'Property $%s::$%s is a plaintext-shaped column (type "%s") with no ciphertext/identifier '
            .'name and no #[AllowPlaintext(reason: ...)]. Per CLAUDE.md, assume encrypted by default — '
            .'see docs/architecture-invariants.md.',
            $node->name?->toString() ?? '(anonymous)',
            $name,
            $type,
        ))
            ->identifier('e1o1.plaintextEntityField')
            ->line($line)
            ->build();
    }

    private function looksSafeByName(string $name): bool
    {
        if ('id' === $name || str_starts_with($name, 'encrypted')) {
            return true;
        }

        foreach (self::SAFE_NAME_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<Node\AttributeGroup> $attrGroups */
    private function findAttribute(array $attrGroups, Scope $scope, string $fqcn): ?Node\Attribute
    {
        $fqcn = ltrim($fqcn, '\\');
        foreach ($attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                if (ltrim($scope->resolveName($attr->name), '\\') === $fqcn) {
                    return $attr;
                }
            }
        }

        return null;
    }
}
