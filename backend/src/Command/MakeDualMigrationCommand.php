<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs `doctrine:migrations:diff` against both migration histories in one step
 * (docs/adr/0003-sqlite-default-database.md's dual-migration cost; see
 * docs/deployment.md's "Using MySQL instead of SQLite" for the manual process this
 * replaces) and flags it when only one side needed a new migration — exactly the
 * failure mode that already happened for real once (three SQLite migrations' worth
 * of schema shipped with no MySQL equivalent until unrelated work went looking for
 * one and found the gap).
 *
 * Deliberately NOT wired into CI (ADR 3 keeps MySQL a second-class, non-required
 * escape hatch; see private/delivery-quality-improvement-proposal.md, not tracked in
 * git, for why a required MySQL CI gate would need its own maintainer sign-off first)
 * — a local dev tool, run by hand after changing an entity, the same way
 * `doctrine:migrations:diff` itself already is.
 *
 * Does NOT make the generated MySQL migration safe to commit as-is: MySQL silently
 * backfills a bare `NOT NULL` column with the type's zero-value instead of rejecting
 * it the way SQLite does, which has already produced a broken migration once (a
 * foreign-key add failing against an empty-string backfill) — every populated-table
 * migration in this project's history needs the same by-hand review this command
 * can't replace, only remind you to do.
 *
 * Known, accepted blind spot: `--no-interaction` (needed so this can run
 * non-interactively at all) also makes doctrine:migrations:diff skip its own
 * confirmation prompt for an already-pending, unexecuted migration in either
 * directory. A prior run that generated a file and then crashed or was killed before
 * cleanup (see execute()'s own cleanup on a MySQL-side failure, which handles the
 * ordinary failure path) could leave one behind for the next run to silently diff
 * against a schema it hasn't actually reached yet. Narrow (needs a hard interruption,
 * not just a normal failure) and self-correcting (a run against an already-generated,
 * unexecuted migration on git status would already look wrong at a glance) — left as
 * a disclosed gap rather than adding pending-migration detection across two separate
 * DB connections for it.
 */
#[AsCommand(
    name: 'app:make-dual-migration',
    description: 'Diffs entity mappings against both the SQLite and MySQL migration histories, flagging drift between them',
)]
class MakeDualMigrationCommand extends Command
{
    private const string SQLITE_MIGRATIONS_DIR = 'migrations';
    private const string MYSQL_MIGRATIONS_DIR = 'migrations-mysql';

