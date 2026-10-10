<?php

namespace App\Command;

use App\Account\AccountDeleter;
use App\Company\SingleCompanyProvider;
use App\Entity\Anketa;
use App\Entity\AnketaPrivateNote;
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
 * employee/manager pair — one per UI locale that has translated demo
 * content (en/ru/lv/es/de/fr; every current SUPPORTED_LOCALES entry has
 * one, see demo.ts) — and their 3-cycle anketa history (2 archived, 1
 * current) to a known-good seeded state, so a demo visitor editing or
 * clearing things out self-heals within one interval rather than
 * degrading permanently.
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
 * command, so a full teardown + rebuild is both simpler than positional matching across resets (which
 * anketa is "cycle 2" after a visitor creates an extra one?) and correctly
 * self-heals from *any* vandalism — extra anketas, deleted rows, edited
 * content — not just content edits to rows that still exist. "Extra
 * anketas" includes one a visitor created with anybody else: every anketa
 * a demo account takes part in is deleted, not only the pair's own, and
 * with it whatever that other user wrote there (answers, goals, private
 * notes). A 1:1 with the shared, public demo account lasts until the next
 * run for either side.
 */
#[AsCommand(name: 'app:reset-demo-data', description: 'Restore the fixed demo accounts and anketa history to their seeded state')]
class ResetDemoDataCommand extends Command
{
    private const CYCLE_MEETING_OFFSET_DAYS = [-50, -18, 6];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SingleCompanyProvider $singleCompanyProvider,
        private readonly AccountDeleter $accountDeleter,
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
        /** @var array{generatedAt: string, password: string, locales: array<string, array{employee: array{id: string, email: string, name: string, authHash: string, publicKey: string, encryptedPrivateKey: string}, manager: array{id: string, email: string, name: string, authHash: string, publicKey: string, encryptedPrivateKey: string}, goalUuid: string, goalTitle: string, goalDescription: ?string, goalTargetDateOffsetMonths: int, periodicityDays: int, cycles: array<int, array{archived: bool, missed: bool, employeeSealedKey: string, managerSealedKey: string, employeeBlob: ?string, managerBlob: ?string, commentsBlob: ?string, commentsVersion: int, outcomesBlob: ?string, outcomesVersion: int, goalCheckpointsBlob: ?string, goalCheckpointsVersion: int, topicsBlob: ?string, topicsVersion: int}>}>} $fixture */
        $fixture = json_decode((string) file_get_contents($fixturePath), true, flags: \JSON_THROW_ON_ERROR);

        $now = new \DateTimeImmutable();
        $summary = [];

        foreach ($fixture['locales'] as $locale => $data) {
            $this->retireAccountsWithAnotherId($data['employee'], $data['manager']);

            $employee = $this->findOrCreateUser($data['employee']);
            $manager = $this->findOrCreateUser($data['manager']);

            $this->deleteAnketasOf($employee, $manager, betweenThemOnly: false);

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
                    topicsBlob: $cycle['topicsBlob'],
                    topicsVersion: $cycle['topicsVersion'],
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
     * The fixture's comments, outcomes and goal checkpoints name their authors by user id,
     * inside the ciphertext, so a demo account must have the id the fixture was generated
     * with: otherwise the page shows that raw id instead of a name, and a visitor's own
     * seeded items aren't treated as theirs. An account created under another id (before
     * this command set it, or by someone registering the demo email first) is deleted like
     * any other account, which anonymizes it in place and frees its email, and
     * findOrCreateUser() then recreates it.
     *
     * @param array{id: string, email: string} $employeeData
     * @param array{id: string, email: string} $managerData
     */
    private function retireAccountsWithAnotherId(array $employeeData, array $managerData): void
    {
        $users = $this->entityManager->getRepository(User::class);
        $employee = $users->findOneBy(['email' => $employeeData['email']]);
        $manager = $users->findOneBy(['email' => $managerData['email']]);

        $retired = [];
        if (null !== $employee && $employee->getId() !== $employeeData['id']) {
            $retired[] = $employee;
        }
        if (null !== $manager && $manager->getId() !== $managerData['id']) {
            $retired[] = $manager;
        }
        if ([] === $retired) {
            return;
        }

        // The pair's seeded history goes with them, rather than staying behind on an
        // anonymized account where the next runs would no longer find it. Only what the
        // two have with each other: whoever holds a demo email under another id may be a
        // real person, whose other 1:1s are left to the account deletion below.
        if (null !== $employee && null !== $manager) {
            $this->deleteAnketasOf($employee, $manager, betweenThemOnly: true);
        }
        foreach ($retired as $user) {
            $this->accountDeleter->delete($user);
        }
        // Before the recreated account is inserted under the same email.
        $this->entityManager->flush();
    }

