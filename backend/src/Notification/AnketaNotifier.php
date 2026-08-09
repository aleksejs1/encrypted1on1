<?php

namespace App\Notification;

use App\Entity\Anketa;
use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * All three notification emails are plain-text, hand-built strings — no Twig,
 * no templating dependency (see the Phase 6e plan): this backend never has
 * anketa plaintext to interpolate in the first place, so the entire email
 * body being a literal PHP string here is what makes "never leaks content"
 * structurally true, not just a convention to remember. Sending is
 * best-effort — a transport failure is logged (no monolog-bundle in this
 * minimal Symfony setup, so plain error_log() rather than pulling one in for
 * a single log line) and never allowed to fail the request it's attached to.
 */
class AnketaNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $frontendBaseUrl,
        private readonly string $mailerFrom,
    ) {
    }

    public function notifyAnketaCreated(Anketa $anketa, User $recipient, User $creator): void
    {
        $this->send(
            $recipient,
            'New 1:1 anketa scheduled',
            sprintf(
                "%s has scheduled a 1:1 with you for %s.\n\nPlease fill out your part: %s",
                $creator->getEmail(),
                $this->formatDate($anketa),
                $this->anketaUrl($anketa),
            ),
        );
    }

    public function notifyMeetingTomorrow(Anketa $anketa, User $recipient, User $counterpart): void
    {
        $this->send(
            $recipient,
            'Your 1:1 is tomorrow',
            sprintf(
                "Your 1:1 with %s is tomorrow (%s).\n\n%s",
                $counterpart->getEmail(),
                $this->formatDate($anketa),
                $this->anketaUrl($anketa),
            ),
        );
    }

    public function notifyNotFilledOut(Anketa $anketa, User $recipient, User $counterpart): void
    {
        $this->send(
            $recipient,
            "Reminder: fill out tomorrow's anketa",
            sprintf(
                "You haven't filled out your part yet for tomorrow's 1:1 with %s (%s).\n\n%s",
                $counterpart->getEmail(),
                $this->formatDate($anketa),
                $this->anketaUrl($anketa),
            ),
        );
    }

    private function send(User $recipient, string $subject, string $body): void
    {
        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($recipient->getEmail())
            ->subject($subject)
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send notification email to %s: %s', $recipient->getEmail(), $e->getMessage()));
        }
    }

    private function formatDate(Anketa $anketa): string
    {
        return $anketa->getMeetingDate()->format('Y-m-d');
    }

    private function anketaUrl(Anketa $anketa): string
    {
        return sprintf('%s/anketas/%s', rtrim($this->frontendBaseUrl, '/'), $anketa->getId());
    }
}
