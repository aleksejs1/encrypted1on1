<?php

namespace App\Command;

use App\Company\SingleCompanyProvider;
use App\Entity\Anketa;
use App\Entity\User;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Load-tests the "SQLite/WAL hypothesis" (docs/adr/0003-sqlite-default-database.md,
 * private/todo.md item 26) for real: spawns concurrent child processes (this same
 * command, --worker mode) each opening its own real Doctrine connection — going through
 * the exact same SqliteConnectionMiddleware-applied WAL/busy_timeout pragmas the real app
 * uses — and hammering one real, pre-seeded anketa row with the same version-guarded
 * UPDATE pattern Anketa::saveComments() already uses (retry-on-conflict).
 *
 * Not wired into CI or any Makefile "test"/"lint" target — this is a one-off measurement
 * tool, meant to be run by hand against the isolated docker-compose.test.yml stack
 * (`make load-test-sqlite`) so it never touches dev's real data. See docs/adr/0003 for
 * the real numbers this produced.
 */
#[AsCommand(name: 'app:load-test-sqlite', description: 'Load-test concurrent SQLite writes under WAL mode')]
class LoadTestSqliteCommand extends Command
{
    private const array CONCURRENCY_LEVELS = [5, 20, 50, 100, 200];
    private const int WRITES_PER_WORKER = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SingleCompanyProvider $singleCompanyProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker', null, InputOption::VALUE_NONE, 'Internal: run as a single write-burst worker, not the orchestrator')
            ->addOption('anketa-id', null, InputOption::VALUE_REQUIRED, 'Internal: worker mode target anketa id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (true === $input->getOption('worker')) {
            $anketaId = $input->getOption('anketa-id');
            \assert(\is_string($anketaId));

            return $this->runWorker($anketaId, $output);
        }

