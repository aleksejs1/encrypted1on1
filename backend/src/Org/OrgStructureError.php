<?php

namespace App\Org;

/** Why a manager can't be assigned. The value is the `errors.*` translation key. */
enum OrgStructureError: string
{
    /** The person themselves is a deleted account: it must not get a link back. */
    case PersonDeleted = 'errors.user_already_deleted';
    case OwnManager = 'errors.org_manager_is_self';
    /** Blocked, deleted, or (never reachable through the API, which answers 404 first) in another company. */
    case ManagerUnavailable = 'errors.org_manager_unavailable';
    case Cycle = 'errors.org_manager_cycle';
}
