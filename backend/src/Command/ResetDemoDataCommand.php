<?php

namespace App\Command;

use App\Company\SingleCompanyProvider;
use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant to run on a schedule via an external cron entry (hourly recommended
 * — see docs/deployment.md), same "documented external trigger" pattern as
 * app:send-reminders and the backup scripts, not a Symfony Scheduler/
 * Messenger worker. Restores every fixed, publicly-documented demo
 * employee/manager pair (one per supported UI locale — en/ru/lv/es, see
 * frontend/src/i18n/index.ts's SUPPORTED_LOCALES) and their 3-cycle anketa
 * history (2 archived, 1 current) to a known-good seeded state, so a demo
 * visitor editing or clearing things out self-heals within one interval
 * rather than degrading permanently.
 *
 * Reads backend/fixtures/demo-seed.json — real ciphertext generated once,
 * offline, by actually driving the app's real UI with real crypto (see
 * frontend/scripts/generate-demo-fixture.mjs). This command itself never
 * touches any crypto: it's a dumb, idempotent replay of already-encrypted
 * bytes into User::resetDemoCredentials()/Anketa::resetForDemo(), which
 * exist specifically so this doesn't need raw reflection or hand-written
 * SQL to bypass the normal one-way publish()/version-guarded mutators —
 * those protect real concurrent user edits, which a scheduled reset isn't.
 *
 * Unlike the single-anketa v1 of this command, every locale's anketas are
 * deleted and recreated from scratch on every run rather than found and
 * updated in place: a demo pair's own history is exclusively owned by this
 * command (no real external data ever references it), so a full teardown +
 * rebuild is both simpler than positional matching across resets (which
 * anketa is "cycle 2" after a visitor creates an extra one?) and correctly
 * self-heals from *any* vandalism — extra anketas, deleted rows, edited
 * content — not just content edits to rows that still exist.
 */
