<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CustomTemplateVersionRepository;
use App\Security\AuthSession;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The company template library for any company member (GitHub issue #142, #133 §6):
 * the picker's list of active templates, and any version by id, which an existing
 * anketa needs to keep rendering even after its template is archived. Scoped to the
 * member's own company by every lookup, on top of CompanyFilter.
 */
class TemplateController
{
    public function __construct(
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
        private readonly CustomTemplateVersionRepository $versionRepository,
    ) {
    }

    /**
     * Active templates only, as their current version. No definitions and no version
     * ids: the picker doesn't need them, and the client never pins a version (the
     * server resolves a template to its current version when an anketa is created).
     */
    #[Route('/api/templates', name: 'templates_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authSession->closeForReading($request);

        return new JsonResponse($this->versionRepository->findActiveSummariesForCompany($user->getCompany()));
    }

    /**
     * Any version of the company's templates, archived ones included. No special cache
     * header: the session listener would override it, and company text shouldn't stay
     * in the browser cache after logout (#133 §6).
     */
    #[Route('/api/template-versions/{id}', name: 'template_versions_get', methods: ['GET'])]
    public function getVersion(string $id, Request $request): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->authSession->closeForReading($request);

        $version = $this->versionRepository->findOneForCompany($id, $user->getCompany())
            ?? throw new NotFoundHttpException($this->translator->trans('errors.template_not_found'));

        return new JsonResponse([
            'name' => $version->getName(),
            'definition' => $version->getDecodedDefinition(),
        ]);
    }

    private function requireUser(Request $request): User
    {
        return $this->authSession->getCurrentUser($request)
            ?? throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
    }
}
