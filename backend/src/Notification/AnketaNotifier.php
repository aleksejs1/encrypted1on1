<?php

namespace App\Notification;

use App\Entity\Anketa;
use App\Entity\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every email is translated into the *recipient's* saved `User::locale`
 * (Phase 6i), never the sender's or the current request's — a reminder
 * command has no "current request" locale to speak of, and
 * `notifyAnketaCreated()`'s creator and recipient can easily use the app in
 * different languages. Bodies stay plain text (no Twig, see the Phase 6e
 * plan) — `trans()`'s own `%placeholder%` substitution is all the
 * templating this needs, and it's exactly as auditable as the hardcoded
 * strings this replaced: still no code path that can interpolate anketa
 * content, since the translator only ever sees the metadata passed in below.
 */
class AnketaNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly string $frontendBaseUrl,
        private readonly string $mailerFrom,
    ) {
    }

    public function notifyAnketaCreated(Anketa $anketa, User $recipient, User $creator): void
    {
        $this->send($recipient, 'email.anketa_created', [
            '%creator%' => $creator->getEmail(),
            '%date%' => $this->formatDate($anketa),
            '%url%' => $this->anketaUrl($anketa),
        ]);
    }

    public function notifyMeetingTomorrow(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        return $this->sendReminder('email.meeting_tomorrow', $anketa, $recipient, $counterpart);
    }

    public function notifyNotFilledOut(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        return $this->sendReminder('email.not_filled_out', $anketa, $recipient, $counterpart);
    }

    /** Friday's reminder for a Monday meeting (GitHub issue #167) — "tomorrow" would be wrong. */
    public function notifyMeetingMonday(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        return $this->sendReminder('email.meeting_monday', $anketa, $recipient, $counterpart);
    }

    /** Friday's reminder for a Monday meeting (GitHub issue #167) — "tomorrow" would be wrong. */
    public function notifyNotFilledOutMonday(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        return $this->sendReminder('email.not_filled_out_monday', $anketa, $recipient, $counterpart);
    }

    /**
     * Gated by User::wantsMeetingReminders() — unlike notifyAnketaCreated(), which is always
     * mandatory. Returns false only when the mail transport failed (already logged), so
     * SendRemindersCommand can leave the reminder due for a retry; an opted-out recipient
     * counts as done.
     */
    private function sendReminder(string $key, Anketa $anketa, User $recipient, User $counterpart): bool
    {
        if (!$recipient->wantsMeetingReminders()) {
            return true;
        }

        return $this->send($recipient, $key, [
            '%counterpart%' => $counterpart->getEmail(),
            '%date%' => $this->formatDate($anketa),
            '%url%' => $this->anketaUrl($anketa),
        ]);
    }

    /**
     * @param array<string, string> $params
     *
     * @return bool false if the mail transport failed, which is logged rather than thrown
     */
    private function send(User $recipient, string $key, array $params): bool
    {
        $locale = $recipient->getLocale();
        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($recipient->getEmail())
            ->subject($this->translator->trans("$key.subject", $params, null, $locale))
            ->text($this->translator->trans("$key.body", $params, null, $locale));

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send notification email to %s: %s', $recipient->getEmail(), $e->getMessage()));

            return false;
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
