<?php

declare(strict_types=1);

// Standalone Doctrine Migrations config for the MySQL migration namespace — deliberately
// NOT registered in config/packages/doctrine_migrations.php alongside the default SQLite
// namespace. Doctrine Migrations sorts a *combined* list of every registered namespace's
// migrations by fully-qualified class name when no explicit target version is given
// (confirmed the hard way: "App\Migrations\MySQL\..." sorts alphabetically before
// "App\Migrations\Version...", so with both namespaces registered together, a bare
// `doctrine:migrations:migrate` — exactly what `composer test`/`composer test-coverage`
// and the documented prod migration command already run — tried to execute the MySQL
// migration's MySQL-only SQL against the SQLite connection first, breaking the entire
// existing SQLite migration flow). Keeping this namespace in its own standalone config,
// invoked only via `--configuration=migrations-mysql.php`, means the default (registered)
// namespace's own migration commands never even know this one exists.
return [
    'migrations_paths' => [
        'App\\Migrations\\MySQL' => 'migrations-mysql',
    ],
];
