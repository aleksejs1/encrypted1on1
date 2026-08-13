<?php

declare(strict_types=1);

use PhpCsFixer\Finder;
use PhpCsFixer\Config;

// Style-only, non-risky (@Symfony — no :risky variant, no individually-risky rules like
// declare_strict_types, which no file in this codebase currently uses and which changes
// runtime scalar-coercion behavior, not just formatting). Scoped to the same real,
// hand-written code PHPStan already analyses (phpstan.neon.dist / phpstan.tests.neon.dist)
// — migrations/ and migrations-mysql/ are Doctrine-generated DDL, not hand-authored, and
// bin/console is unmodified Symfony boilerplate with no .php extension.
$finder = (new Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/bin']);

return (new Config())
    ->setRules(['@Symfony' => true])
    ->setFinder($finder);
