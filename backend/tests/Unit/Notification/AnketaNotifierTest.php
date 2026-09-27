<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\CustomTemplate;
use App\Entity\CustomTemplateVersion;
use App\Entity\User;
use App\Notification\AnketaNotifier;
use PHPUnit\Framework\TestCase;
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
