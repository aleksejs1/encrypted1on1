<?php

namespace App\Org;

/**
 * Why a row of an org structure import (GitHub issue #271) is not applied. The value
 * is the reason code the API answers with; the admin panel translates it.
 */
enum OrgImportProblem: string
{
    case EmployeeNotFound = 'employee_not_found';
    case ManagerNotFound = 'manager_not_found';
    /** The address matches more than one account when case is ignored. */
    case AmbiguousEmail = 'ambiguous_email';
    case ManagerUnavailable = 'manager_unavailable';
    case OwnManager = 'own_manager';
    /** The same person in two rows with different managers. */
    case ConflictingRows = 'conflicting_rows';
    case Cycle = 'cycle';

    /**
     * A warning is a row about someone the company doesn't have (yet): expected when a
     * file comes from an HR system. An error is a row that contradicts itself or
     * another row. Both are skipped and the rest applied. Blocking stops the whole
     * import: see OrgStructure::violations() for why a cycle can't be skipped by row.
     */
    public function severity(): string
    {
        return match ($this) {
            self::EmployeeNotFound, self::ManagerNotFound, self::AmbiguousEmail, self::ManagerUnavailable => 'warning',
            self::OwnManager, self::ConflictingRows => 'error',
            self::Cycle => 'blocking',
        };
    }
}
