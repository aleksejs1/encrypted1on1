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

    /**
     * CLOUD_MODE's self-service company creation (CompanyController, Phase B of
     * private/cloud-service-plan.md, not tracked in git) — same shape as notifySignup(),
     * with the new company's own name in the message so the recipient (its first admin)
     * has some confirmation this is the company they meant to create.
     */
    public function notifyCompanySignup(string $email, string $rawToken, string $companyName): void
    {
        $params = [
            '%url%' => sprintf('%s/activate/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken),
            '%company%' => $companyName,
        ];

        $message = (new Email())
            ->from($this->mailerFrom)
            ->to($email)
            ->subject($this->translator->trans('email.company_signup.subject', $params))
            ->text($this->translator->trans('email.company_signup.body', $params));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send company-signup confirmation email to %s: %s', $email, $e->getMessage()));
        }
    }

    /**
     * GitHub issue #169: the invitee opened an expired link and asked for a new one.
     * Sent to whoever can re-invite them (see InviteRenewal::decide()),
     * in that user's own locale. An admin lands on the invites list, where the row has
     * a Re-send button; anyone else on Account settings, with the invite form filled in.
     *
     * @return bool false when the mail transport failed, so the caller can let the
     *              invitee ask again instead of telling them it was sent
     */
    public function notifyInviteRenewalRequested(User $recipient, string $inviteeEmail): bool
    {
        $baseUrl = rtrim($this->frontendBaseUrl, '/');
        $params = [
            '%invitee%' => $inviteeEmail,
            '%url%' => $recipient->isAdmin()
                ? sprintf('%s/admin/invites', $baseUrl)
                : sprintf('%s/account?invite=%s', $baseUrl, rawurlencode($inviteeEmail)),
        ];
        $locale = $recipient->getLocale();

        $message = (new Email())
            ->from($this->mailerFrom)
            ->to($recipient->getEmail())
            ->subject($this->translator->trans('email.invite_renewal_requested.subject', $params, null, $locale))
            ->text($this->translator->trans('email.invite_renewal_requested.body', $params, null, $locale));

        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            error_log(sprintf('Failed to send invite-renewal request email to %s: %s', $recipient->getEmail(), $e->getMessage()));

            return false;
        }

        return true;
    }
}
