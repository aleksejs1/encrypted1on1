<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;

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
 */
class ExcludeDeletedUsersExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
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
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere(sprintf('%s.deletedAt IS NULL', $rootAlias));
        $queryBuilder->andWhere(sprintf('%s.isDemo = false', $rootAlias));
    }
}
