<?php

namespace App\Company;

use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Phase A of private/cloud-service-plan.md (not tracked in git) guaranteed exactly one
 * `Company` row exists for a fresh or freshly-backfilled deployment — the migration that
 * introduced the table seeds it unconditionally. This resolves it for the handful of call
 * sites that have no authenticated "current user" to draw a company from directly (an
 * unauthenticated signup form, the CLI bootstrap) — everywhere else (InviteController,
 * AnketaController, ...) should read `$user->getCompany()` directly instead of using this.
 *
 * Phase B added a real way to create a *second* company (CompanyController, gated behind
 * CLOUD_MODE) — every remaining caller of this class (the CLI bootstrap,
 * ResetDemoDataCommand, LoadTestSqliteCommand) is a self-hosted/dev/demo/load-test tool
 * that only ever makes sense against a single-company database, so get() below fails
 * loudly rather than silently guessing which company was meant the moment that stops
 * being true — a real correctness backstop, not a hypothetical one, now that
 * CompanyController exists. SignupController (the other pre-Phase-B caller) stopped using
 * this under CLOUD_MODE entirely rather than relying on the throw — see its own comments.
 */
class SingleCompanyProvider
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function get(): Company
    {
        // Limited to 2 rather than a full count() — cheap way to detect "more than one"
        // without scanning the whole table.
        $companies = $this->entityManager->getRepository(Company::class)->findBy([], null, 2);

        if ([] === $companies) {
            // Should be unreachable outside a database that hasn't run its migrations —
            // the seeding migration guarantees this row exists before any app code runs.
            throw new \LogicException('No Company row exists — has the database been migrated?');
        }

        if (\count($companies) > 1) {
            throw new \LogicException('More than one Company row exists — SingleCompanyProvider is no longer safe to use here now that self-service company creation (CLOUD_MODE) can create additional companies; this call site needs to resolve the correct company explicitly instead of assuming there is only one.');
        }

        return $companies[0];
    }
}
