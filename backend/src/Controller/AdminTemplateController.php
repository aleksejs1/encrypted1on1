<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use App\Entity\User;
use App\Http\RateLimitResponse;
use App\Http\TemplateRequestBody;
use App\Repository\CustomTemplateRepository;
use App\Repository\CustomTemplateVersionRepository;
use App\Security\AuthSession;
use App\Security\RequiresCompanyAdmin;
use App\Template\TemplateDefinitionValidator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The company template library, for company admins (GitHub issue #142, part C2 of the
 * design in #133 §6). Every save appends an immutable CustomTemplateVersion; templates
 * are archived, never deleted. Scoped to the admin's own company by every lookup, on
 * top of CompanyFilter.
 *
 * The 409 and 422 answers are returned, not thrown: they carry a machine-readable
 * `code` the editor branches on (and a 409 conflict the current row), which a thrown
 * HttpException can't, and JsonExceptionListener would turn a thrown 422 into a 400.
 */
class AdminTemplateController
{
    use RequiresCompanyAdmin;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
        private readonly CustomTemplateRepository $templateRepository,
        private readonly CustomTemplateVersionRepository $versionRepository,
        private readonly TemplateDefinitionValidator $definitionValidator,
        private readonly TemplateRequestBody $requestBody,
        #[Autowire(service: 'limiter.template_save')]
        private readonly RateLimiterFactory $saveLimiter,
    ) {
    }

    /** Every template, archived ones included, each with its current version's content. */
    #[Route('/api/admin/templates', name: 'admin_templates_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $this->authSession->closeForReading($request);

        return new JsonResponse(array_map(
            fn (CustomTemplateVersion $version) => $this->row($version),
            $this->versionRepository->findCurrentForCompany($admin->getCompany()),
        ));
    }

    /**
     * Create and update read their body through TemplateRequestBody rather than a
     * #[MapRequestPayload] DTO; see that class for why.
     */
    #[Route('/api/admin/templates', name: 'admin_templates_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $body = $this->requestBody->decode($request);
        if ($body instanceof JsonResponse) {
            return $body;
        }
        $definition = $this->requestBody->textViolations($body, descriptionRequired: false) ?? $this->requestBody->validDefinition($body);
        if ($definition instanceof JsonResponse) {
            return $definition;
        }

        // Count, then insert: two concurrent creates can overshoot the cap by one or
        // two, which is accepted (#133 §6); the cap only has to bound storage.
        if ($this->templateRepository->countForCompany($admin->getCompany()) >= CustomTemplate::MAX_PER_COMPANY) {
            return new JsonResponse([
                'error' => $this->translator->trans('errors.template_limit', ['%max%' => CustomTemplate::MAX_PER_COMPANY]),
                'code' => 'template_limit',
            ], 422);
        }
        $rateLimited = $this->rateLimited($admin);
        if (null !== $rateLimited) {
            return $rateLimited;
        }

        $template = new CustomTemplate($admin);
        $this->entityManager->persist($template);
        $this->entityManager->persist(new CustomTemplateVersion(
            $template,
            1,
            TemplateRequestBody::text($body, 'name'),
            TemplateRequestBody::text($body, 'description'),
            $this->definitionValidator->canonicalJson($definition),
            $admin,
        ));
        $this->entityManager->flush();

        return new JsonResponse(['id' => $template->getId(), 'version' => 1], 201);
    }

    /**
     * Appends a version, if the editor's `expectedVersion` is still the current one.
     * The template's own state is checked before the rest of the body (#133 §8.1:
     * archived, then the version), so the editor gets the answer it branches on
     * whatever else is wrong.
     * A save that changes nothing appends nothing (#133 §6): versions are never
     * deleted, so storage has to stay bounded.
     */
    #[Route('/api/admin/templates/{id}', name: 'admin_templates_update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $loaded = $this->loadForWrite($id, $request);
        if ($loaded instanceof JsonResponse) {
            return $loaded;
        }
        [$admin, $template, $body] = $loaded;
        $stateProblem = $this->stateProblem($template, $body);
        if (null !== $stateProblem) {
            return $stateProblem;
        }
        $expectedVersion = $template->getCurrentVersion();
        $definition = $this->requestBody->textViolations($body, descriptionRequired: true) ?? $this->requestBody->validDefinition($body);
        if ($definition instanceof JsonResponse) {
            return $definition;
        }

        $name = TemplateRequestBody::text($body, 'name');
        $description = TemplateRequestBody::text($body, 'description');
        $canonical = $this->definitionValidator->canonicalJson($definition);
        $current = $this->versionRepository->findCurrent($template);
        if (null !== $current && $current->getName() === $name && $current->getDescription() === $description && $current->getDefinition() === $canonical) {
            return $this->unchanged($template, $expectedVersion);
        }
        $rateLimited = $this->rateLimited($admin);
        if (null !== $rateLimited) {
            return $rateLimited;
        }

        return $this->appendVersion($template, $expectedVersion, $name, $description, $canonical, $admin);
    }

    /**
     * Why update() can't save onto $template whatever the rest of the body says, or
     * null: it's archived (409), the body has no usable `expectedVersion` (400), or
     * that version isn't the current one any more (409).
     */
    private function stateProblem(CustomTemplate $template, \stdClass $body): ?JsonResponse
    {
        if ($template->isArchived()) {
            return $this->archivedConflict();
        }
        $expectedVersion = $body->expectedVersion ?? null;
        if (!\is_int($expectedVersion) || $expectedVersion < 1) {
            return $this->requestBody->violations([['expectedVersion', $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'expectedVersion'])]]);
        }

        return $expectedVersion === $template->getCurrentVersion() ? null : $this->versionConflict($template);
    }

    /**
     * The answer to a save that changes nothing, after a second look at the template:
     * another save or an archive may have landed since findTemplate() loaded it, and a
     * stale no-op save must still get its 409 (#133 §6).
     */
    private function unchanged(CustomTemplate $template, int $expectedVersion): JsonResponse
    {
        $this->entityManager->refresh($template);
        if ($template->isArchived()) {
            return $this->archivedConflict();
        }
        if ($expectedVersion !== $template->getCurrentVersion()) {
            return $this->versionConflict($template);
        }

        return new JsonResponse(['version' => $expectedVersion]);
    }

    /**
     * The write half of update(): moves the template to the next version and inserts
     * that version, in one transaction, or answers with the conflict that stopped it.
     */
    private function appendVersion(CustomTemplate $template, int $expectedVersion, string $name, string $description, string $canonical, User $admin): JsonResponse
    {
        try {
            // Returns false (never throws) when the bump matched nothing, so the
            // transaction closes normally and the EntityManager stays usable.
            $bumped = $this->entityManager->wrapInTransaction(function () use ($template, $expectedVersion, $name, $description, $canonical, $admin): bool {
                if (!$this->templateRepository->bumpVersionIfCurrent($template, $expectedVersion, $admin->getCompany())) {
                    return false;
                }
                $this->entityManager->persist(new CustomTemplateVersion($template, $expectedVersion + 1, $name, $description, $canonical, $admin));

                return true;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // A backstop only: the conditional UPDATE already lets one save through
            // per version, so getting here means that guarantee broke: reported to
            // Sentry (a no-op without SENTRY_DSN), as in AnketaController's identical
            // catch. The EntityManager is closed now, so no `current` row.
            \Sentry\captureException($exception);

            return new JsonResponse([
                'error' => $this->translator->trans('errors.template_version_conflict'),
                'code' => 'version_conflict',
            ], 409);
        }

        if (!$bumped) {
            // Someone else saved or archived it after update()'s checks.
            $this->entityManager->refresh($template);

            return $template->isArchived() ? $this->archivedConflict() : $this->versionConflict($template);
        }

        return new JsonResponse(['version' => $expectedVersion + 1]);
    }

    /**
     * Archiving hides a template from every picker; existing anketas keep rendering
     * their version (#133 §6). Doesn't touch `updatedAt`, which means "edited".
     */
    #[Route('/api/admin/templates/{id}/archived', name: 'admin_templates_set_archived', methods: ['PUT'])]
    public function setArchived(string $id, Request $request): JsonResponse
    {
        $loaded = $this->loadForWrite($id, $request);
        if ($loaded instanceof JsonResponse) {
            return $loaded;
        }
        [, $template, $body] = $loaded;
        $archived = $body->archived ?? null;
        if (!\is_bool($archived)) {
            return $this->requestBody->violations([['archived', $this->translator->trans('errors.missing_or_invalid_field', ['%field%' => 'archived'])]]);
        }

        $template->setArchived($archived);
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => $template->getId(),
            'archivedAt' => $template->getArchivedAt()?->format(\DATE_ATOM),
        ]);
    }

    /**
     * The 429 to return if $admin is over the save limit, else null (one save used).
     * Called only right before a write: the limit bounds the versions an admin can
     * append (#133 §6), so a rejected or unchanged save doesn't count against it
     * (TemplateRequestBody::MAX_BODY_BYTES bounds what those cost instead), and an
     * admin at the template cap isn't locked out of editing by their own failed
     * attempts. A save that then loses a race to another admin's (a 409 from
     * appendVersion()) has used its token: a token can't be given back.
     */
    private function rateLimited(User $admin): ?JsonResponse
    {
        $limit = $this->saveLimiter->create($admin->getId())->consume();

        return $limit->isAccepted() ? null : RateLimitResponse::create($limit, $this->translator);
    }

    /**
     * What update() and setArchived() start with, in this order: the admin gate (so a
     * non-admin gets 403 whatever the body), the template (404), then the body.
     *
     * @return array{User, CustomTemplate, \stdClass}|JsonResponse
     */
    private function loadForWrite(string $id, Request $request): array|JsonResponse
    {
        $admin = $this->requireAdmin($request);
        $template = $this->findTemplate($id, $admin->getCompany());
        $body = $this->requestBody->decode($request);

        return $body instanceof JsonResponse ? $body : [$admin, $template, $body];
    }

    private function findTemplate(string $id, Company $company): CustomTemplate
    {
        return $this->templateRepository->findOneForCompany($id, $company)
            ?? throw new NotFoundHttpException($this->translator->trans('errors.template_not_found'));
    }

    private function archivedConflict(): JsonResponse
    {
        return new JsonResponse([
            'error' => $this->translator->trans('errors.template_archived'),
            'code' => 'template_archived',
        ], 409);
    }

    private function versionConflict(CustomTemplate $template): JsonResponse
    {
        $current = $this->versionRepository->findCurrent($template);

        return new JsonResponse([
            'error' => $this->translator->trans('errors.template_version_conflict'),
            'code' => 'version_conflict',
            'current' => null === $current ? null : $this->row($current),
        ], 409);
    }

    /**
     * @return array{id: string, currentVersion: int, archivedAt: string|null, updatedAt: string,
     *     name: string, description: string, definition: mixed}
     */
    private function row(CustomTemplateVersion $version): array
    {
        $template = $version->getTemplate();

        return [
            'id' => $template->getId(),
            'currentVersion' => $template->getCurrentVersion(),
            'archivedAt' => $template->getArchivedAt()?->format(\DATE_ATOM),
            'updatedAt' => $template->getUpdatedAt()->format(\DATE_ATOM),
            'name' => $version->getName(),
            'description' => $version->getDescription(),
            'definition' => $version->getDecodedDefinition(),
        ];
    }
}
