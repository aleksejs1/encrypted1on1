<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

class AnketaNotifierTest extends TestCase
{
    public function testNotifyMeetingTomorrowSendsWhenRecipientWantsReminders(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();

        $notifier->notifyMeetingTomorrow($anketa, $employee, $manager);
    }

    public function testNotifyMeetingTomorrowDoesNotSendWhenRecipientOptedOut(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();
        $employee->setMeetingRemindersEnabled(false);

        $notifier->notifyMeetingTomorrow($anketa, $employee, $manager);
    }

    /**
     * GitHub issue #167: the reminder methods tell SendRemindersCommand whether the mail
     * transport failed, so it can leave the reminder due; an opted-out recipient is done.
     */
    public function testRemindersReportAMailTransportFailure(): void
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('smtp down'));
        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();

        // The failure is error_log()ged; kept off STDERR, which Infection's initial test
        // run treats as a failure and stops on.
        $log = tempnam(sys_get_temp_dir(), 'notifier-log');
        $previousLog = ini_set('error_log', (string) $log);
        try {
            self::assertFalse($notifier->notifyMeetingTomorrow($anketa, $employee, $manager));
            self::assertFalse($notifier->notifyMeetingMonday($anketa, $employee, $manager));
        } finally {
            ini_set('error_log', false === $previousLog ? '' : $previousLog);
        }
        self::assertStringContainsString('Failed to send notification email to employee@example.com: smtp down', (string) file_get_contents((string) $log));
        unlink((string) $log);

        $employee->setMeetingRemindersEnabled(false);
        self::assertTrue($notifier->notifyMeetingTomorrow($anketa, $employee, $manager));
    }

    public function testRemindersReportASuccessfulSend(): void
    {
        $notifier = $this->makeNotifier(self::createStub(MailerInterface::class));
        [$anketa, $employee, $manager] = $this->makeAnketa();

        self::assertTrue($notifier->notifyMeetingMonday($anketa, $employee, $manager));
    }

    /** @return iterable<string, array{0: \Closure(AnketaNotifier, Anketa, User, User): bool, 1: string}> */
    public static function reminders(): iterable
    {
        yield 'tomorrow' => [
            static fn (AnketaNotifier $notifier, Anketa $anketa, User $recipient, User $counterpart) => $notifier->notifyMeetingTomorrow($anketa, $recipient, $counterpart),
            'email.meeting_tomorrow',
        ];
        // GitHub issue #167: Friday's reminder for a Monday meeting says "Monday", not "tomorrow".
        yield 'monday' => [
            static fn (AnketaNotifier $notifier, Anketa $anketa, User $recipient, User $counterpart) => $notifier->notifyMeetingMonday($anketa, $recipient, $counterpart),
            'email.meeting_monday',
        ];
    }

    /**
     * GitHub issue #200: one reminder email per recipient. A side that hasn't published
     * gets the same email with one more line, not a second email.
     *
     * @param \Closure(AnketaNotifier, Anketa, User, User): bool $notify
     */
    #[DataProvider('reminders')]
    public function testARemindersBodyDependsOnWhetherTheRecipientPublished(\Closure $notify, string $key): void
    {
        $sent = [];
        $notifier = $this->makeKeyEchoingNotifier($sent);
        [$anketa, $employee, $manager] = $this->makeAnketa();
        $anketa->publish($manager, 'published-blob');

        $notify($notifier, $anketa, $employee, $manager);
        $notify($notifier, $anketa, $manager, $employee);

        self::assertCount(2, $sent);
        self::assertSame('employee@example.com', $sent[0]->getTo()[0]->getAddress());
        self::assertSame("$key.subject %counterpart%,%date%,%url%", $sent[0]->getSubject());
        self::assertSame("$key.body_not_published %counterpart%,%date%,%url%", $sent[0]->getTextBody());
        self::assertSame('manager@example.com', $sent[1]->getTo()[0]->getAddress());
        self::assertSame("$key.subject %counterpart%,%date%,%url%", $sent[1]->getSubject());
        self::assertSame("$key.body %counterpart%,%date%,%url%", $sent[1]->getTextBody());
    }

    /**
     * @param \Closure(AnketaNotifier, Anketa, User, User): bool $notify
     * @param string                                             $key    unused here, shared provider
     */
    #[DataProvider('reminders')]
    public function testRemindersDoNotSendWhenRecipientOptedOut(\Closure $notify, string $key): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();
        $employee->setMeetingRemindersEnabled(false);

        $notify($notifier, $anketa, $employee, $manager);
    }

    /** GitHub issue #200: people are named by display name plus email, or by email alone if they never set one. */
    public function testEmailsNameTheOtherPersonByDisplayNameFallingBackToEmail(): void
    {
        $sent = [];
        $notifier = $this->makeParamEchoingNotifier($sent);
        [$anketa, $employee, $manager] = $this->makeAnketa();
        $previousDate = new \DateTimeImmutable('2030-01-02');

        $notifier->notifyAnketaCreated($anketa, $employee, $manager);
        $notifier->notifyMeetingTomorrow($anketa, $employee, $manager);
        $notifier->notifyMeetingRescheduled($anketa, $employee, $manager, $previousDate);
        $manager->setDisplayName('Maria Manager');
        $notifier->notifyAnketaCreated($anketa, $employee, $manager);
        $notifier->notifyMeetingTomorrow($anketa, $employee, $manager);
        $notifier->notifyMeetingRescheduled($anketa, $employee, $manager, $previousDate);

        $names = array_map(static function (Email $email): mixed {
            $params = json_decode((string) $email->getTextBody(), true);
            self::assertIsArray($params);

            return $params['%creator%'] ?? $params['%counterpart%'] ?? $params['%actor%'];
        }, $sent);
        self::assertSame([
            'manager@example.com', 'manager@example.com', 'manager@example.com',
            'Maria Manager (manager@example.com)', 'Maria Manager (manager@example.com)', 'Maria Manager (manager@example.com)',
        ], $names);
    }

    /** GitHub issue #200: a date change is announced to the counterpart, with both dates. */
    public function testNotifyMeetingRescheduledNamesBothDatesAndIgnoresTheOptOut(): void
    {
        $sent = [];
        $notifier = $this->makeParamEchoingNotifier($sent);
        $company = new Company('Test Co');
        $employee = new User('employee@example.com', 'hash', 'pub', 'enc', $company);
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc', $company);
        $anketa = new Anketa($employee, $manager, new \DateTimeImmutable('2030-01-09'), 'sealed-e', 'sealed-m', 30);
        $employee->setMeetingRemindersEnabled(false);

        $notifier->notifyMeetingRescheduled($anketa, $employee, $manager, new \DateTimeImmutable('2030-01-02'));

        self::assertCount(1, $sent);
        self::assertSame('employee@example.com', $sent[0]->getTo()[0]->getAddress());
        self::assertSame([
            '%actor%' => 'manager@example.com',
            '%old_date%' => '2030-01-02',
            '%date%' => '2030-01-09',
            '%url%' => 'https://example.com/anketas/'.$anketa->getId(),
        ], json_decode((string) $sent[0]->getTextBody(), true));
        self::assertSame('email.meeting_rescheduled.subject', $sent[0]->getSubject());
    }

    public function testNotifyAnketaCreatedSendsRegardlessOfTheOptOut(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();
        $employee->setMeetingRemindersEnabled(false);

        $notifier->notifyAnketaCreated($anketa, $employee, $manager);
    }

    /**
     * A template choice must never leak into an email — a channel with real exposure
     * risk (mail server logs, lock-screen previews, a shared inbox), unlike an in-app
     * admin view that can at least be access-controlled. See
     * private/anketa-meeting-templates-proposal.md §3 (not tracked in git). Exercises
     * every registered template plus one unrecognized key, so the invariant keeps holding
     * as templates are added without editing this list.
     */
    public function testNotifyAnketaCreatedNeverIncludesTemplateInformation(): void
    {
        // A stub, not a mock, matching makeNotifier()'s own convention below — no
        // call-count expectation is being verified on it here either, only on $translator's
        // captured parameters.
        $mailer = self::createStub(MailerInterface::class);

        $capturedParams = [];
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            function (string $id, array $parameters = []) use (&$capturedParams): string {
                $capturedParams[] = $parameters;

                return 'translated';
            },
        );
        $notifier = new AnketaNotifier($mailer, $translator, 'https://example.com', 'noreply@example.com');

        $company = new Company('Test Co');
        $employee = new User('employee@example.com', 'hash', 'pub', 'enc', $company);
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc', $company);

        // 'custom' (GitHub issue #144) needs a company template version, and its email
        // must say nothing about the template either.
        $version = new CustomTemplateVersion(new CustomTemplate($manager), 1, 'PIP follow-up', '', '{}', $manager);
        foreach ([...Anketa::TEMPLATE_KEYS, 'not-a-real-key'] as $templateKey) {
            $anketa = new Anketa($employee, $manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30, $templateKey, customTemplateVersion: Anketa::CUSTOM_TEMPLATE_KEY === $templateKey ? $version : null);
            $notifier->notifyAnketaCreated($anketa, $employee, $manager);
        }

        self::assertNotEmpty($capturedParams);
        foreach ($capturedParams as $parameters) {
            self::assertSame(['%creator%', '%date%', '%url%'], array_keys($parameters));
        }
    }

    /**
     * GitHub issue #144 (#133 §7.5): an anketa on a company template gets exactly the
     * email a Regular one does — nothing about the template, not even its name, which
     * is admin-written text that can say why a pair meets.
     */
    public function testACustomAnketasCreatedEmailIsTheSameAsARegularOnes(): void
    {
        $sent = [];
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent[] = $email;
        });
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.' '.json_encode($parameters),
        );
        $notifier = new AnketaNotifier($mailer, $translator, 'https://example.com', 'noreply@example.com');

        $company = new Company('Test Co');
        $employee = new User('employee@example.com', 'hash', 'pub', 'enc', $company);
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc', $company);
        $date = new \DateTimeImmutable('+1 day');
        $regular = new Anketa($employee, $manager, $date, 'sealed-e', 'sealed-m', 30);
        $version = new CustomTemplateVersion(new CustomTemplate($manager), 1, 'PIP follow-up', 'For the plan', '{}', $manager);
        $custom = new Anketa($employee, $manager, $date, 'sealed-e', 'sealed-m', 30, 'custom', customTemplateVersion: $version);

        $notifier->notifyAnketaCreated($regular, $employee, $manager);
        $notifier->notifyAnketaCreated($custom, $employee, $manager);

        self::assertCount(2, $sent);
        // The link names each anketa by its own id; everything else must match.
        $masked = static fn (mixed $text, Anketa $anketa): string => str_replace($anketa->getId(), '{id}', \is_string($text) ? $text : '');
        self::assertSame($masked($sent[0]->getSubject(), $regular), $masked($sent[1]->getSubject(), $custom));
        self::assertSame($masked($sent[0]->getTextBody(), $regular), $masked($sent[1]->getTextBody(), $custom));
        self::assertStringNotContainsString('PIP', $masked($sent[1]->getTextBody(), $custom));
    }

    /**
     * A notifier whose emails are the translation key plus the parameter names.
     *
     * @param list<Email> $sent filled as emails go out
     */
    private function makeKeyEchoingNotifier(array &$sent): AnketaNotifier
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => $id.' '.implode(',', array_keys($parameters)),
        );

        return new AnketaNotifier($this->collectingMailer($sent), $translator, 'https://example.com', 'noreply@example.com');
    }

    /**
     * A notifier whose email subject is the translation key and body the parameters as JSON.
     *
     * @param list<Email> $sent filled as emails go out
     */
    private function makeParamEchoingNotifier(array &$sent): AnketaNotifier
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = []): string => str_ends_with($id, '.subject') ? $id : (string) json_encode($parameters),
        );

        return new AnketaNotifier($this->collectingMailer($sent), $translator, 'https://example.com', 'noreply@example.com');
    }

    /** @param list<Email> $sent */
    private function collectingMailer(array &$sent): MailerInterface
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent[] = $email;
        });

        return $mailer;
    }

    private function makeNotifier(MailerInterface $mailer): AnketaNotifier
    {
        // A stub, not a mock — its return value is stubbed, but no call-count
        // expectation is being verified on it, only on $mailer.
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');

        return new AnketaNotifier($mailer, $translator, 'https://example.com', 'noreply@example.com');
    }

    /** @return array{0: Anketa, 1: User, 2: User} */
    private function makeAnketa(): array
    {
        $company = new Company('Test Co');
        $employee = new User('employee@example.com', 'hash', 'pub', 'enc', $company);
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc', $company);
        $anketa = new Anketa($employee, $manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30);

        return [$anketa, $employee, $manager];
    }
}
