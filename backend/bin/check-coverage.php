<?php

/**
 * Fails (exit 1) if a Clover coverage report's overall statement coverage
 * is below a threshold. PHPUnit itself has no native flag for this (only
 * report-generation flags exist) — this is the boring, dependency-free
 * substitute, same "small custom script over a new tool" precedent as
 * frontend/scripts/inject-sri.mjs.
 *
 * Usage: php bin/check-coverage.php <path-to-clover.xml> <min-percent>
 */
[, $cloverPath, $minPercentArg] = $argv + [null, null, null];

if (null === $cloverPath || null === $minPercentArg) {
    fwrite(STDERR, "Usage: php bin/check-coverage.php <clover.xml> <min-percent>\n");
    exit(1);
}

$minPercent = (float) $minPercentArg;

$xml = simplexml_load_file($cloverPath);
if (false === $xml) {
    fwrite(STDERR, "Could not parse Clover report at {$cloverPath}\n");
    exit(1);
}

// The project-level summary <metrics> is the only one carrying a "files"
// attribute (per-file/per-class <metrics> elements don't) — every other
// <metrics> in the document is scoped to one file or one class.
$projectMetrics = null;
foreach ($xml->xpath('//metrics[@files]') as $metrics) {
    $projectMetrics = $metrics;
}

if (null === $projectMetrics) {
    fwrite(STDERR, "Could not find project-level <metrics> in {$cloverPath}\n");
    exit(1);
}

$statements = (int) $projectMetrics['statements'];
$coveredStatements = (int) $projectMetrics['coveredstatements'];

if (0 === $statements) {
    fwrite(STDERR, "Clover report has zero statements — nothing was measured\n");
    exit(1);
}

$actualPercent = $coveredStatements / $statements * 100;

printf("Line coverage: %.2f%% (%d/%d), threshold: %.2f%%\n", $actualPercent, $coveredStatements, $statements, $minPercent);

if ($actualPercent < $minPercent) {
    fwrite(STDERR, "Coverage regression: below the required threshold.\n");
    exit(1);
}

exit(0);
