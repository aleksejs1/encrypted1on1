<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use App\Security\AuthSession;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GET /api/users (the app's one API-Platform ApiResource, used for the counterpart
 * picker) must never list a deleted account — starting a new anketa with a dead account
 * makes no sense. Account deletion (User::delete()) is anonymization-in-place, not a
 * real row delete, so this is the one place that hides those rows again. Auto-tagged for
 * free: services.php already sets autoconfigure() on the whole App\ namespace, so
 * implementing these two interfaces is all API Platform needs to pick this up.
 *
 * Also filters out isDemo accounts (see private/demo-mode-plan.md, not tracked in git)
 * for the same underlying reason: a real prospect's counterpart typeahead shouldn't
 * surface the fixed, publicly-known demo account. Kept in this one class rather than a
 * second extension — both are the same concept ("rows that exist but shouldn't appear in
 * the public listing"), not two unrelated ones.
 *
 * The company-scoping predicate added here (private/cloud-service-plan.md, not tracked
 * in git, Phase A) is the single most important line of code in this whole app's
 * multi-tenancy story: without it, this resource would list every registered user across
 * every company sharing the database, not just the requester's own — a real cross-tenant
 * leak, not a hypothetical one, for a product whose entire pitch is that the operator
 * can't see your data, let alone another customer's. Requiring authentication here is a
 * genuine, deliberate behavior change from this endpoint's original design ("read-only,
 * unauthenticated by design" — neither isAdmin nor isBlocked was ever sensitive on its
 * own) — that reasoning stops holding once a second company can exist on the same
 * instance: an unauthenticated caller has no company to scope by, so the only safe
 * answer is to require a session, the same way every other endpoint in this app already
 * does. Self-hosted deployments (still always exactly one company) see no behavior
 * change beyond now needing to be logged in — already true in practice, since the only
 * real caller (CreateAnketa.svelte's counterpart typeahead) only ever runs on an
 * authenticated page.
 */
class ExcludeDeletedUsersExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly AuthSession $authSession,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->apply($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->apply($queryBuilder, $resourceClass);
    }

    private function apply(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (User::class !== $resourceClass) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();
        $currentUser = null !== $request ? $this->authSession->getCurrentUser($request) : null;
        if (null === $currentUser) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere(sprintf('%s.deletedAt IS NULL', $rootAlias));
        $queryBuilder->andWhere(sprintf('%s.isDemo = false', $rootAlias));
        $queryBuilder->andWhere(sprintf('%s.company = :currentUserCompany', $rootAlias));
        $queryBuilder->setParameter('currentUserCompany', $currentUser->getCompany()->getId());
    }
}