    /**
     * @param array{id: string, email: string, name: string, authHash: string, publicKey: string, encryptedPrivateKey: string} $data
     */
    private function findOrCreateUser(array $data): User
    {
        // By id, not by email: a visitor can delete the demo account like any other, which
        // scrubs the email but keeps the row, and its id, in place. Any other account
        // holding this email is already gone (retireAccountsWithAnotherId()).
        $user = $this->entityManager->getRepository(User::class)->find($data['id']);
        if (null === $user) {
            $user = new User(
                email: $data['email'],
                authHash: $data['authHash'],
                publicKey: $data['publicKey'],
                encryptedPrivateKey: $data['encryptedPrivateKey'],
                company: $this->singleCompanyProvider->get(),
                displayName: $data['name'],
                id: $data['id'],
            );
            $user->setDemo(true);
            $this->entityManager->persist($user);

            return $user;
        }

        $user->restoreDemoAccount($data['email']);
        $user->resetDemoCredentials($data['authHash'], $data['publicKey'], $data['encryptedPrivateKey']);
        $user->setDemo(true);
        // A visitor may have edited the display name via Account Settings — restored
        // here same as the credentials above, so the demo self-heals within one interval.
        $user->setDisplayName($data['name']);
        // A visitor may have blocked/deleted-flagged the account via flows that
        // shouldn't apply to it, or an admin may have blocked it by mistake —
        // either way, the demo account should always be usable after a reset.
        $user->setBlocked(false);

        return $user;
    }

    /**
     * Every anketa either demo account takes part in, in either role and with anybody: a
     * visitor can create a 1:1 with the roles swapped, swap them for the next one at
     * archive (GitHub issue #254), or create one with any other user of the company.
     * With $betweenThemOnly, just the ones the two have with each other, either way round.
     * Deletes Goals and private notes before their Anketas — both this app's own MySQL
     * migration and a real DB's FK constraint require child rows gone first.
     */
    private function deleteAnketasOf(User $employee, User $manager, bool $betweenThemOnly): void
    {
        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where($betweenThemOnly
                ? 'anketa.employee IN (:pair) AND anketa.manager IN (:pair)'
                : 'anketa.employee IN (:pair) OR anketa.manager IN (:pair)')
            ->setParameter('pair', [$employee, $manager])
            ->getQuery()
            ->getResult();

        if ([] === $anketas) {
            $this->deletePrivateNotes($employee, $manager, []);

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

        // Right before the flush: the notes panel autosaves every second while someone
        // types, so a note first saved after an earlier SELECT would block the anketa
        // delete on the foreign key. A save landing in the moment between this and the
        // flush still can; the next reset run then succeeds.
        $this->deletePrivateNotes($employee, $manager, $anketas);

        // Flushed immediately (not batched with the recreate below) — the new
        // rows this locale is about to get would otherwise collide with the
        // not-yet-deleted old ones in the same unit of work.
        $this->entityManager->flush();
    }

    /**
     * Every private note (GitHub issue #132) on the anketas about to be deleted, the
     * other participant's of a visitor-made anketa included, plus every note the two
     * accounts wrote anywhere. The second part isn't redundant: where there are no
     * foreign keys (SQLite), an autosave landing between this and the flush leaves a note
     * whose anketa is gone, and the demo keypairs are restored every run, so it would
     * stay readable to every later visitor. By author, the next run removes it.
     *
     * @param Anketa[] $anketas
     */
    private function deletePrivateNotes(User $employee, User $manager, array $anketas): void
    {
        $delete = $this->entityManager->createQueryBuilder()
            ->delete(AnketaPrivateNote::class, 'note')
            ->where('note.author IN (:authors)')
            ->setParameter('authors', [$employee, $manager]);
        if ([] !== $anketas) {
            $delete->orWhere('note.anketa IN (:anketas)')->setParameter('anketas', $anketas);
        }
        $delete->getQuery()->execute();
    }
}
