<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
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

    public function testNotifyNotFilledOutSendsWhenRecipientWantsReminders(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();

        $notifier->notifyNotFilledOut($anketa, $employee, $manager);
    }

    public function testNotifyNotFilledOutDoesNotSendWhenRecipientOptedOut(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $notifier = $this->makeNotifier($mailer);
        [$anketa, $employee, $manager] = $this->makeAnketa();
        $employee->setMeetingRemindersEnabled(false);

        $notifier->notifyNotFilledOut($anketa, $employee, $manager);
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
