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

    /**
     * A template choice must never leak into an email — a channel with real exposure
     * risk (mail server logs, lock-screen previews, a shared inbox), unlike an in-app
     * admin view that can at least be access-controlled. See
     * private/anketa-meeting-templates-proposal.md §3 (not tracked in git). Deliberately
     * exercises templateKey values beyond today's only real one ('regular') — the
     * invariant this locks in must keep holding as more templates are added later, not
     * just for the one key that exists right now.
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

        foreach (['regular', 'onboarding', 'support_checkin'] as $templateKey) {
            $anketa = new Anketa($employee, $manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30, $templateKey);
            $notifier->notifyAnketaCreated($anketa, $employee, $manager);
        }

        self::assertNotEmpty($capturedParams);
        foreach ($capturedParams as $parameters) {
            self::assertSame(['%creator%', '%date%', '%url%'], array_keys($parameters));
        }
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
