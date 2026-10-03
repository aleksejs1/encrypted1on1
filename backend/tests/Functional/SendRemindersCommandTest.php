<?php

namespace App\Tests\Functional;

use App\Command\SendRemindersCommand;
use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use App\Repository\AnketaRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CleansUpCompanies;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * GitHub issue #167: business-day reminders. Each test works in a company of its own and
 * its own week in 2091: the command reminds across every company, and the database isn't
 * recreated between tests, so a far-off week keeps other tests' meetings out of its runs.
 */
class SendRemindersCommandTest extends ApiTestCase
{
    use CleansUpCompanies;

    private const COMPANY_TABLES = ['anketas', 'users'];

    private const FOLLOW_UP_SUBJECT = 'Did your 1:1 happen?';

    /** @var list<Email> */
    private array $sent = [];

    private ?string $companyId = null;

    /** @var (\Closure(Email): void)|null runs inside the next mailer send, i.e. while the command is mid-batch */
    private ?\Closure $onSend = null;

    public function testTheCommandResolvesFromTheContainer(): void
    {
        static::createClient();

        self::assertInstanceOf(SendRemindersCommand::class, self::getContainer()->get(SendRemindersCommand::class));
    }

    public function testAFridayRunRemindsTomorrowsAndMondaysMeetingsButNotSundays(): void
    {
        static::createClient();
        $saturday = $this->makeAnketa('2091-06-02', 'fri-sat');
        $sunday = $this->makeAnketa('2091-06-03', 'fri-sun');
        $monday = $this->makeAnketa('2091-06-04', 'fri-mon');
        $monday->publish($monday->getManager(), 'published-blob');
        $tuesday = $this->makeAnketa('2091-06-05', 'fri-tue');
        $this->entityManager()->flush();

        $this->runCommandAt('2091-06-01 06:00');

        self::assertSame(['Your 1:1 is tomorrow'], $this->subjectsFor($saturday->getEmployee()));
        self::assertNotNull($this->reminderSentAt($saturday));

        self::assertSame(['Your 1:1 is on Monday'], $this->subjectsFor($monday->getEmployee()));
        self::assertSame(['Your 1:1 is on Monday'], $this->subjectsFor($monday->getManager()));
        // GitHub issue #200: one email each; only the side that hasn't published gets the extra line.
        self::assertSame([true], $this->nudgedFor($saturday->getEmployee()));
        self::assertSame([true], $this->nudgedFor($monday->getEmployee()));
        self::assertSame([false], $this->nudgedFor($monday->getManager()));
        self::assertEquals(new \DateTimeImmutable('2091-06-01 06:00', new \DateTimeZone('UTC')), $this->reminderSentAt($monday));

        self::assertSame([], $this->subjectsFor($sunday->getEmployee()), "a Sunday meeting keeps the day-before rule — Saturday's run reminds it");
        self::assertNull($this->reminderSentAt($sunday));
        self::assertSame([], $this->subjectsFor($tuesday->getEmployee()));
        self::assertNull($this->reminderSentAt($tuesday));
    }

    public function testASundayRunSkipsMondayMeetingsFridayRemindedAndCatchesTheRest(): void
    {
        static::createClient();
        $remindedOnFriday = $this->makeAnketa('2091-06-11', 'sun-friday');
        $this->entityManager()->flush();
        $this->runCommandAt('2091-06-08 06:00');
        self::assertCount(1, $this->subjectsFor($remindedOnFriday->getEmployee()));

        // Scheduled over the weekend, after Friday's run.
        $createdLater = $this->makeAnketa('2091-06-11', 'sun-later');
        $this->entityManager()->flush();
        $this->sent = [];

        $this->runCommandAt('2091-06-10 06:00');

        self::assertSame([], $this->subjectsFor($remindedOnFriday->getEmployee()));
        self::assertSame([], $this->subjectsFor($remindedOnFriday->getManager()));
        self::assertSame(['Your 1:1 is tomorrow'], $this->subjectsFor($createdLater->getEmployee()));
        self::assertNotNull($this->reminderSentAt($createdLater));
    }

