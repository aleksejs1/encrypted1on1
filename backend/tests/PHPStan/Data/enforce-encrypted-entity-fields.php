<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Data;

use App\Entity\AllowPlaintext;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A custom Doctrine type's own class constant — not Doctrine\DBAL\Types\Types. */
class CustomType
{
    public const NAME = 'custom_type';
}

#[ORM\Entity]
class FixtureEntity
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'text')]
    private string $commentsBlob;

    #[ORM\Column(type: 'text')]
    private string $employeeSealedKey;

    #[ORM\Column(type: 'string')]
    private string $authHash;

    #[ORM\Column(type: 'string', length: 36)]
    private string $companyId;

    #[ORM\Column(type: 'string', length: 36)]
    private string $goalUuid;

    /** An *Id-suffixed name doesn't get a free pass — this is genuinely sensitive content. */
    #[ORM\Column(type: 'string', length: 20)]
    private string $nationalId;

    #[ORM\Column(type: 'text')]
    private string $encryptedPrivateKey;

    #[ORM\Column(type: 'boolean')]
    private bool $isAdmin;

    #[ORM\Column(type: 'integer')]
    private int $commentsVersion;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $targetDate;

    #[ORM\Column(type: 'string', length: 255)]
    #[AllowPlaintext(reason: 'Test fixture — annotated plaintext field.')]
    private string $annotatedContent;

    #[ORM\ManyToOne(targetEntity: FixtureEntity::class)]
    private FixtureEntity $relation;

    /** Not an entity column at all. */
    private string $notMapped;

    #[ORM\Column(type: 'string', length: 255)]
    private string $unannotatedContent;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    /** `type:` given as a Types:: class-constant, not a string literal — still detected. */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $unannotatedTypesConstant;

    /** `type:` omitted entirely — Doctrine infers 'string' from the native PHP type. */
    #[ORM\Column(length: 255)]
    private string $unannotatedInferredString;

    /** `type:` given as some other class constant this rule doesn't recognize by name — falls back to the native PHP type instead of silently passing. */
    #[ORM\Column(type: CustomType::NAME, length: 255)]
    private string $unannotatedCustomType;

    /**
     * A genuine union type (php-cs-fixer would normalize `string|null` back down to the
     * `?string` shorthand — already covered above — so this uses a union PHP itself
     * can't collapse) with `type:` omitted, to exercise the UnionType-unwrapping path.
     */
    #[ORM\Column(length: 255)]
    private string|int $unannotatedUnionType;

    /**
     * `type:` given as a string literal that isn't one of Doctrine's own built-in type
     * names (a custom-registered type's name, referenced by its raw string rather than a
     * class constant) — falls back to the native PHP type instead of trusting the literal
     * as "not content-shaped" just because it isn't 'string'/'text' verbatim.
     */
    #[ORM\Column(type: 'my_custom_string_type', length: 255)]
    private string $unannotatedCustomStringLiteralType;
}

class NotAnEntity
{
    #[ORM\Column(type: 'string', length: 255)]
    private string $unannotatedContent;
}

#[ORM\Entity]
class FixtureEntityWithPromotedProperties
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 36)]
        private string $id,
        #[ORM\Column(type: 'text')]
        private string $commentsBlob,
        #[ORM\Column(type: 'string', length: 255)]
        private string $unannotatedPromotedContent,
    ) {
    }
}
