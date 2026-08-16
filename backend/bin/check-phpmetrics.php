<?php

/**
 * Fails (exit 1) on any cyclomatic-complexity/method-length violation from a
 * PhpMetrics --report-violations XML that isn't on the explicit allowlist
 * below. PhpMetrics has no native "fail the build" flag (it only ever writes
 * report files) — this is the boring, dependency-free substitute, same
 * "small custom script over a new tool" precedent as bin/check-coverage.php.
 *
 * Only three of PhpMetrics' built-in violation rules are in scope — the ones
 * that are actually about complexity/length, matching this project's own
 * private/todo.md item. "Probably bugged" (a Halstead bug-probability
 * estimate) and "Stable Abstractions Principle" (package coupling) are
 * different, unrelated concerns and are ignored here, not gated on.
 *
 * The allowlist holds today's real, already-triaged findings — each one
 * read against the actual source and judged to be legitimate real
 * complexity (many distinct real endpoints/fields), not a code smell, before
 * being added here. A *new* violation (any class/rule pair not listed) fails
 * the build; an already-allowlisted one is expected and passes silently.
 * This is deliberately not a numeric threshold override (PhpMetrics'
 * built-in violation rules don't expose one) — it's a specific, named
 * exception per specific, already-understood finding.
 *
 * Usage: php bin/check-phpmetrics.php <path-to-violations.xml>
 */
const IN_SCOPE_RULES = ['Too complex class code', 'Too complex method code', 'Too long'];

// class => [rule => reason]
const ALLOWED = [
    'App\\Controller\\AnketaController' => [
        'Too complex class code' => 'One controller per resource area with real, distinct per-endpoint logic (ADR 2, docs/adr/0002-hand-composed-symfony.md) — many real routes in one file, not tangled logic.',
        'Too complex method code' => "archive()'s real cyclomatic complexity (26) comes from several genuine, linearly-sequenced concerns (input validation, blocked-participant eligibility, conditional carry-forward, notification) — read directly against the source before allowlisting; splitting it would fragment one coherent request handler across several methods for no real readability gain.",
        'Too long' => 'Direct consequence of the same one-controller-per-resource-area design (many real endpoints in one file), not accidental bloat.',
    ],
    'App\\Entity\\Anketa' => [
        'Too long' => "Many small, simple accessor/mutator methods (ccnMethodMax=3, genuinely low) for the entity's real number of distinct fields (per-side blobs, sealed keys, comments, outcomes, checkpoints) — length reflects real domain shape, not tangled logic.",
    ],
    'App\\Controller\\InviteController' => [
        'Too complex method code' => 'create() is one linear sequence of independent guard clauses (auth, admin-mode gate, rate limit, email validation, domain restriction, existing-user check) before its real side effects — the same stacked-guard-clause shape used throughout this app\'s controllers, not tangled branching.',
    ],
    'App\\Controller\\SignupController' => [
        'Too complex method code' => "signup()'s complexity (11, same level as InviteController::create() above) grew by exactly one guard clause — Phase D's seat-limit check (private/cloud-service-plan.md, not tracked in git) — added to the same already-linear stacked-guard-clause sequence (CSRF, rate limit, cloud-mode gate, registration-mode gate, email validation, domain restriction, existing-user check), not a new branch that muddies the logic.",
    ],
    'App\\Controller\\AuthController' => [
        'Too complex method code' => "login()'s complexity (11, same level as InviteController::create() above) grew by exactly one guard clause — Phase D's company-suspension check (private/cloud-service-plan.md, not tracked in git), added immediately after the existing isBlocked() guard it mirrors — the same stacked-guard-clause shape, not tangled branching.",
    ],
];

[, $violationsPath] = $argv + [null, null];

if (null === $violationsPath) {
    fwrite(STDERR, "Usage: php bin/check-phpmetrics.php <violations.xml>\n");
    exit(1);
}

$xml = simplexml_load_file($violationsPath);
if (false === $xml) {
    fwrite(STDERR, "Could not parse PhpMetrics violations report at {$violationsPath}\n");
    exit(1);
}

$unexpected = [];
$allowedCount = 0;

foreach ($xml->file as $file) {
    $class = (string) $file['name'];
    foreach ($file->violation as $violation) {
        $rule = (string) $violation['rule'];
        if (!\in_array($rule, IN_SCOPE_RULES, true)) {
            continue;
        }
        if (isset(ALLOWED[$class][$rule])) {
            ++$allowedCount;
            continue;
        }
        $unexpected[] = "{$class}: {$rule}";
    }
}

printf("PhpMetrics complexity/length check: %d already-allowlisted finding(s), %d unexpected.\n", $allowedCount, \count($unexpected));

if ([] !== $unexpected) {
    fwrite(STDERR, "New complexity/length violation(s) not on the allowlist:\n");
    foreach ($unexpected as $line) {
        fwrite(STDERR, "  - {$line}\n");
    }
    fwrite(STDERR, "Either fix the underlying complexity, or add a specific, justified entry to ALLOWED in bin/check-phpmetrics.php.\n");
    exit(1);
}

exit(0);
