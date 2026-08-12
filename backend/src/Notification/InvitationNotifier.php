<?php

namespace App\Notification;

use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Same shape as AnketaNotifier (Phase 6e/6i): plain-text, no Twig,
 * best-effort send, translated into the *recipient's* locale — but an
 * invitee has no account yet, so there's no `User::locale` to read. Falls
 * back to English; the invitee picks their own language once they land on
 * the activation page (Phase 6h), which is the earliest point their
 * preference can exist at all.
 */
class InvitationNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly string $frontendBaseUrl,
        private readonly string $mailerFrom,
    ) {
    }

    public function notifyInvited(string $email, string $rawToken, User $inviter): void
    {
        $params = [
            '%inviter%' => $inviter->getEmail(),
            '%url%' => sprintf('%s/activate/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken),
        ];

        $message = (new Email())
            ->from($this->mailerFrom)
            ->to($email)
            ->subject($this->translator->trans('email.invited.subject', $params))
            ->text($this->translator->trans('email.invited.body', $params));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send invitation email to %s: %s', $email, $e->getMessage()));
        }
    }

    /** REGISTRATION_MODE=domain's self-service signup (SignupController) — same shape as notifyInvited(), no %inviter% since nobody invited this person. */
    public function notifySignup(string $email, string $rawToken): void
    {
        $params = ['%url%' => sprintf('%s/activate/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken)];

        $message = (new Email())
            ->from($this->mailerFrom)
            ->to($email)
            ->subject($this->translator->trans('email.signup.subject', $params))
            ->text($this->translator->trans('email.signup.body', $params));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send signup confirmation email to %s: %s', $email, $e->getMessage()));
        }
    }
}
