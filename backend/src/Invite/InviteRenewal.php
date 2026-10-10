<?php

namespace App\Invite;

use App\Entity\Company;
use App\Entity\InviteRecord;
use App\Entity\User;
use App\Http\ActivationLinkState;
use App\Repository\ActivationTokenRepository;
use App\Repository\InviteRecordRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Whether an expired invite to an address can be renewed (GitHub issue #169), and how.
 * One rule for both sides: ActivationController asks stateFor() about the expired link
 * the invitee opened, InviteController asks resendableAmong() about the newest rows of
 * the admin's invite list, so the page offers "Request new invitation" exactly when the
 * admin's row offers "Re-send". Both end in decide().
 *
 * A renewal belongs to the address, not to a link: it is recorded on the newest invite
 * to that address in the company, the row /admin/invites re-sends from, so several old
 * links share one cooldown and the admin's "Renewal requested" badge is on that row.
 */
class InviteRenewal
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivationTokenRepository $activationTokenRepository,
        private readonly InviteRecordRepository $inviteRecordRepository,
        private readonly bool $cloudMode,
    ) {
    }

    /**
     * Any account with this address, in any company: users.email is unique app-wide, so
     * a new invite to it could never be completed. Plain SQL, so the CompanyFilter an
     * admin's request runs under doesn't hide another company's account. Callers check
     * this first; stateFor() and decide() assume there is none.
     */
    public function hasAccount(string $email): bool
    {
        return [] !== $this->emailsWithAccount([$email]);
    }

    /**
     * For an expired, unused link to an address with no account: one of Reissued,
     * Signup, None, Requested or Available, or `$withoutInvite` when the company has no
     * invite record for the address at all (the CLI bootstrap and cloud company creation
     * write none).
     *
     * @return array{0: ActivationLinkState, 1: InviteRecord|null, 2: list<User>} the
     *                                                                            state, and for Requested/Available the invite to record the request on and who to ask
     */
    public function stateFor(string $email, Company $company, \DateTimeImmutable $now, ActivationLinkState $withoutInvite = ActivationLinkState::None): array
    {
        if ($this->activationTokenRepository->hasUsableFor($email, $company, $now)) {
            return [ActivationLinkState::Reissued, null, []];
        }

        $inviteRecord = $this->inviteRecordRepository->findNewestFor($email, $company);
        if (null === $inviteRecord) {
            // Restarting a cloud company creation makes a new company, so its newer link
            // is in another one.
            if (ActivationLinkState::CreateCompany === $withoutInvite && $this->activationTokenRepository->hasUsableFor($email, null, $now)) {
                return [ActivationLinkState::Reissued, null, []];
            }

            return [$withoutInvite, null, []];
        }

        return $this->decide($inviteRecord, $now, fn () => $this->activeAdmins($company));
    }

    /**
     * Which of `$inviteRecords` — each the newest, expired invite to its address in
     * `$company` — the admin can re-send: those whose link would offer a renewal. The
     * same rule as stateFor(), in a fixed number of queries for the whole list.
     *
     * @param list<InviteRecord> $inviteRecords
     *
     * @return array<string, true> keyed by InviteRecord id
     */
    public function resendableAmong(array $inviteRecords, Company $company, \DateTimeImmutable $now): array
    {
        $emails = array_values(array_unique(array_map(fn (InviteRecord $inviteRecord) => $inviteRecord->getEmail(), $inviteRecords)));
        $excluded = array_flip([
            ...$this->emailsWithAccount($emails),
            ...$this->activationTokenRepository->emailsWithUsableToken($company, $emails, $now),
        ]);
        $admins = null;
        $activeAdmins = function () use (&$admins, $company): array {
            return $admins ??= $this->activeAdmins($company);
        };

        $resendable = [];
        foreach ($inviteRecords as $inviteRecord) {
            if (isset($excluded[$inviteRecord->getEmail()])) {
                continue;
            }
            [$state] = $this->decide($inviteRecord, $now, $activeAdmins);
            if (ActivationLinkState::Available === $state || ActivationLinkState::Requested === $state) {
                $resendable[$inviteRecord->getId()] = true;
            }
        }

        return $resendable;
    }

    /**
     * The renewal state of an address's newest invite, once there's no account and no
     * usable link. Nothing can be renewed in a suspended company (its admins can't log
     * in), nor for an address outside the company's allowed email domain (changed since
     * the invite; sign-up and InviteController both refuse it). A self-registration
     * while signing up again would work (REGISTRATION_MODE=domain, not Cloud, which
     * turns SignupController off) is renewed that way.
     * Anything else asks whoever can re-invite: the original inviter while their account
     * exists, isn't blocked, and the app still offers them an invite form (admins
     * always, in the admin panel; anyone else only in REGISTRATION_MODE=invite, in
     * Account settings), otherwise the company's active admins — so a self-registration
     * after sign-up closed goes to the admins too.
     *
     * @param \Closure(): list<User> $activeAdmins
     *
     * @return array{0: ActivationLinkState, 1: InviteRecord|null, 2: list<User>}
     */
    private function decide(InviteRecord $inviteRecord, \DateTimeImmutable $now, \Closure $activeAdmins): array
    {
        $company = $inviteRecord->getCompany();
        if (!$this->companyCanStillTake($inviteRecord)) {
            return [ActivationLinkState::None, null, []];
        }

        $inviter = $inviteRecord->getInvitedBy();
        if (null === $inviter) {
            $selfRegistration = $this->selfRegistrationState($inviteRecord);
            if (null !== $selfRegistration) {
                return [$selfRegistration, null, []];
            }
        }

        $recipients = null !== $inviter && $this->canReinvite($inviter, $company) ? [$inviter] : $activeAdmins();
        if ([] === $recipients) {
            return [ActivationLinkState::None, null, []];
        }

        $requestedAt = $inviteRecord->getRenewalRequestedAt();
        $withinCooldown = null !== $requestedAt && $requestedAt > InviteRecord::renewalCooldownStart($now);
        $state = $withinCooldown ? ActivationLinkState::Requested : ActivationLinkState::Available;

        return [$state, $inviteRecord, $recipients];
    }

    /**
     * Signup while signing up again is open; None for an address that isn't a plain
     * email (sign-up only checked its domain suffix, and the admins would get it in an
     * email); otherwise null, and the admins are asked as for an invite.
     */
    private function selfRegistrationState(InviteRecord $inviteRecord): ?ActivationLinkState
    {
        if (!$this->cloudMode && 'domain' === $inviteRecord->getCompany()->getRegistrationMode()) {
            return ActivationLinkState::Signup;
        }

        return false === filter_var($inviteRecord->getEmail(), \FILTER_VALIDATE_EMAIL) ? ActivationLinkState::None : null;
    }

    /**
     * Not suspended (its admins can't log in), and the address still in the allowed
     * email domain (it may have changed since: neither sign-up nor InviteController would
     * accept it any more).
     */
    private function companyCanStillTake(InviteRecord $inviteRecord): bool
    {
        $company = $inviteRecord->getCompany();
        $allowedDomain = $company->getAllowedEmailDomain();

        return !$company->isSuspended()
            && ('' === $allowedDomain || str_ends_with($inviteRecord->getEmail(), '@'.$allowedDomain));
    }

    /** The account still exists, isn't blocked, and the app still gives it an invite form. */
    private function canReinvite(User $inviter, Company $company): bool
    {
        return null === $inviter->getDeletedAt() && !$inviter->isBlocked()
            && ($inviter->isAdmin() || 'invite' === $company->getRegistrationMode());
    }

    /**
     * @param list<string> $emails
     *
     * @return list<string>
     */
    private function emailsWithAccount(array $emails): array
    {
        if ([] === $emails) {
            return [];
        }

        /** @var list<string> $found */
        $found = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT email FROM users WHERE email IN (?)',
            [$emails],
            [ArrayParameterType::STRING],
        );

        return $found;
    }

    /** @return list<User> */
    private function activeAdmins(Company $company): array
    {
        /** @var list<User> $admins */
        $admins = $this->entityManager->getRepository(User::class)
            ->findBy(['company' => $company, 'isAdmin' => true, 'isBlocked' => false, 'deletedAt' => null]);

        return $admins;
    }
}
