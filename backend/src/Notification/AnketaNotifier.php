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
            '%creator%' => $this->nameOf($creator),
            '%date%' => $this->formatDate($anketa->getMeetingDate()),
            '%url%' => $this->anketaUrl($anketa),
        ]);
    }

    /**
     * Tells the counterpart a meeting moved to another day (GitHub issue #200). Mandatory,
     * like notifyAnketaCreated(), not gated by User::wantsMeetingReminders(): see
     * docs/decisions/2026-10-03-email-copy-single-reminder-reschedule-notice.md.
     */
    public function notifyMeetingRescheduled(Anketa $anketa, User $recipient, User $actor, \DateTimeImmutable $previousDate): void
    {
        $this->send($recipient, 'email.meeting_rescheduled', [
            '%actor%' => $this->nameOf($actor),
            '%old_date%' => $this->formatDate($previousDate),
            '%date%' => $this->formatDate($anketa->getMeetingDate()),
            '%url%' => $this->anketaUrl($anketa),
        ]);
    }

    public function notifyMeetingTomorrow(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        return $this->sendReminder('email.meeting_tomorrow', $anketa, $recipient, $counterpart);
    }

    /** Friday's reminder for a Monday meeting (GitHub issue #167) — "tomorrow" would be wrong. */
    public function notifyMeetingMonday(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        return $this->sendReminder('email.meeting_monday', $anketa, $recipient, $counterpart);
    }

    /**
     * Asks whether a meeting that is past its day and still open happened (GitHub issue
     * #202), with one link to close it and one to move it; the fragments are read by
     * frontend/src/anketa/followUpLinks.ts. A one-off has no next meeting to schedule, so
     * its copy says only "close". Gated and reported like the reminders: see sendReminder().
     */
    public function notifyMeetingFollowUp(Anketa $anketa, User $recipient, User $counterpart): bool
    {
        // A blocked account can't log in, so it has no use for links to the meeting.
        if (!$recipient->wantsMeetingReminders() || $recipient->isBlocked()) {
            return true;
        }
        $url = $this->anketaUrl($anketa);

        return $this->send($recipient, 'email.meeting_follow_up', [
            '%counterpart%' => $this->nameOf($counterpart),
            '%date%' => $this->formatDate($anketa->getMeetingDate()),
            '%close_url%' => $url.'#close',
            '%reschedule_url%' => $url.'#reschedule',
        ], $anketa->isOneOff() ? 'body_one_off' : 'body');
    }

    /**
     * One email per recipient (GitHub issue #200): a recipient whose side isn't published
     * gets the same reminder with one more line (`body_not_published`), not a second email.
     *
     * Gated by User::wantsMeetingReminders() — unlike notifyAnketaCreated() and
     * notifyMeetingRescheduled(), which are always mandatory. Returns false only when the
     * mail transport failed (already logged), so SendRemindersCommand can leave the
     * reminder due for a retry; an opted-out recipient counts as done.
     */
    private function sendReminder(string $key, Anketa $anketa, User $recipient, User $counterpart): bool
    {
        if (!$recipient->wantsMeetingReminders()) {
            return true;
        }

        return $this->send($recipient, $key, [
            '%counterpart%' => $this->nameOf($counterpart),
            '%date%' => $this->formatDate($anketa->getMeetingDate()),
            '%url%' => $this->anketaUrl($anketa),
        ], $anketa->isPublished($recipient) ? 'body' : 'body_not_published');
    }

    /**
     * @param array<string, string> $params
     *
     * @return bool false if the mail transport failed, which is logged rather than thrown
     */
    private function send(User $recipient, string $key, array $params, string $body = 'body'): bool
    {
        $locale = $recipient->getLocale();
        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($recipient->getEmail())
            ->subject($this->translator->trans("$key.subject", $params, null, $locale))
            ->text($this->translator->trans("$key.$body", $params, null, $locale));

        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send notification email to %s: %s', $recipient->getEmail(), $e->getMessage()));

            return false;
        }
    }

    /**
     * "Display name (email)", or the email alone for someone who never set a name. The
     * email stays: a display name is self-chosen, and an email naming only "the CEO"
     * from this instance's own sender address would be an impersonation tool.
     */
    private function nameOf(User $user): string
    {
        return '' !== $user->getDisplayName()
            ? sprintf('%s (%s)', $user->getDisplayName(), $user->getEmail())
            : $user->getEmail();
    }

    private function formatDate(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }

    private function anketaUrl(Anketa $anketa): string
    {
        return sprintf('%s/anketas/%s', rtrim($this->frontendBaseUrl, '/'), $anketa->getId());
    }
}