#[AsCommand(name: 'app:reset-demo-data', description: 'Restore the fixed demo accounts and anketa history to their seeded state')]
class ResetDemoDataCommand extends Command
{
    private const CYCLE_MEETING_OFFSET_DAYS = [-50, -18, 6];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SingleCompanyProvider $singleCompanyProvider,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fixturePath = \dirname(__DIR__, 2).'/fixtures/demo-seed.json';
        if (!is_file($fixturePath)) {
            $io->error(sprintf('Fixture not found: %s', $fixturePath));

            return Command::FAILURE;
        }
        /** @var array{generatedAt: string, password: string, locales: array<string, array{employee: array{email: string, authHash: string, publicKey: string, encryptedPrivateKey: string}, manager: array{email: string, authHash: string, publicKey: string, encryptedPrivateKey: string}, goalUuid: string, goalTitle: string, goalDescription: ?string, goalTargetDateOffsetMonths: int, periodicityDays: int, cycles: array<int, array{archived: bool, missed: bool, employeeSealedKey: string, managerSealedKey: string, employeeBlob: ?string, managerBlob: ?string, commentsBlob: ?string, commentsVersion: int, outcomesBlob: ?string, outcomesVersion: int, goalCheckpointsBlob: ?string, goalCheckpointsVersion: int}>}>} $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: \JSON_THROW_ON_ERROR);

        $now = new \DateTimeImmutable();
        $summary = [];

        foreach ($fixture['locales'] as $locale => $data) {
            $employee = $this->findOrCreateUser($data['employee']);
            $manager = $this->findOrCreateUser($data['manager']);

            $this->deleteExistingAnketasForPair($employee, $manager);

            $targetDate = $now->modify(sprintf('+%d months', $data['goalTargetDateOffsetMonths']));

            foreach ($data['cycles'] as $index => $cycle) {
                $offsetDays = self::CYCLE_MEETING_OFFSET_DAYS[$index]
                    ?? throw new \RuntimeException(sprintf('Fixture locale "%s" has more cycles than this command knows offsets for.', $locale));

                $anketa = new Anketa(
                    employee: $employee,
                    manager: $manager,
                    meetingDate: $now->modify(sprintf('%+d days', $offsetDays)),
                    employeeSealedKey: $cycle['employeeSealedKey'],
                    managerSealedKey: $cycle['managerSealedKey'],
                    periodicityDays: $data['periodicityDays'],
                );
                $anketa->resetForDemo(
                    employeeBlob: $cycle['employeeBlob'],
                    employeePublishedAt: null !== $cycle['employeeBlob'] ? $now : null,
                    managerBlob: $cycle['managerBlob'],
                    managerPublishedAt: null !== $cycle['managerBlob'] ? $now : null,
                    commentsBlob: $cycle['commentsBlob'],
                    commentsVersion: $cycle['commentsVersion'],
                    outcomesBlob: $cycle['outcomesBlob'],
                    outcomesVersion: $cycle['outcomesVersion'],
                    goalCheckpointsBlob: $cycle['goalCheckpointsBlob'],
                    goalCheckpointsVersion: $cycle['goalCheckpointsVersion'],
                    archived: $cycle['archived'],
                    missed: $cycle['missed'],
                );
                $this->entityManager->persist($anketa);

                $this->entityManager->persist(new Goal(
                    goalUuid: $data['goalUuid'],
                    anketa: $anketa,
                    author: $employee,
                    title: $data['goalTitle'],
                    description: $data['goalDescription'],
                    targetDate: $targetDate,
                ));
            }

            $summary[] = sprintf('%s (%s <-> %s)', $locale, $employee->getEmail(), $manager->getEmail());
        }

        $this->entityManager->flush();

        $io->success(sprintf('Demo data reset for %d locale(s): %s.', \count($summary), implode(', ', $summary)));

        return Command::SUCCESS;
    }

    /**
     * @param array{email: string, authHash: string, publicKey: string, encryptedPrivateKey: string} $data
     */
    private function findOrCreateUser(array $data): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if (null === $user) {
            $user = new User(
                email: $data['email'],
                authHash: $data['authHash'],
                publicKey: $data['publicKey'],
                encryptedPrivateKey: $data['encryptedPrivateKey'],
                company: $this->singleCompanyProvider->get(),
            );
            $user->setDemo(true);
            $this->entityManager->persist($user);

            return $user;
        }

        $user->resetDemoCredentials($data['authHash'], $data['publicKey'], $data['encryptedPrivateKey']);
        $user->setDemo(true);
        // A visitor may have blocked/deleted-flagged the account via flows that
        // shouldn't apply to it, or an admin may have blocked it by mistake —
        // either way, the demo account should always be usable after a reset.
        $user->setBlocked(false);

        return $user;
    }

    /** Deletes Goals before their Anketas — both this app's own MySQL migration and a real DB's FK constraint require child rows gone first. */
    private function deleteExistingAnketasForPair(User $employee, User $manager): void
    {
        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('anketa.employee = :employee')
            ->andWhere('anketa.manager = :manager')
            ->setParameter('employee', $employee)
            ->setParameter('manager', $manager)
            ->getQuery()
            ->getResult();

        if ([] === $anketas) {
            return;
        }

        /** @var Goal[] $goals */
        $goals = $this->entityManager->createQueryBuilder()
            ->select('goal')
            ->from(Goal::class, 'goal')
            ->where('goal.anketa IN (:anketas)')
            ->setParameter('anketas', $anketas)
            ->getQuery()
            ->getResult();

        foreach ($goals as $goal) {
            $this->entityManager->remove($goal);
        }
        foreach ($anketas as $anketa) {
            $this->entityManager->remove($anketa);
        }
        // Flushed immediately (not batched with the recreate below) — the new
        // rows this locale is about to get would otherwise collide with the
        // not-yet-deleted old ones in the same unit of work.
        $this->entityManager->flush();
    }
}