        return $this->runOrchestrator($input, $output);
    }

    private function runOrchestrator(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Placeholder crypto strings, same "opaque, never actually unsealed" convention
        // ApiTestCase::activateUser() already uses — this test never decrypts anything,
        // only measures how the version-guarded UPDATE itself behaves under contention.
        $company = $this->singleCompanyProvider->get();
        $employee = new User('load-test-employee@example.invalid', 'x', 'x', 'x', $company);
        $manager = new User('load-test-manager@example.invalid', 'x', 'x', 'x', $company);
        $anketa = new Anketa($employee, $manager, new \DateTimeImmutable('+30 days'), 'x', 'x', 30);
        $this->entityManager->persist($employee);
        $this->entityManager->persist($manager);
        $this->entityManager->persist($anketa);
        $this->entityManager->flush();
        $anketaId = $anketa->getId();

        $io->title('SQLite/WAL concurrent-write load test');
        $io->writeln(\sprintf('Seeded anketa %s. %d writes attempted per worker.', $anketaId, self::WRITES_PER_WORKER));

        $rows = [];
        foreach (self::CONCURRENCY_LEVELS as $concurrency) {
            $rows[] = $this->runLevel($anketaId, $concurrency, $io);
        }

        $io->table(
            ['Concurrency', 'Attempted', 'Success', 'Lock errors', 'Retries exhausted', 'Other errors', 'Writes/sec', 'p50 (ms)', 'p95 (ms)', 'Max (ms)'],
            $rows,
        );

        return Command::SUCCESS;
    }

    /** @return list<int|string> */
    private function runLevel(string $anketaId, int $concurrency, SymfonyStyle $io): array
    {
        $io->section(\sprintf('Concurrency: %d', $concurrency));

        $consolePath = \dirname(__DIR__, 2).'/bin/console';
        $processes = [];
        for ($i = 0; $i < $concurrency; ++$i) {
            $pipes = [];
            $process = proc_open(
                ['php', $consolePath, 'app:load-test-sqlite', '--worker', '--anketa-id', $anketaId, '--env=test'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                \dirname(__DIR__, 2),
            );
            if (false === $process) {
                continue;
            }
            $processes[] = [$process, $pipes];
        }

        $success = 0;
        $lockErrors = 0;
        $otherErrors = 0;
        $retriesExhausted = 0;
        $latencies = [];
        $start = microtime(true);

        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]); // drain stderr too, so a chatty child can't block on a full pipe
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            /** @var array{success?: int, lockErrors?: int, otherErrors?: int, retriesExhausted?: int, latenciesMs?: list<float>}|null $result */
            $result = json_decode((string) $stdout, true);
            if (!\is_array($result)) {
                ++$otherErrors;
                continue;
            }
            $success += $result['success'] ?? 0;
            $lockErrors += $result['lockErrors'] ?? 0;
            $otherErrors += $result['otherErrors'] ?? 0;
            $retriesExhausted += $result['retriesExhausted'] ?? 0;
            array_push($latencies, ...($result['latenciesMs'] ?? []));
        }

        $elapsed = microtime(true) - $start;
        $attempted = $concurrency * self::WRITES_PER_WORKER;
        $writesPerSec = $elapsed > 0 ? $success / $elapsed : 0.0;

        sort($latencies);
        $max = [] !== $latencies ? $latencies[\count($latencies) - 1] : 0.0;

        return [
            $concurrency,
            $attempted,
            $success,
            $lockErrors,
            $retriesExhausted,
            $otherErrors,
            number_format($writesPerSec, 1),
            number_format($this->percentile($latencies, 50), 1),
            number_format($this->percentile($latencies, 95), 1),
            number_format($max, 1),
        ];
    }

    /** @param list<float> $sorted */
    private function percentile(array $sorted, int $pct): float
    {
        if ([] === $sorted) {
            return 0.0;
        }
        $index = (int) ceil($pct / 100 * \count($sorted)) - 1;

        return $sorted[max(0, min($index, \count($sorted) - 1))];
    }

    private function runWorker(string $anketaId, OutputInterface $output): int
    {
        $connection = $this->entityManager->getConnection();
        $success = 0;
        $lockErrors = 0;
        $otherErrors = 0;
        $retriesExhausted = 0;
        $latenciesMs = [];

        for ($i = 0; $i < self::WRITES_PER_WORKER; ++$i) {
            $attempts = 0;
            $done = false;
            while (!$done && $attempts < 10) {
                ++$attempts;
                try {
                    $start = microtime(true);
                    $rawVersion = $connection->fetchOne('SELECT commentsVersion FROM anketas WHERE id = ?', [$anketaId]);
                    $version = is_numeric($rawVersion) ? (int) $rawVersion : 0;
                    $affected = $connection->executeStatement(
                        'UPDATE anketas SET commentsBlob = ?, commentsVersion = commentsVersion + 1 WHERE id = ? AND commentsVersion = ?',
                        [\sprintf('load-test-blob-%d-%d', getmypid(), $i), $anketaId, $version],
                    );
                    $latenciesMs[] = (microtime(true) - $start) * 1000;
                    if ($affected > 0) {
                        $done = true;
                        ++$success;
                    }
                    // $affected === 0 is a genuine version conflict (another worker won the
                    // race in between) — the real app's own 409-and-retry shape, not a failure.
                    // Falls through to another loop iteration (a real retry) unless the
                    // 10-attempt budget above is exhausted, counted below as its own outcome.
                } catch (DbalException $e) {
                    if (str_contains($e->getMessage(), 'database is locked')) {
                        ++$lockErrors;
                    } else {
                        ++$otherErrors;
                    }
                    $done = true; // stop retrying this one write, count it and move on
                }
            }
            if (!$done) {
                ++$retriesExhausted;
            }
        }

        $output->write((string) json_encode([
            'success' => $success,
            'lockErrors' => $lockErrors,
            'otherErrors' => $otherErrors,
            'retriesExhausted' => $retriesExhausted,
            'latenciesMs' => $latenciesMs,
        ]));

        return Command::SUCCESS;
    }
}