    public function testAWeekdayRunRemindsOnlyTomorrowsMeetings(): void
    {
        static::createClient();
        $thursday = $this->makeAnketa('2091-06-14', 'wed-thu');
        $friday = $this->makeAnketa('2091-06-15', 'wed-fri');
        $monday = $this->makeAnketa('2091-06-18', 'wed-mon');
        $this->entityManager()->flush();

        $this->runCommandAt('2091-06-13 06:00');

        self::assertSame(['Your 1:1 is tomorrow'], $this->subjectsFor($thursday->getManager()));
        self::assertSame([], $this->subjectsFor($friday->getEmployee()));
        self::assertSame([], $this->subjectsFor($monday->getEmployee()));
    }

    /**
     * Friday's reminder is three days ahead, so a meeting moved to another day after it
     * must be reminded again for its new date. A move within the same day must not re-send.
     */
    public function testARescheduledMeetingIsRemindedAgainForItsNewDate(): void
    {
        static::createClient();
        $moved = $this->makeAnketa('2091-07-02', 'moved');
        $kept = $this->makeAnketa('2091-07-02', 'kept');
        $this->entityManager()->flush();
        $this->runCommandAt('2091-06-29 06:00');
        self::assertEquals(new \DateTimeImmutable('2091-07-02', new \DateTimeZone('UTC')), $this->reload($moved)->getReminderMeetingDay());

        $this->reload($moved)->reschedule(new \DateTimeImmutable('2091-07-04', new \DateTimeZone('UTC')));
        // Stored as its wall time, 09:00 on the same day.
        $this->reload($kept)->reschedule(new \DateTimeImmutable('2091-07-02T09:00:00+03:00'));
        $this->entityManager()->flush();
        $this->sent = [];

        // Sunday's run covers Monday 07-02 again: the kept meeting must not be re-sent.
        $this->runCommandAt('2091-07-01 06:00');
        self::assertSame([], $this->subjectsFor($kept->getEmployee()));
        self::assertSame([], $this->subjectsFor($moved->getEmployee()));

        $this->runCommandAt('2091-07-03 06:00');

        self::assertSame(['Your 1:1 is tomorrow'], $this->subjectsFor($moved->getEmployee()));
    }

    /**
     * A second run starting while the first is mid-batch (a cron retry) sends nothing the
     * first one claimed, and the first skips what the second claimed.
     */
    public function testOverlappingRunsSendEachReminderOnce(): void
    {
        static::createClient();
        $first = $this->makeAnketa('2091-07-10', 'overlap-a');
        $second = $this->makeAnketa('2091-07-10', 'overlap-b');
        $this->entityManager()->flush();
        $this->onSend = function (): void {
            $this->onSend = null;
            $this->runCommandAt('2091-07-09 06:00');
        };

        $this->runCommandAt('2091-07-09 06:00');

        foreach ([$first, $second] as $anketa) {
            self::assertSame(['Your 1:1 is tomorrow'], $this->subjectsFor($anketa->getEmployee()));
        }
    }

    /**
     * On a Friday, a Saturday meeting moved to Monday after the Saturday pass loaded it is
     * reminded again by the Monday pass, with its new date, not the stale loaded one.
     */
    public function testTheMondayPassRendersAMeetingMovedSinceTheSaturdayPass(): void
    {
        static::createClient();
        $anketa = $this->makeAnketa('2091-07-14', 'fri-moved');
        $this->entityManager()->flush();
        $this->onSend = function () use ($anketa): void {
            $this->onSend = null;
            // Another request, straight to the row: the command's loaded entity keeps the old date.
            $this->entityManager()->getConnection()->executeStatement(
                'UPDATE anketas SET meetingDate = :date WHERE id = :id',
                ['date' => '2091-07-16 00:00:00', 'id' => $anketa->getId()],
            );
        };

        $this->runCommandAt('2091-07-13 06:00');

        $bodies = array_map(static fn (Email $email): string => (string) $email->getTextBody(), array_values(array_filter(
            $this->sent,
            static fn (Email $email): bool => $email->getTo()[0]->getAddress() === $anketa->getEmployee()->getEmail() && 'Your 1:1 is on Monday' === $email->getSubject(),
        )));
        self::assertCount(1, $bodies);
        self::assertStringContainsString('2091-07-16', $bodies[0]);
    }