    protected function configure(): void
    {
        $this->addOption(
            'mysql-url',
            null,
            InputOption::VALUE_REQUIRED,
            'DATABASE_URL of a MySQL instance already migrated to the latest migrations-mysql/ version — falls back to $MYSQL_DATABASE_URL if omitted',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $mysqlUrl = $this->resolveMysqlUrl($input);
        if (null === $mysqlUrl) {
            $io->error('No MySQL connection given: pass --mysql-url or set $MYSQL_DATABASE_URL to a MySQL instance already migrated to the latest migrations-mysql/ version (see docs/deployment.md\'s "Using MySQL instead of SQLite").');

            return Command::INVALID;
        }

        $projectDir = \dirname(__DIR__, 2);
        $consolePath = $projectDir.'/bin/console';
        $sqliteDir = $projectDir.'/'.self::SQLITE_MIGRATIONS_DIR;

        $sqliteNew = $this->diffSide('SQLite', $sqliteDir, [], null, $consolePath, $projectDir, $io);
        if (null === $sqliteNew) {
            return Command::FAILURE;
        }

        $mysqlNew = $this->diffSide('MySQL', $projectDir.'/'.self::MYSQL_MIGRATIONS_DIR, ['--configuration=migrations-mysql.php'], $mysqlUrl, $consolePath, $projectDir, $io);
        if (null === $mysqlNew) {
            // Don't leave a lone SQLite migration behind: an unrelated MySQL failure
            // (bad --mysql-url, a real connection error) would otherwise strand a
            // real, applicable migration file on disk that a *later* run then sees as
            // pre-existing, not new — misreporting genuine drift that this failed run
            // itself caused, not a real pre-existing mismatch.
            $this->cleanupGeneratedFiles($sqliteDir, $sqliteNew, $io);

            return Command::FAILURE;
        }

        return $this->reportResult($sqliteNew, $mysqlNew, $io);
    }

    private function resolveMysqlUrl(InputInterface $input): ?string
    {
        $option = $input->getOption('mysql-url');
        \assert(null === $option || \is_string($option));

        // $_SERVER/$_ENV, not getenv(): this project's SymfonyRuntime boots Dotenv
        // with usePutenv(false) (the framework default, confirmed in
        // vendor/symfony/runtime/SymfonyRuntime.php and not overridden in
        // composer.json's extra.runtime), so a value set the normal project way —
        // added to backend/.env — never reaches real getenv(), only $_SERVER/$_ENV.
        $server = $_SERVER['MYSQL_DATABASE_URL'] ?? null;
        $envVar = $_ENV['MYSQL_DATABASE_URL'] ?? null;
        \assert(null === $server || \is_string($server));
        \assert(null === $envVar || \is_string($envVar));

        return DualMigrationDiffOutcome::firstNonEmpty($option, $server, $envVar);
    }

    /**
     * Diffs one side (SQLite or MySQL) and returns the list of migration files it
     * generated — empty if the mappings already matched. Null means the diff itself
     * failed for a real reason (not just "nothing to diff"), with the error already
     * printed to $io. Refuses to diff at all if this side isn't already migrated to
     * its own latest version first: `doctrine:migrations:diff` computes a delta
     * against whatever schema the target database is *actually* at, so a database
     * that's simply behind on already-existing migrations would otherwise conflate
     * "missing migrations" with "changed entity mappings" — reported as DRIFT for a
     * reason that has nothing to do with the two histories being out of sync with
     * each other.
     *
     * @param list<string> $extraArgs
     *
     * @return list<string>|null
     */
    private function diffSide(string $label, string $dir, array $extraArgs, ?string $databaseUrl, string $consolePath, string $cwd, SymfonyStyle $io): ?array
    {
        $io->section(\sprintf('Diffing against %s (%s/)', $label, basename($dir)));

        $upToDate = $this->runConsoleCommand($consolePath, $cwd, 'doctrine:migrations:up-to-date', $extraArgs, $databaseUrl);
        if (0 !== $upToDate['exitCode']) {
            $io->writeln($upToDate['output']);
            $io->error(\sprintf('%s is not migrated to its own latest version yet — run its migrate command first, then re-run this.', $label));

            return null;
        }

        $before = $this->listMigrationFiles($dir);
        $diff = $this->runConsoleCommand($consolePath, $cwd, 'doctrine:migrations:diff', $extraArgs, $databaseUrl);
        $io->writeln($diff['output']);

        // doctrine:migrations:diff exits 1 (not 0) for the legitimate "this side
        // already matches the entity mappings" case, indistinguishable from a real
        // failure by exit code alone — confirmed by actually running it against an
        // already-migrated database, not assumed from its --help text.
        if (0 !== $diff['exitCode'] && !str_contains($diff['output'], 'No changes detected')) {
            $io->error(\sprintf('%s diff failed — see output above.', $label));

            return null;
        }

        return array_values(array_diff($this->listMigrationFiles($dir), $before));
    }

    /** @param list<string> $files */
    private function cleanupGeneratedFiles(string $dir, array $files, SymfonyStyle $io): void
    {
        $removed = [];
        foreach ($files as $file) {
            if (unlink($dir.'/'.$file)) {
                $removed[] = $file;
            }
        }
        if ([] !== $removed) {
            $io->writeln('Removed leftover migration(s) from this failed run: '.implode(', ', $removed));
        }
        $failed = array_values(array_diff($files, $removed));
        if ([] !== $failed) {
            $io->error('Could not remove leftover migration(s), left in place — delete by hand before re-running: '.implode(', ', $failed));
        }
    }

    /**
     * @param list<string> $sqliteNew
     * @param list<string> $mysqlNew
     */
    private function reportResult(array $sqliteNew, array $mysqlNew, SymfonyStyle $io): int
    {
        if ([] !== $sqliteNew) {
            $io->writeln('New SQLite migration(s): '.implode(', ', $sqliteNew));
        }
        if ([] !== $mysqlNew) {
            $io->writeln('New MySQL migration(s): '.implode(', ', $mysqlNew));
        }

        $outcome = DualMigrationDiffOutcome::classify($sqliteNew, $mysqlNew);

        if (DualMigrationDiffOutcome::BOTH_UP_TO_DATE === $outcome) {
            $io->success('Both migration histories already match the current entity mappings — nothing to generate.');

            return Command::SUCCESS;
        }

        if (DualMigrationDiffOutcome::DRIFT === $outcome) {
            $io->error(
                'Drift detected: one migration history needed a new migration for this entity change and the other '
                .'did not, which means the two histories were already out of sync *before* this ran. Investigate with '
                .'`php bin/console doctrine:schema:validate --skip-sync` (validates against whichever DATABASE_URL is '
                .'currently active — prefix with e.g. `DATABASE_URL=mysql://... ` to check the MySQL side instead) '
                .'before committing the migration that was generated above.',
            );

            return Command::FAILURE;
        }

        $io->warning(
            'The generated MySQL migration is a draft, not a finished one — review it by hand for populated-table '
            .'safety before committing (see docs/deployment.md\'s "Using MySQL instead of SQLite"). MySQL silently '
            .'backfills a bare NOT NULL column with the column type\'s zero-value instead of rejecting it the way '
            .'SQLite does; add an explicit default/backfill statement the same way every populated-table migration '
            .'in this project already does.',
        );

        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function listMigrationFiles(string $dir): array
    {
        $files = glob($dir.'/Version*.php');

        return false === $files ? [] : array_map('basename', $files);
    }

    /**
     * Runs one `bin/console` subcommand as a subprocess and returns its exit code and
     * combined output, without throwing — every caller here needs to inspect a
     * failure's output, not just know that it failed.
     *
     * @param list<string> $extraArgs
     *
     * @return array{exitCode: int, output: string}
     */
    private function runConsoleCommand(string $consolePath, string $cwd, string $command, array $extraArgs, ?string $databaseUrl): array
    {
        // Explicitly unset (not just "leave alone") when $databaseUrl is null: a real
        // exported DATABASE_URL in this process's own environment (e.g. a shell where
        // docs/deployment.md's own MySQL-testing instructions are already active)
        // would otherwise silently pass through to what's meant to be the SQLite
        // side, diffing the wrong database with no warning. Unsetting it here forces
        // the child's own bootstrap to fall back to its normal .env-configured
        // default (SQLite in dev) instead of inheriting whatever happens to already
        // be exported in the parent shell.
        $env = getenv();
        if (null !== $databaseUrl) {
            $env['DATABASE_URL'] = $databaseUrl;
        } else {
            unset($env['DATABASE_URL']);
        }

        $pipes = [];
        // stderr redirected into the same pipe as stdout: reading two separate pipes
        // sequentially (stdout fully, then stderr) can deadlock if the child fills the
        // OS buffer on the one not currently being read, and these commands write both
        // a verbose stdout summary and, sometimes, stderr deprecation notices in the
        // same run. `['redirect', 1]` is a real, working proc_open descriptor
        // (confirmed by actually running it) that PHPStan's builtin stub doesn't model
        // in a mixed pipe/redirect descriptor array.
        $process = proc_open(
            ['php', $consolePath, $command, '--no-interaction', ...$extraArgs],
            // Redirect target must be a real int at runtime (confirmed by hand), but
            // PHPStan's proc_open stub only types descriptor arrays as list<string>|resource.
            // @phpstan-ignore-next-line argument.type
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $cwd,
            $env,
        );

        if (false === $process) {
            return ['exitCode' => 1, 'output' => \sprintf('Failed to start %s.', $command)];
        }

        $combinedOutput = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return ['exitCode' => $exitCode, 'output' => $combinedOutput];
    }
}
