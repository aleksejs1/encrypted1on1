<?php

namespace App\Command;

use App\Entity\Anketa;
use App\Notification\AnketaNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant to run once daily via an external cron entry (see docs/history.md) — not a
 * Symfony Scheduler/Messenger worker, which would need a new long-running
 * process this docker-compose setup has nowhere to put (see the Phase 6e plan).
 * Idempotent via Anketa::reminderSentAt: a same-day rerun (cron retry,
 * misconfiguration) sends nothing twice.
 */
#[AsCommand(name: 'app:send-reminders', description: "Send day-before meeting reminders for tomorrow's anketas")]
class SendRemindersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnketaNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tomorrowStart = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('UTC')));
        $tomorrowEnd = $tomorrowStart->modify('+1 day');

        /** @var Anketa[] $anketas */
        $anketas = $this->entityManager->createQueryBuilder()
            ->select('anketa')
            ->from(Anketa::class, 'anketa')
            ->where('anketa.archivedAt IS NULL')
            ->andWhere('anketa.reminderSentAt IS NULL')
            ->andWhere('anketa.meetingDate >= :start AND anketa.meetingDate < :end')
            ->setParameter('start', $tomorrowStart)
            ->setParameter('end', $tomorrowEnd)
            ->getQuery()
            ->getResult();

        foreach ($anketas as $anketa) {
            $employee = $anketa->getEmployee();
            $manager = $anketa->getManager();

            $this->notifier->notifyMeetingTomorrow($anketa, $employee, $manager);
            $this->notifier->notifyMeetingTomorrow($anketa, $manager, $employee);

            if (!$anketa->isPublished($employee)) {
                $this->notifier->notifyNotFilledOut($anketa, $employee, $manager);
            }
            if (!$anketa->isPublished($manager)) {
                $this->notifier->notifyNotFilledOut($anketa, $manager, $employee);
            }

            $anketa->markReminderSent();

            // Flush per anketa, not once at the end: reminderSentAt only protects a same-day
            // rerun (see the class docblock) for anketas that actually made it to the DB —
            // a single flush() after the whole loop meant a crash on anketa #190 of 200 left
            // every prior one unmarked too, so the next cron run would email everyone again.
            $this->entityManager->flush();
        }

        $io->success(sprintf('Sent reminders for %d anketa(s).', \count($anketas)));

        return Command::SUCCESS;
    }
}
