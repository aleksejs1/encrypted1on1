<?php

namespace App\Company;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Phase A of private/cloud-service-plan.md (not tracked in git) guarantees exactly one
 * `Company` row exists for any deployment — the migration that introduced the table seeds
 * it unconditionally, for both a brand-new database and an existing one being backfilled.
 * This resolves it for the handful of call sites that have no authenticated "current
 * user" to draw a company from directly (an unauthenticated signup form, the CLI
 * bootstrap) — everywhere else (InviteController, AnketaController, ...) should read
 * `$user->getCompany()` directly instead of using this.
 *
 * A later phase that lets a second company actually be created (the plan's Phase B,
 * self-service company signup) must revisit every call site of this class — the
 * "there's only ever one" assumption stops holding the moment that ships.
 */
class SingleCompanyProvider
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function get(): Company
    {
        $company = $this->entityManager->getRepository(Company::class)->findOneBy([]);
        if (null === $company) {
            // Should be unreachable outside a database that hasn't run its migrations —
            // the seeding migration guarantees this row exists before any app code runs.
            throw new \LogicException('No Company row exists — has the database been migrated?');
        }

        return $company;
    }
}
