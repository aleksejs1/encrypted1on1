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
 * Idempotent via Anketa::reminderMeetingDay (and followUpMeetingDay): each anketa is claimed for its meeting day
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
 *
 * Follow-up (GitHub issue #202): a meeting still open after its day gets one "did your
 * 1:1 happen?" email per participant on the next business day, so Friday's, Saturday's
 * and Sunday's meetings are followed up on Monday and a weekend run sends none. Like a
 * reminder, a follow-up that failed is retried only by a rerun on the same day. Days are
 * UTC days. Claimed per meeting day like the reminder (Anketa::$followUpMeetingDay), so a
 * meeting moved after its follow-up gets another one after its new day.
 */
#[AsCommand(name: 'app:send-reminders', description: "Send day-before meeting reminders for tomorrow's anketas (and Monday's, on a Friday), and follow-ups for meetings left open")]
class SendRemindersCommand extends Command
{
    private const int FRIDAY = 5;

    private const int MONDAY = 1;

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
        $weekday = (int) $today->format('N');
        $this->processMeetingsOn($today->modify('+1 day'), ReminderPass::Tomorrow, $now, $io, $result);
        if (self::FRIDAY === $weekday) {
            $this->processMeetingsOn($today->modify('+3 days'), ReminderPass::Monday, $now, $io, $result);
        }
        if ($weekday <= self::FRIDAY) {
            // The previous business day; on a Monday, the weekend too.
            $followUpDaysBack = self::MONDAY === $weekday ? 3 : 1;
            for ($daysBack = 1; $daysBack <= $followUpDaysBack; ++$daysBack) {
                $this->processMeetingsOn($today->modify("-$daysBack days"), ReminderPass::FollowUp, $now, $io, $result);
            }
        }

        $message = $result->summary();
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
     * select) can't stop the rest of the batch, or the passes after it; execute() throws
     * once at the end.
     */
    private function processMeetingsOn(\DateTimeImmutable $dayStart, ReminderPass $pass, \DateTimeImmutable $now, SymfonyStyle $io, ReminderRunResult $result): void
    {
        $followUp = ReminderPass::FollowUp === $pass;
        try {
            $ids = $followUp
                ? $this->anketaRepository->findDueForFollowUp($dayStart)
                : $this->anketaRepository->findDueForReminder($dayStart);
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
                $anketa = $this->loadFresh($id, $pass);
                if (null === $anketa) {
                    continue;
                }
                $previousDay = $followUp ? $anketa->getFollowUpMeetingDay() : $anketa->getReminderMeetingDay();
                // Claimed one at a time, right before sending: see the repository methods.
                $claimed = $this->claim($id, $dayStart, $pass, $now);
                if ($claimed) {
                    $this->sendEmails($anketa, $pass);
                    $result->countProcessed($pass);
                }
            } catch (\Throwable $e) {
                // One anketa's failure, a mail transport failure included (see
                // sendEmails()), mustn't stop the rest of the batch, or the later passes.
                // Released so a same-day rerun (or, for a Monday meeting's reminder, Sunday's
                // fallback) retries it.
                $io->error(sprintf('%s for anketa %s failed: %s', $followUp ? 'Follow-up' : 'Reminder', $id, $e->getMessage()));
                $result->firstError ??= $e;
                $result->countFailed(!$claimed || $this->release($id, $dayStart, $previousDay, $pass, $io));
            }
        }
    }

    /**
     * The anketa as it is now, or null if it's gone or this pass skips it: a pair with a
     * blocked account (a deleted one included) gets no follow-up, and isn't claimed. That
     * account can't log in to use the links, and its counterpart can't schedule a next
     * meeting with it (AnketaLifecycleService::shouldCreateNext()).
     */
    private function loadFresh(string $id, ReminderPass $pass): ?Anketa
    {
        $this->entityManager->clear();
        $anketa = $this->anketaRepository->findWithParticipants($id);
        if (null === $anketa || ReminderPass::FollowUp !== $pass) {
            return $anketa;
        }

        return $anketa->getEmployee()->isBlocked() || $anketa->getManager()->isBlocked() ? null : $anketa;
    }

    private function claim(string $id, \DateTimeImmutable $dayStart, ReminderPass $pass, \DateTimeImmutable $now): bool
    {
        return ReminderPass::FollowUp === $pass
            ? $this->anketaRepository->claimFollowUp($id, $dayStart)
            : $this->anketaRepository->claimReminder($id, $dayStart, $now);
    }

    private function release(string $id, \DateTimeImmutable $dayStart, ?\DateTimeImmutable $previousDay, ReminderPass $pass, SymfonyStyle $io): bool
    {
        try {
            if (ReminderPass::FollowUp === $pass) {
                $this->anketaRepository->releaseFollowUp($id, $dayStart, $previousDay);
            } else {
                $this->anketaRepository->releaseReminder($id, $dayStart, $previousDay);
            }

            return true;
        } catch (\Throwable $releaseError) {
            $io->error(sprintf('Releasing anketa %s failed too, so it stays claimed: %s', $id, $releaseError->getMessage()));

            return false;
        }
    }

    /** @throws \RuntimeException if the mail transport failed for any of the emails */
    private function sendEmails(Anketa $anketa, ReminderPass $pass): void
    {
        $employee = $anketa->getEmployee();
        $manager = $anketa->getManager();
        $sent = true;

        foreach ([[$employee, $manager], [$manager, $employee]] as [$recipient, $counterpart]) {
            // `$sent = ... && $sent`, not the other way round: every email is still attempted.
            $sent = match ($pass) {
                ReminderPass::Tomorrow => $this->notifier->notifyMeetingTomorrow($anketa, $recipient, $counterpart),
                ReminderPass::Monday => $this->notifier->notifyMeetingMonday($anketa, $recipient, $counterpart),
                ReminderPass::FollowUp => $this->notifier->notifyMeetingFollowUp($anketa, $recipient, $counterpart),
            } && $sent;
        }

        if (!$sent) {
            // AnketaNotifier logs and swallows transport failures; an SMTP outage must still
            // leave the email due for a rerun rather than claimed and lost.
            throw new \RuntimeException('The mail transport failed for at least one email (see the error log).');
        }
    }
}
