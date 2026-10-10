<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Company;
use App\Entity\User;
use App\Notification\InvitationNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/** GitHub issue #169: notifyInviteRenewalRequested(). */
class InvitationNotifierTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];

    /** @var list<array{0: string, 1: array<string, string>, 2: string|null}> */
    private array $translated = [];

    public function testAnAdminIsSentToTheInvitesList(): void
    {
        $recipient = $this->makeUser('admin@example.com', admin: true, locale: 'lv');

        self::assertTrue($this->makeNotifier()->notifyInviteRenewalRequested($recipient, 'new+hire@example.com'));

        self::assertCount(1, $this->sent);
        self::assertSame('admin@example.com', $this->sent[0]->getTo()[0]->getAddress());
        self::assertSame('noreply@example.com', $this->sent[0]->getFrom()[0]->getAddress());
        self::assertSame(['email.invite_renewal_requested.subject', 'email.invite_renewal_requested.body'], array_column($this->translated, 0));
        self::assertSame(['lv', 'lv'], array_column($this->translated, 2));
        self::assertSame(['%invitee%' => 'new+hire@example.com', '%url%' => 'https://example.com/admin/invites'], $this->translated[0][1]);
    }

    public function testAnyoneElseIsSentToAccountSettingsWithTheEmailFilledIn(): void
    {
        $recipient = $this->makeUser('member@example.com', admin: false, locale: 'en');

        $this->makeNotifier()->notifyInviteRenewalRequested($recipient, 'new+hire@example.com');

        self::assertSame('https://example.com/account?invite=new%2Bhire%40example.com', $this->translated[0][1]['%url%']);
    }

    public function testATransportFailureIsReportedAsFalse(): void
    {
        $mailer = self::createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('smtp down'));

        $log = tempnam(sys_get_temp_dir(), 'notifier-log');
        $previousLog = ini_set('error_log', (string) $log);
        try {
            $result = $this->makeNotifier($mailer)->notifyInviteRenewalRequested($this->makeUser('admin@example.com', true, 'en'), 'x@example.com');
        } finally {
            ini_set('error_log', false === $previousLog ? '' : $previousLog);
            @unlink((string) $log);
        }

        self::assertFalse($result);
    }

    private function makeNotifier(?MailerInterface $mailer = null): InvitationNotifier
    {
        if (null === $mailer) {
            $stub = self::createStub(MailerInterface::class);
            $stub->method('send')->willReturnCallback(function (Email $email): void {
                $this->sent[] = $email;
            });
            $mailer = $stub;
        }

        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(function (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string {
            /* @var array<string, string> $parameters */
            $this->translated[] = [$id, $parameters, $locale];

            return 'translated';
        });

        // A trailing slash, which the notifier trims, like the other notify methods.
        return new InvitationNotifier($mailer, $translator, 'https://example.com/', 'noreply@example.com');
    }

    private function makeUser(string $email, bool $admin, string $locale): User
    {
        return new User($email, 'auth', 'public', 'private', new Company('Notifier Co'), $admin, $locale);
    }
}
