<?php

namespace App\Entity;

use App\Repository\CustomTemplateVersionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One immutable version of a CustomTemplate (GitHub issue #142, #133 §5.2): never
 * updated and never deleted, so an anketa that references it (#144) keeps rendering
 * exactly what it was created with. Name and description live here, not on the
 * template, so an anketa also keeps the name it was created with.
 *
 * Name, description and definition are plaintext on the server: company configuration
 * written by an admin, like the built-in question wording that is public in the source,
 * never anything a participant said. Maintainer decision D1 (2026-09-24), the second
 * documented exception to end-to-end encryption; see docs/encryption.md.
 *
 * `definition` is the canonical JSON TemplateDefinitionValidator::canonicalJson()
 * produces, so its byte count is exactly what the 64 KiB cap measured. `company` is
 * denormalized from the template so CompanyFilter scopes versions directly
 * (docs/architecture-invariants.md §3).
 */
#[ORM\Entity(repositoryClass: CustomTemplateVersionRepository::class)]
#[ORM\Table(name: 'custom_template_versions')]
#[ORM\UniqueConstraint(name: 'uniq_custom_template_versions_template_version', columns: ['template_id', 'version'])]
class CustomTemplateVersion
{
    public const MAX_NAME_LENGTH = 120;
    public const MAX_DESCRIPTION_LENGTH = 300;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CustomTemplate::class)]
    #[ORM\JoinColumn(nullable: false)]
    private CustomTemplate $template;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    #[ORM\Column(type: 'integer')]
    private int $version;

    #[ORM\Column(type: 'string', length: self::MAX_NAME_LENGTH)]
    #[AllowPlaintext(reason: 'A company template\'s name: configuration an admin writes for the whole company to pick from, never participant content. Maintainer decision D1 (GitHub issue #133), documented in docs/encryption.md as the second plaintext exception; the admin editor (GitHub issue #143) is to warn not to put names or reasons in it.')]
    private string $name;

    #[ORM\Column(type: 'string', length: self::MAX_DESCRIPTION_LENGTH, options: ['default' => ''])]
    #[AllowPlaintext(reason: 'A company template\'s description, same reasoning as $name (D1, GitHub issue #133).')]
    private string $description;

    #[ORM\Column(type: 'text')]
    #[AllowPlaintext(reason: 'A company template\'s question definition (question titles, field labels, option labels): company configuration like the built-in question wording that is public in the source, never answers. Same D1 decision as $name (GitHub issue #133). Canonical JSON, validated by TemplateDefinitionValidator.')]
    private string $definition;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /**
     * @param string $definition canonical JSON from TemplateDefinitionValidator::canonicalJson()
     */
    public function __construct(CustomTemplate $template, int $version, string $name, string $description, string $definition, User $createdBy)
    {
        if ($createdBy->getCompany()->getId() !== $template->getCompany()->getId()) {
            throw new \InvalidArgumentException('A template version must be written by a member of the template\'s company.');
        }
        $this->id = Uuid::v7()->toRfc4122();
        $this->template = $template;
        $this->company = $template->getCompany();
        $this->version = $version;
        $this->name = $name;
        $this->description = $description;
        $this->definition = $definition;
        $this->createdBy = $createdBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTemplate(): CustomTemplate
    {
        return $this->template;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** The canonical JSON, as stored. */
    public function getDefinition(): string
    {
        return $this->definition;
    }

    /**
     * The definition as JSON data for a response: objects as \stdClass, so an
     * encoder writes `{}` and `[]` back exactly as stored (an associative decode
     * would turn an empty object into a list).
     */
    public function getDecodedDefinition(): mixed
    {
        return json_decode($this->definition, flags: \JSON_THROW_ON_ERROR);
    }
}
