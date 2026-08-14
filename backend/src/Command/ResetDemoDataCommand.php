<?php

namespace App\Command;

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
 * Messenger worker. Restores the two fixed, publicly-documented demo
 * accounts (see private/demo-mode-plan.md, not tracked in git) and their
 * shared anketa to a known-good seeded state, so a demo visitor editing or
 * clearing things out self-heals within one interval rather than degrading
 * permanently.
 *
 * Reads backend/fixtures/demo-seed.json — real ciphertext generated once,
 * offline, by actually driving the app's real UI with real crypto (see
 * frontend/scripts/generate-demo-fixture.mjs). This command itself never
 * touches any crypto: it's a dumb, idempotent replay of already-encrypted
 * bytes into the two entities' own narrowly-scoped reset methods
 * (User::resetDemoCredentials(), Anketa::resetForDemo()), which exist
 * specifically so this doesn't need raw reflection or hand-written SQL to
 * bypass the normal one-way publish()/version-guarded mutators — those
 * protect real concurrent user edits, which a scheduled reset isn't.
 *
 * First run creates the two Users, the Anketa, and the Goal from scratch;
 * every later run finds the same rows again (by email for the Users, by
 * the employee/manager pair for the Anketa, by goalUuid for the Goal — none
 * of these need the fixture to pin a specific database id) and overwrites
 * their content back to the fixture's values.
 */
#[AsCommand(name: 'app:reset-demo-data', description: 'Restore the fixed demo accounts and anketa to their seeded state')]
class ResetDemoDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        /** @var array{employee: array{email: string, authHash: string, publicKey: string, encryptedPrivateKey: string}, manager: array{email: string, authHash: string, publicKey: string, encryptedPrivateKey: string}, anketa: array{employeeSealedKey: string, managerSealedKey: string, periodicityDays: int, meetingDateOffsetDays: int, employeeBlob: string, managerBlob: string, commentsBlob: ?string, commentsVersion: int, outcomesBlob: ?string, outcomesVersion: int, goalCheckpointsBlob: ?string, goalCheckpointsVersion: int}, goal: array{goalUuid: string, title: string, description: ?string, targetDateOffsetMonths: int}} $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: \JSON_THROW_ON_ERROR);

        $now = new \DateTimeImmutable();

        $employee = $this->findOrCreateUser($fixture['employee'], $now);
        $manager = $this->findOrCreateUser($fixture['manager'], $now);

        $anketa = $this->findAnketaForPair($employee, $manager);
        if (null === $anketa) {
            $anketa = new Anketa(
                employee: $employee,
                manager: $manager,
                meetingDate: $now->modify(sprintf('+%d days', $fixture['anketa']['meetingDateOffsetDays'])),
                employeeSealedKey: $fixture['anketa']['employeeSealedKey'],
                managerSealedKey: $fixture['anketa']['managerSealedKey'],
                periodicityDays: $fixture['anketa']['periodicityDays'],
            );
            $this->entityManager->persist($anketa);
        }
        $anketa->resetForDemo(
            meetingDate: $now->modify(sprintf('+%d days', $fixture['anketa']['meetingDateOffsetDays'])),
            employeeBlob: $fixture['anketa']['employeeBlob'],
            employeePublishedAt: $now,
            managerBlob: $fixture['anketa']['managerBlob'],
            managerPublishedAt: $now,
            commentsBlob: $fixture['anketa']['commentsBlob'],
            commentsVersion: $fixture['anketa']['commentsVersion'],
            outcomesBlob: $fixture['anketa']['outcomesBlob'],
            outcomesVersion: $fixture['anketa']['outcomesVersion'],
            goalCheckpointsBlob: $fixture['anketa']['goalCheckpointsBlob'],
            goalCheckpointsVersion: $fixture['anketa']['goalCheckpointsVersion'],
        );

        $goal = $this->entityManager->getRepository(Goal::class)->findOneBy([
            'goalUuid' => $fixture['goal']['goalUuid'],
            'anketa' => $anketa,
        ]);
        $targetDate = $now->modify(sprintf('+%d months', $fixture['goal']['targetDateOffsetMonths']));
        if (null === $goal) {
            $goal = new Goal(
                goalUuid: $fixture['goal']['goalUuid'],
                anketa: $anketa,
                author: $employee,
                title: $fixture['goal']['title'],
                description: $fixture['goal']['description'],
                targetDate: $targetDate,
            );
            $this->entityManager->persist($goal);
        } else {
            $goal->setTitle($fixture['goal']['title']);
            $goal->setDescription($fixture['goal']['description']);
            $goal->setTargetDate($targetDate);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Demo data reset: %s <-> %s, anketa %s.', $employee->getEmail(), $manager->getEmail(), $anketa->getId()));

        return Command::SUCCESS;
    }

    /**
     * @param array{email: string, authHash: string, publicKey: string, encryptedPrivateKey: string} $data
     */
    private function findOrCreateUser(array $data, \DateTimeImmutable $now): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if (null === $user) {
            $user = new User(
                email: $data['email'],
                authHash: $data['authHash'],
                publicKey: $data['publicKey'],
                encryptedPrivateKey: $data['encryptedPrivateKey'],
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

    private function findAnketaForPair(User $employee, User $manager): ?Anketa
    {
        /** @var Anketa|null $anketa */
        $anketa = $this->entityManager->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('anketa.employee = :employee')
            ->andWhere('anketa.manager = :manager')
            ->setParameter('employee', $employee)
            ->setParameter('manager', $manager)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $anketa;
    }
}
