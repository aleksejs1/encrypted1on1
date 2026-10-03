<?php

namespace App\Command;

use App\Entity\Anketa;
use App\Notification\AnketaNotifier;
use App\Repository\AnketaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant to run once daily, weekends included, via an external cron entry (see
 * docs/deployment.md's "Meeting reminders") — not a
 * Symfony Scheduler/Messenger worker, which would need a new long-running
 * process this docker-compose setup has nowhere to put (see the Phase 6e plan).
 * Idempotent via Anketa::reminderMeetingDay: each anketa is claimed for its meeting day
 * before its emails go out, so a rerun, or an overlapping run, sends nothing twice —
 * except an anketa whose send failed and was released, which the next claim re-sends
 * in full, possibly to a participant whose email did go out.
 *
 * Business-day rule (GitHub issue #167): a Friday run also reminds Monday's meetings,
 * with "on Monday" copy, so nobody gets a work email on Sunday for a Monday 1:1. Sunday's
 * run still reminds any Monday meeting nobody was reminded about yet (created after
 * Friday's run, or Friday's run failed). Saturday and Sunday meetings keep the plain
 * day-before rule: whoever meets on a weekend works then anyway, and a Friday email
 * about a Sunday meeting would need yet another copy variant. "Today" is the UTC day,
 * and a meeting's day is its stored date's calendar day (the picked day at midnight; the
 * web UI sends UTC midnight). A meeting moved to another day is due again
 * for its new day; see Anketa::$reminderMeetingDay.
 */
#[AsCommand(name: 'app:send-reminders', description: "Send day-before meeting reminders for tomorrow's anketas (and Monday's, on a Friday)")]
class SendRemindersCommand extends Command
{
    private const int FRIDAY = 5;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AnketaNotifier $notifier,
        private readonly AnketaRepository $anketaRepository,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->entityManager->getFilters()->isEnabled(\App\Doctrine\CompanyFilter::NAME)) {
            $this->entityManager->getFilters()->disable(\App\Doctrine\CompanyFilter::NAME);
        }

        $io = new SymfonyStyle($input, $output);

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $today = $now->setTime(0, 0);

        $result = new ReminderRunResult();
        $this->remindMeetingsOn($today->modify('+1 day'), false, $now, $io, $result);
        if (self::FRIDAY === (int) $today->format('N')) {
            $this->remindMeetingsOn($today->modify('+3 days'), true, $now, $io, $result);
        }

        // "Processed", not "sent": a participant who opted out gets no email.
        $message = sprintf('Processed reminders for %d anketa(s).', $result->count);
        if ($result->failedDays > 0) {
            $message .= sprintf(' Selecting %d day(s) of meetings failed, so none of them were looked at; rerun today.', $result->failedDays);
        }
        if ($result->failed > 0) {
            $message .= sprintf(' %d failed and stay due; rerun today to retry them.', $result->failed);
        }
        if ($result->stuck > 0) {
            $message .= sprintf(' %d failed and couldn\'t be released, so no rerun retries them; see the errors above.', $result->stuck);
        }
        if (null !== $result->firstError) {
            $io->error($message);

            // Thrown, not just returned as a failure code, so error tracking (Sentry's console
            // listener, on the Cloud deployment) still sees it, with the first cause attached.
            throw new \RuntimeException($message, 0, $result->firstError);
        }
        $io->success($message);

        return Command::SUCCESS;
    }

    /**
     * Each anketa is loaded fresh, with its participants, right before its claim: a batch
     * loaded up front would go stale while earlier emails send (a side publishing, a
     * participant opting out), and on a Friday the Monday pass would get back the Saturday
     * pass's copies of an anketa moved to Monday since.
     *
     * Failures are recorded in `$result` rather than thrown, so one anketa (or one day's
     * select) can't stop the rest of the batch, or a Friday's Monday pass; execute() throws
     * once at the end.
     */
    private function remindMeetingsOn(\DateTimeImmutable $dayStart, bool $monday, \DateTimeImmutable $now, SymfonyStyle $io, ReminderRunResult $result): void
    {
        try {
            $ids = $this->anketaRepository->findDueForReminder($dayStart);
        } catch (\Throwable $e) {
            $io->error(sprintf('Selecting the meetings on %s failed: %s', $dayStart->format('Y-m-d'), $e->getMessage()));
            ++$result->failedDays;
            $result->firstError ??= $e;

            return;
        }

        foreach ($ids as $id) {
            $claimed = false;
            $previousDay = null;
            try {
                $this->entityManager->clear();
                $anketa = $this->anketaRepository->findWithParticipants($id);
                if (null === $anketa) {
                    continue;
                }
                $previousDay = $anketa->getReminderMeetingDay();
                // Claimed one at a time, right before sending: see the repository method.
                if (!$this->anketaRepository->claimReminder($id, $dayStart, $now)) {
                    continue;
                }
                $claimed = true;
                $this->sendReminders($anketa, $monday);
                ++$result->count;
            } catch (\Throwable $e) {
                // One anketa's failure, a mail transport failure included (see
                // sendReminders()), mustn't stop the rest of the batch, or the Monday pass.
                // Released so a same-day rerun (or, for a Monday meeting, Sunday's fallback)
                // retries it.
                $io->error(sprintf('Reminder for anketa %s failed: %s', $id, $e->getMessage()));
                $result->firstError ??= $e;
                if (!$claimed || $this->release($id, $dayStart, $previousDay, $io)) {
                    ++$result->failed;
                } else {
                    ++$result->stuck;
                }
            }
        }
    }

    private function release(string $id, \DateTimeImmutable $dayStart, ?\DateTimeImmutable $previousDay, SymfonyStyle $io): bool
    {
        try {
            $this->anketaRepository->releaseReminder($id, $dayStart, $previousDay);

            return true;
        } catch (\Throwable $releaseError) {
            $io->error(sprintf('Releasing anketa %s failed too, so it stays claimed: %s', $id, $releaseError->getMessage()));

            return false;
        }
    }

    /** @throws \RuntimeException if the mail transport failed for any of the emails */
    private function sendReminders(Anketa $anketa, bool $monday): void
    {
        $employee = $anketa->getEmployee();
        $manager = $anketa->getManager();
        $sent = true;

        foreach ([[$employee, $manager], [$manager, $employee]] as [$recipient, $counterpart]) {
            // `$sent = ... && $sent`, not the other way round: every email is still attempted.
            $sent = ($monday
                ? $this->notifier->notifyMeetingMonday($anketa, $recipient, $counterpart)
                : $this->notifier->notifyMeetingTomorrow($anketa, $recipient, $counterpart)) && $sent;
        }

        if (!$sent) {
            // AnketaNotifier logs and swallows transport failures; an SMTP outage must still
            // leave the reminder due for a rerun rather than claimed and lost.
            throw new \RuntimeException('The mail transport failed for at least one email (see the error log).');
        }
    }
}
