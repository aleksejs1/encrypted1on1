<?php

namespace App\Notification;

use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Same shape as AnketaNotifier (Phase 6e): plain-text, no Twig, best-effort
 * send. A separate small class rather than folding into AnketaNotifier —
 * invitations aren't anketa-related at all, and each notifier stays
 * single-purpose.
 */
class InvitationNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $frontendBaseUrl,
        private readonly string $mailerFrom,
    ) {
    }

    public function notifyInvited(string $email, string $rawToken, User $inviter): void
    {
        $url = sprintf('%s/activate/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken);
        $body = sprintf(
            "%s has invited you to encrypted1on1.\n\nActivate your account: %s",
            $inviter->getEmail(),
            $url,
        );

        $message = (new Email())
            ->from($this->mailerFrom)
            ->to($email)
            ->subject('You\'ve been invited to encrypted1on1')
            ->text($body);

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send invitation email to %s: %s', $email, $e->getMessage()));
        }
    }
}
