<?php

namespace App\Doctrine;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Multi-tenant isolation filter (GitHub issue #69).
 *
 * Automatically appends `{$targetTableAlias}.company_id = {$this->getParameter('company_id')}`
 * to queries targeting tenant-scoped entities carrying a direct `company` relation
 * (users, anketas, invite_records, activation_tokens, anketa_private_notes).
 *
 * Note on PasswordResetToken: Issue #69 listed password_reset_tokens alongside users,
 * anketas, invite_records, and activation_tokens under the assumption it carried a company_id FK;
 * checking $targetEntity->hasAssociation('company') cleanly covers all entities with a company
 * relation without failing on PasswordResetToken (which has no company column in its schema).
 */
class CompanyFilter extends SQLFilter
{
    public const string NAME = 'company_filter';
    public const string PARAMETER_NAME = 'company_id';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!$targetEntity->hasAssociation('company')) {
            return '';
        }

        return sprintf('%s.company_id = %s', $targetTableAlias, $this->getParameter(self::PARAMETER_NAME));
    }
}