    /**
     * A send that throws after the claim is reported, released for the next run, and
     * doesn't stop the rest of the batch.
     */
    public function testAFailedSendIsReleasedAndTheBatchCarriesOn(): void
    {
        static::createClient();
        $failing = $this->makeAnketa('2091-07-19', 'send-fails');
        $other = $this->makeAnketa('2091-07-19', 'send-ok');
        $this->entityManager()->flush();
        $this->onSend = function (Email $email) use ($failing): void {
            if ($email->getTo()[0]->getAddress() === $failing->getEmployee()->getEmail()) {
                throw new \RuntimeException('translator exploded');
            }
        };

        [$exitCode, $display] = $this->runCommandAt('2091-07-18 06:00', expectSuccess: false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('translator exploded', $display);
        self::assertNull($this->reload($failing)->getReminderMeetingDay());
        self::assertCount(1, $this->subjectsFor($other->getEmployee()));

        $this->onSend = null;
        $this->sent = [];
        $this->runCommandAt('2091-07-18 06:00');
        self::assertCount(1, $this->subjectsFor($failing->getEmployee()));
        self::assertSame([], $this->subjectsFor($other->getEmployee()));
    }

    /** An SMTP outage leaves the reminder due and fails the run, instead of claiming it. */
    public function testAMailTransportFailureLeavesTheReminderDue(): void
    {
        static::createClient();
        $anketa = $this->makeAnketa('2091-08-02', 'smtp-down');
        $this->entityManager()->flush();
        $this->onSend = static function (): void {
            throw new TransportException('smtp down');
        };

        // AnketaNotifier error_log()s the failure; kept off STDERR, which Infection's initial
        // test run treats as a failure and stops on.
        $log = tempnam(sys_get_temp_dir(), 'reminders-log');
        $previousLog = ini_set('error_log', (string) $log);
        try {
            [$exitCode, $display] = $this->runCommandAt('2091-08-01 06:00', expectSuccess: false);
        } finally {
            ini_set('error_log', false === $previousLog ? '' : $previousLog);
        }
        self::assertStringContainsString('smtp down', (string) file_get_contents((string) $log));
        unlink((string) $log);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('mail transport failed', $display);
        self::assertNull($this->reload($anketa)->getReminderMeetingDay());

        $this->onSend = null;
        $this->runCommandAt('2091-08-01 06:00');
        self::assertCount(1, $this->subjectsFor($anketa->getEmployee()));
    }

    /** An employee who publishes while the batch is sending gets the reminder without the "not published" line. */
    public function testASidePublishedMidBatchGetsNoNudge(): void
    {
        static::createClient();
        $first = $this->makeAnketa('2091-07-26', 'publish-mid-a');
        $second = $this->makeAnketa('2091-07-26', 'publish-mid-b');
        $this->entityManager()->flush();
        $this->onSend = function () use ($first, $second): void {
            $this->onSend = null;
            // Whichever anketa sends first, the other one publishes meanwhile.
            foreach ([$first, $second] as $anketa) {
                $this->entityManager()->getConnection()->executeStatement(
                    'UPDATE anketas SET employeeBlob = :blob, employeePublishedAt = :now WHERE id = :id AND employeePublishedAt IS NULL',
                    ['blob' => 'published', 'now' => '2091-07-25 06:00:00', 'id' => $anketa->getId()],
                );
            }
        };

        $this->runCommandAt('2091-07-25 06:00');

        // The anketa sent first still nudges its employee; the other one's employee published
        // before its turn and gets the reminder without the "not published" line.
        $nudged = [...$this->nudgedFor($first->getEmployee()), ...$this->nudgedFor($second->getEmployee())];
        sort($nudged);
        self::assertSame([false, true], $nudged);
    }

    /** The day of week is the UTC one, the same as meetingDate's UTC midnight. */
    public function testTheWeekdayIsTakenInUtcNotTheClocksOwnTimezone(): void
    {
        static::createClient();
        $monday = $this->makeAnketa('2091-06-25', 'utc-mon');
        $this->entityManager()->flush();

        // Saturday morning in Auckland, still Friday in UTC.
        $this->runCommandAt('2091-06-23 09:00', 'Pacific/Auckland');

        self::assertSame(['Your 1:1 is on Monday'], $this->subjectsFor($monday->getEmployee()));
        self::assertEquals(new \DateTimeImmutable('2091-06-22 21:00', new \DateTimeZone('UTC')), $this->reminderSentAt($monday));
    }

    /**
     * GitHub issue #202: a meeting still open after its day gets one follow-up on the next
     * business day, so Monday's run covers Friday and the weekend.
     */
    public function testAMondayRunFollowsUpFridaysAndTheWeekendsOpenMeetings(): void
    {
        static::createClient();
        $thursday = $this->makeAnketa('2091-09-06', 'fu-thu');
        $this->entityManager()->flush();
        // Friday's run follows up Thursday's meeting; Monday's must not send it again.
        $this->runCommandAt('2091-09-07 06:00');
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($thursday->getEmployee()));
        $this->sent = [];
        $friday = $this->makeAnketa('2091-09-07', 'fu-fri');
        $saturday = $this->makeAnketa('2091-09-08', 'fu-sat');
        $sunday = $this->makeAnketa('2091-09-09', 'fu-sun');
        $today = $this->makeAnketa('2091-09-10', 'fu-today');
        $closed = $this->makeAnketa('2091-09-07', 'fu-closed');
        $closed->archive();
        $this->entityManager()->flush();

        [, $display] = $this->runCommandAt('2091-09-10 06:00');

        foreach ([$friday, $saturday, $sunday] as $anketa) {
            self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($anketa->getEmployee()));
            self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($anketa->getManager()));
            self::assertEquals($anketa->getMeetingDate(), $this->reload($anketa)->getFollowUpMeetingDay());
        }
        $body = $this->bodiesFor($friday->getEmployee())[0];
        self::assertStringContainsString('2091-09-07', $body);
        self::assertStringContainsString($friday->getManager()->getEmail(), $body);
        self::assertStringContainsString('https://example.com/anketas/'.$friday->getId()."#close\n", $body);
        self::assertStringEndsWith('https://example.com/anketas/'.$friday->getId().'#reschedule', $body);

        self::assertSame([], $this->subjectsFor($thursday->getEmployee()));
        self::assertSame([], $this->subjectsFor($today->getEmployee()));
        self::assertSame([], $this->subjectsFor($closed->getEmployee()));
        self::assertNull($this->reload($closed)->getFollowUpMeetingDay());
        self::assertStringContainsString('follow-ups for 3.', $display);
    }

    /** Nobody gets a work email on the weekend, and a rerun or a later run sends nothing new. */
    public function testAFollowUpWaitsForMondayAndIsSentOnce(): void
    {
        static::createClient();
        $friday = $this->makeAnketa('2091-09-14', 'fu-once');
        $this->entityManager()->flush();

        $this->runCommandAt('2091-09-15 06:00');
        $this->runCommandAt('2091-09-16 06:00');
        self::assertSame([], $this->subjectsFor($friday->getEmployee()));

        $this->runCommandAt('2091-09-17 06:00');
        $this->runCommandAt('2091-09-17 06:00');
        $this->runCommandAt('2091-09-18 06:00');
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($friday->getEmployee()));
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($friday->getManager()));
    }

    /**
     * A midweek run follows up yesterday's meeting, and an earlier one that no run followed
     * up (a skipped or failed run), but nothing older than the five-day window.
     */
    public function testAWeekdayRunFollowsUpYesterdayAndCatchesUpOnAMissedRun(): void
    {
        static::createClient();
        $old = $this->makeAnketa('2091-09-21', 'fu-old');
        $tuesday = $this->makeAnketa('2091-09-25', 'fu-tue');
        $wednesday = $this->makeAnketa('2091-09-26', 'fu-wed');
        $this->entityManager()->flush();

        $this->runCommandAt('2091-09-27 06:00');

        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($tuesday->getEmployee()));
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($wednesday->getEmployee()));
        self::assertSame([], $this->subjectsFor($old->getEmployee()));
    }

    /** A meeting moved to a later day isn't followed up for its old day, only after its new one. */
    public function testARescheduledMeetingIsFollowedUpForItsNewDate(): void
    {
        static::createClient();
        $movedBefore = $this->makeAnketa('2091-10-02', 'fu-moved-before');
        $movedAfter = $this->makeAnketa('2091-10-02', 'fu-moved-after');
        $this->entityManager()->flush();
        $this->reload($movedBefore)->reschedule(new \DateTimeImmutable('2091-10-08', new \DateTimeZone('UTC')));
        $this->entityManager()->flush();

        $this->runCommandAt('2091-10-03 06:00');
        self::assertSame([], $this->subjectsFor($movedBefore->getEmployee()));
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($movedAfter->getEmployee()));

        // Moved after its follow-up went out: due again once the new day has passed.
        $this->reload($movedAfter)->reschedule(new \DateTimeImmutable('2091-10-04', new \DateTimeZone('UTC')));
        $this->entityManager()->flush();
        $this->sent = [];
        $this->runCommandAt('2091-10-04 06:00');
        self::assertSame([], $this->subjectsFor($movedAfter->getEmployee()));

        $this->runCommandAt('2091-10-05 06:00');
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($movedAfter->getEmployee()));
        self::assertStringContainsString('2091-10-04', $this->bodiesFor($movedAfter->getEmployee())[0]);
    }

    public function testAFollowUpSkipsAnOptedOutRecipient(): void
    {
        static::createClient();
        $anketa = $this->makeAnketa('2091-10-09', 'fu-opt-out');
        $anketa->getEmployee()->setMeetingRemindersEnabled(false);
        $this->entityManager()->flush();

        $this->runCommandAt('2091-10-10 06:00');

        self::assertSame([], $this->subjectsFor($anketa->getEmployee()));
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($anketa->getManager()));
    }

    /** As for reminders: an SMTP outage fails the run and leaves the follow-up due. */
    public function testAMailTransportFailureLeavesTheFollowUpDue(): void
    {
        static::createClient();
        $anketa = $this->makeAnketa('2091-10-18', 'fu-smtp-down');
        $this->entityManager()->flush();
        $this->onSend = static function (): void {
            throw new TransportException('smtp down');
        };

        $log = tempnam(sys_get_temp_dir(), 'reminders-log');
        $previousLog = ini_set('error_log', (string) $log);
        try {
            [$exitCode, $display] = $this->runCommandAt('2091-10-19 06:00', expectSuccess: false);
        } finally {
            ini_set('error_log', false === $previousLog ? '' : $previousLog);
        }
        unlink((string) $log);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Follow-up for anketa '.$anketa->getId().' failed', $display);
        self::assertNull($this->reload($anketa)->getFollowUpMeetingDay());

        // A Thursday meeting whose Friday run failed: the weekend runs send no follow-ups,
        // and Monday's still reaches back to it.
        $this->onSend = null;
        $this->runCommandAt('2091-10-20 06:00');
        self::assertSame([], $this->subjectsFor($anketa->getEmployee()));
        $this->runCommandAt('2091-10-22 06:00');
        self::assertSame([self::FOLLOW_UP_SUBJECT], $this->subjectsFor($anketa->getEmployee()));
    }

    private function makeAnketa(string $meetingDate, string $label): Anketa
    {
        $this->companyId ??= $this->makeCompany('Reminders Co')->getId();
        // Looked up each time: the command clears the EntityManager, detaching it.
        $company = $this->entityManager()->find(Company::class, $this->companyId);
        self::assertNotNull($company);
        $employee = new User($this->uniqueEmail("reminders-$label-employee"), 'hash', 'pub', 'enc', $company);
        $manager = new User($this->uniqueEmail("reminders-$label-manager"), 'hash', 'pub', 'enc', $company);
        $anketa = new Anketa($employee, $manager, new \DateTimeImmutable($meetingDate, new \DateTimeZone('UTC')), 'sealed-e', 'sealed-m', 7);

        $this->entityManager()->persist($employee);
        $this->entityManager()->persist($manager);
        $this->entityManager()->persist($anketa);

        return $anketa;
    }

    /** @return array{0: int, 1: string} 0 or 1 for a run that threw, and the display */
    private function runCommandAt(string $now, string $timezone = 'UTC', bool $expectSuccess = true): array
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $email): void {
            if (null !== $this->onSend) {
                ($this->onSend)($email);
            }
            $this->sent[] = $email;
        });
        $translator = self::getContainer()->get('translator');
        \assert($translator instanceof TranslatorInterface);
        $notifier = new AnketaNotifier($mailer, $translator, 'https://example.com', 'noreply@example.com');

        $command = new SendRemindersCommand($this->entityManager(), $notifier, $this->anketaRepository(), new MockClock($now, $timezone));
        $tester = new CommandTester($command);
        if ($expectSuccess) {
            self::assertSame(0, $tester->execute([]), $tester->getDisplay());

            return [0, $tester->getDisplay()];
        }

        // A failed run throws at the end (so error tracking sees it), after reporting.
        try {
            $tester->execute([]);
        } catch (\RuntimeException $e) {
            return [1, $tester->getDisplay()."\n".$e->getMessage()];
        }
        self::fail('the run was expected to fail');
    }

    private function anketaRepository(): AnketaRepository
    {
        return $this->entityManager()->getRepository(Anketa::class);
    }

    /** Re-read: the command writes straight to the row and clears the EntityManager. */
    private function reload(Anketa $anketa): Anketa
    {
        $fresh = $this->entityManager()->find(Anketa::class, $anketa->getId());
        self::assertNotNull($fresh);
        $this->entityManager()->refresh($fresh);

        return $fresh;
    }

    private function reminderSentAt(Anketa $anketa): ?\DateTimeImmutable
    {
        return $this->reload($anketa)->getReminderSentAt();
    }

    /**
     * For each email to the recipient, whether it carries the "not published yet" line.
     *
     * @return list<bool>
     */
    private function nudgedFor(User $recipient): array
    {
        $nudged = [];
        foreach ($this->sent as $email) {
            if ($email->getTo()[0]->getAddress() === $recipient->getEmail()) {
                $nudged[] = str_contains((string) $email->getTextBody(), "You haven't published your part yet.");
            }
        }

        return $nudged;
    }

    /** @return list<string> */
    private function bodiesFor(User $recipient): array
    {
        $bodies = [];
        foreach ($this->sent as $email) {
            if ($email->getTo()[0]->getAddress() === $recipient->getEmail()) {
                $bodies[] = (string) $email->getTextBody();
            }
        }

        return $bodies;
    }

    /** @return list<string> */
    private function subjectsFor(User $recipient): array
    {
        $subjects = [];
        foreach ($this->sent as $email) {
            if ($email->getTo()[0]->getAddress() === $recipient->getEmail()) {
                $subjects[] = (string) $email->getSubject();
            }
        }

        return $subjects;
    }
}
