<?php

namespace App\Notification;

use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Same shape as AnketaNotifier: plain text, best-effort send, translated
 * into the *recipient's* saved User::locale — unlike InvitationNotifier's
 * invitee, this recipient already has an account and a saved preference.
 */
class PasswordResetNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly string $frontendBaseUrl,
        private readonly string $mailerFrom,
    ) {
    }

    public function notifyPasswordResetRequested(User $recipient, string $rawToken): void
    {
        $locale = $recipient->getLocale();
        $params = ['%url%' => sprintf('%s/reset-password/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken)];

        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($recipient->getEmail())
            ->subject($this->translator->trans('email.password_reset_requested.subject', $params, null, $locale))
            ->text($this->translator->trans('email.password_reset_requested.body', $params, null, $locale));

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send password reset email to %s: %s', $recipient->getEmail(), $e->getMessage()));
        }
    }
}
