<?php

namespace App\Anketa;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;

/**
 * Serializes anketas and goals into explicit, typed associative arrays for API responses,
 * keeping ciphertext boundary reflection tests (SerializationBoundaryTest) green without
 * exposing entity ciphertext properties through generic serialization.
 */
class AnketaPresenter
{
    /**
     * @return array{id: string, goalUuid: string, authorId: string, title: string,
     *     description: string|null, targetDate: string|null, status: string, createdAt: string}
     */
    public function serializeGoal(Goal $goal): array
    {
        return [
            'id' => $goal->getId(),
            'goalUuid' => $goal->getGoalUuid(),
            'authorId' => $goal->getAuthor()->getId(),
            'title' => $goal->getTitle(),
            'description' => $goal->getDescription(),
            'targetDate' => $goal->getTargetDate()?->format('Y-m-d'),
            'status' => $goal->getStatus(),
            'createdAt' => $goal->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @return array{id: string, myRole: string, counterpartId: string, counterpartEmail: string,
     *     counterpartName: string, meetingDate: string, myPublishedAt: string|null, counterpartPublishedAt: string|null,
     *     archivedAt: string|null, missed: bool, periodicityDays: int|null, counterpartKeyOutdated: bool,
     *     counterpartDeleted: bool, formVersion: int, templateKey: string}
     */
    public function summarize(Anketa $anketa, User $user): array
    {
        $isEmployee = $anketa->isEmployee($user);
        $counterpart = $isEmployee ? $anketa->getManager() : $anketa->getEmployee();

        return [
            'id' => $anketa->getId(),
            'myRole' => $isEmployee ? 'employee' : 'manager',
            'counterpartId' => $counterpart->getId(),
            'counterpartEmail' => $counterpart->getEmail(),
            'counterpartName' => $counterpart->getDisplayName(),
            'meetingDate' => $anketa->getMeetingDate()->format(\DATE_ATOM),
            'myPublishedAt' => ($isEmployee ? $anketa->getEmployeePublishedAt() : $anketa->getManagerPublishedAt())?->format(\DATE_ATOM),
            'counterpartPublishedAt' => ($isEmployee ? $anketa->getManagerPublishedAt() : $anketa->getEmployeePublishedAt())?->format(\DATE_ATOM),
            'archivedAt' => $anketa->getArchivedAt()?->format(\DATE_ATOM),
            'missed' => $anketa->isMissed(),
            'periodicityDays' => $anketa->getPeriodicityDays(),
            'counterpartKeyOutdated' => $this->isKeyOutdated($anketa, $counterpart),
            'counterpartDeleted' => null !== $counterpart->getDeletedAt(),
            'formVersion' => $anketa->getFormVersion(),
            'templateKey' => $anketa->getTemplateKey(),
        ];
    }

    /**
     * @param Goal[] $goals
     *
     * @return array{id: string, myRole: string, counterpartId: string, counterpartEmail: string,
     *     counterpartName: string, meetingDate: string, myPublishedAt: string|null, counterpartPublishedAt: string|null,
     *     archivedAt: string|null, missed: bool, periodicityDays: int|null, counterpartKeyOutdated: bool,
     *     counterpartDeleted: bool, formVersion: int, templateKey: string, mySealedKey: string, counterpartPublicKey: string,
     *     employeeBlob: string|null, employeePublishedAt: string|null, employeeBlobVersion: int,
     *     managerBlob: string|null, managerPublishedAt: string|null, managerBlobVersion: int,
     *     commentsBlob: string|null, commentsVersion: int,
     *     outcomesBlob: string|null, outcomesVersion: int, goals: list<array{id: string, goalUuid: string,
     *     authorId: string, title: string, description: string|null, targetDate: string|null, status: string,
     *     createdAt: string}>, goalCheckpointsBlob: string|null, goalCheckpointsVersion: int}
     */
    public function serializeDetail(Anketa $anketa, User $user, array $goals): array
    {
        $counterpart = $anketa->isEmployee($user) ? $anketa->getManager() : $anketa->getEmployee();

        return [
            ...$this->summarize($anketa, $user),
            'mySealedKey' => $anketa->sealedKeyFor($user),
            // Needed client-side to seal the auto-recreated next anketa's key on archive
            // (Phase 6d) without a separate /api/users round trip — public keys aren't secret.
            'counterpartPublicKey' => $counterpart->getPublicKey(),
            'employeeBlob' => $anketa->getEmployeeBlob(),
            'employeePublishedAt' => $anketa->getEmployeePublishedAt()?->format(\DATE_ATOM),
            'employeeBlobVersion' => $anketa->getEmployeeBlobVersion(),
            'managerBlob' => $anketa->getManagerBlob(),
            'managerPublishedAt' => $anketa->getManagerPublishedAt()?->format(\DATE_ATOM),
            'managerBlobVersion' => $anketa->getManagerBlobVersion(),
            'commentsBlob' => $anketa->getCommentsBlob(),
            'commentsVersion' => $anketa->getCommentsVersion(),
            'outcomesBlob' => $anketa->getOutcomesBlob(),
            'outcomesVersion' => $anketa->getOutcomesVersion(),
            'goals' => array_values(array_map(fn (Goal $goal) => $this->serializeGoal($goal), $goals)),
            'goalCheckpointsBlob' => $anketa->getGoalCheckpointsBlob(),
            'goalCheckpointsVersion' => $anketa->getGoalCheckpointsVersion(),
        ];
    }

    /**
     * @return array{id: string, myRole: string, counterpartId: string, counterpartEmail: string,
     *     counterpartName: string, meetingDate: string, myPublishedAt: string|null, counterpartPublishedAt: string|null,
     *     archivedAt: string|null, missed: bool, periodicityDays: int|null, counterpartKeyOutdated: bool,
     *     counterpartDeleted: bool, formVersion: int, employeeBlobVersion: int, managerBlobVersion: int,
     *     commentsVersion: int, outcomesVersion: int, goalCheckpointsVersion: int}
     */
    public function serializeLiveState(Anketa $anketa, User $user): array
    {
        $state = [
            ...$this->summarize($anketa, $user),
            'employeeBlobVersion' => $anketa->getEmployeeBlobVersion(),
            'managerBlobVersion' => $anketa->getManagerBlobVersion(),
            'commentsVersion' => $anketa->getCommentsVersion(),
            'outcomesVersion' => $anketa->getOutcomesVersion(),
            'goalCheckpointsVersion' => $anketa->getGoalCheckpointsVersion(),
        ];
        // templateKey is immutable once an anketa is created, so it has nothing to poll
        // for — dropped explicitly rather than left in as wasted payload on every 4s
        // tick. summarize() still returns it (serializeDetail() wants it), so it has to
        // be removed here rather than never added in the first place. formVersion is
        // equally immutable and stays in this response regardless — a pre-existing part
        // of this endpoint's shape this issue doesn't touch, not a precedent this
        // exclusion is claiming to follow.
        unset($state['templateKey']);

        return $state;
    }

    /**
     * True when $participant's public key has changed (password-reset plan, part 2)
     * since their side of $anketa's sealed key was last set — i.e. their copy of the
     * anketa key was sealed to a public key that's no longer current, so whoever's
     * looking at this (the *other* participant, whose own key is unaffected) can offer
     * to re-seal it. Self-correcting: resealKeyFor() bumps the anketa-side timestamp
     * past the reset, a later reset moves publicKeyUpdatedAt forward again.
     */
    public function isKeyOutdated(Anketa $anketa, User $participant): bool
    {
        $publicKeyUpdatedAt = $participant->getPublicKeyUpdatedAt();

        return null !== $publicKeyUpdatedAt && $publicKeyUpdatedAt > $anketa->sealedKeyUpdatedAtFor($participant);
    }
}
