<?php

namespace App\Tests\Unit\Org;

use App\Entity\Company;
use App\Entity\User;
use App\Org\OrgStructure;
use App\Org\OrgStructureError;
use App\Org\OrgStructureException;
use PHPUnit\Framework\TestCase;

class OrgStructureTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company('Test Co');
    }

    private function user(string $name, ?Company $company = null): User
    {
        return new User("{$name}@example.com", 'hash', 'pub', 'enc', $company ?? $this->company);
    }

    private function assertRejected(OrgStructureError $expected, User $user, User $manager): void
    {
        try {
            (new OrgStructure())->assign($user, $manager);
            self::fail('Expected the assignment to be rejected.');
        } catch (OrgStructureException $e) {
            self::assertSame($expected, $e->reason);
        }
    }

    public function testAssignSetsTheManager(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');

        (new OrgStructure())->assign($anna, $boris);

        self::assertSame($boris, $anna->getManager());
        self::assertNull($boris->getManager());
    }

    public function testAssignNullClearsTheManager(): void
    {
        $anna = $this->user('anna');
        $anna->setManager($this->user('boris'));

        (new OrgStructure())->assign($anna, null);

        self::assertNull($anna->getManager());
    }

    public function testAPersonCannotBeTheirOwnManager(): void
    {
        $anna = $this->user('anna');

        $this->assertRejected(OrgStructureError::OwnManager, $anna, $anna);
        self::assertNull($anna->getManager());
    }

    public function testADirectCycleIsRejected(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $anna->setManager($boris);

        $this->assertRejected(OrgStructureError::Cycle, $boris, $anna);
        self::assertNull($boris->getManager());
    }

    public function testADeepCycleIsRejected(): void
    {
        [$a, $b, $c, $d] = [$this->user('a'), $this->user('b'), $this->user('c'), $this->user('d')];
        $a->setManager($b);
        $b->setManager($c);
        $c->setManager($d);

        $this->assertRejected(OrgStructureError::Cycle, $d, $a);
    }

    public function testMovingAPersonUnderAColleagueOfTheSameManagerIsAllowed(): void
    {
        [$head, $anna, $boris] = [$this->user('head'), $this->user('anna'), $this->user('boris')];
        $anna->setManager($head);
        $boris->setManager($head);

        (new OrgStructure())->assign($anna, $boris);

        self::assertSame($boris, $anna->getManager());
    }

    public function testABlockedManagerIsRejected(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $boris->setBlocked(true);

        $this->assertRejected(OrgStructureError::ManagerUnavailable, $anna, $boris);
    }

    public function testADeletedManagerIsRejected(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $boris->delete();
        // delete() blocks too; unblocked, only the deletion itself is left to reject it.
        $boris->setBlocked(false);

        $this->assertRejected(OrgStructureError::ManagerUnavailable, $anna, $boris);
    }

    public function testAManagerFromAnotherCompanyIsRejected(): void
    {
        $anna = $this->user('anna');
        $outsider = $this->user('outsider', new Company('Other Co'));

        $this->assertRejected(OrgStructureError::ManagerUnavailable, $anna, $outsider);
    }

    /** A blocked person keeps their own manager, and can be given one: only the manager's side is checked. */
    public function testABlockedPersonCanBeGivenAManager(): void
    {
        $anna = $this->user('anna');
        $anna->setBlocked(true);
        $boris = $this->user('boris');

        (new OrgStructure())->assign($anna, $boris);

        self::assertSame($boris, $anna->getManager());
    }

    /** Two admins at once can store a cycle (no locking): the walk must still end. */
    public function testAnAlreadyStoredCycleDoesNotHangTheCheck(): void
    {
        [$a, $b, $newcomer] = [$this->user('a'), $this->user('b'), $this->user('newcomer')];
        $a->setManager($b);
        $b->setManager($a);

        (new OrgStructure())->assign($newcomer, $a);

        self::assertSame($a, $newcomer->getManager());
    }

    public function testViolationsChangesNothing(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');

        self::assertSame([], (new OrgStructure())->violations([[$anna, $boris]]));
        self::assertNull($anna->getManager());
    }

    /** Anna and Boris trade places: a cycle if checked row by row, fine as a whole. */
    public function testABatchIsCheckedAgainstTheResultingTree(): void
    {
        [$head, $anna, $boris] = [$this->user('head'), $this->user('anna'), $this->user('boris')];
        $anna->setManager($head);
        $boris->setManager($anna);

        self::assertSame([], (new OrgStructure())->violations([[$anna, $boris], [$boris, $head]]));
    }

    public function testACycleMadeOnlyByTwoRowsTogetherRejectsBoth(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');

        self::assertSame(
            [$anna->getId() => OrgStructureError::Cycle, $boris->getId() => OrgStructureError::Cycle],
            (new OrgStructure())->violations([[$anna, $boris], [$boris, $anna]]),
        );
    }

    public function testOnlyTheRowsInACycleAreRejected(): void
    {
        [$anna, $boris, $clara] = [$this->user('anna'), $this->user('boris'), $this->user('clara')];
        $anna->setManager($boris);

        // Clara ends up under the pair, not in their loop.
        self::assertSame(
            [$boris->getId() => OrgStructureError::Cycle],
            (new OrgStructure())->violations([[$boris, $anna], [$clara, $anna]]),
        );
    }

    public function testAPersonListedTwiceKeepsTheLastRow(): void
    {
        [$anna, $boris, $clara] = [$this->user('anna'), $this->user('boris'), $this->user('clara')];
        $boris->setManager($anna);

        // The first row alone would be a cycle; the last one is what counts.
        self::assertSame([], (new OrgStructure())->violations([[$anna, $boris], [$anna, $clara]]));
    }

    public function testADeletedPersonCannotBeGivenAManager(): void
    {
        $anna = $this->user('anna');
        $anna->delete();

        $this->assertRejected(OrgStructureError::PersonDeleted, $anna, $this->user('boris'));
        self::assertNull($anna->getManager());
    }

    public function testADeletedPersonsLeftoverManagerCanStillBeCleared(): void
    {
        $anna = $this->user('anna');
        $anna->delete();
        $anna->setManager($this->user('boris'));

        (new OrgStructure())->assign($anna, null);

        self::assertNull($anna->getManager());
    }

    public function testRepeatingADeletedPersonsLeftoverManagerIsNotAnError(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $anna->delete();
        $anna->setManager($boris);

        self::assertSame([], (new OrgStructure())->violations([[$anna, $boris]]));
    }

    /** Blocking a manager leaves their reports in place, so re-stating the link is not an error. */
    public function testRepeatingTheStoredManagerIsFineEvenOnceTheyAreBlocked(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $anna->setManager($boris);
        $boris->setBlocked(true);

        (new OrgStructure())->assign($anna, $boris);

        self::assertSame($boris, $anna->getManager());
    }

    public function testARepeatedRowIsNotBlamedForACycleAnotherRowCloses(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $anna->setManager($boris);

        self::assertSame(
            [$boris->getId() => OrgStructureError::Cycle],
            (new OrgStructure())->violations([[$anna, $boris], [$boris, $anna]]),
        );
    }

    /** Anna's row is rejected, so it won't be applied, and Boris under Anna closes nothing. */
    public function testARowRejectedForItsOwnSakeIsNotPartOfTheResultingTree(): void
    {
        $anna = $this->user('anna');
        $boris = $this->user('boris');
        $boris->setBlocked(true);

        self::assertSame(
            [$anna->getId() => OrgStructureError::ManagerUnavailable],
            (new OrgStructure())->violations([[$anna, $boris], [$boris, $anna]]),
        );
    }

    public function testAssignAllWritesAValidBatchASwapIncluded(): void
    {
        [$head, $anna, $boris] = [$this->user('head'), $this->user('anna'), $this->user('boris')];
        $anna->setManager($head);
        $boris->setManager($anna);

        (new OrgStructure())->assignAll([[$anna, $boris], [$boris, $head]]);

        self::assertSame($boris, $anna->getManager());
        self::assertSame($head, $boris->getManager());
    }

    public function testAssignAllWritesNothingIfAnyAssignmentIsRefused(): void
    {
        [$anna, $boris, $clara] = [$this->user('anna'), $this->user('boris'), $this->user('clara')];

        try {
            (new OrgStructure())->assignAll([[$clara, $anna], [$boris, $boris]]);
            self::fail('Expected the batch to be refused.');
        } catch (OrgStructureException $e) {
            self::assertSame(OrgStructureError::OwnManager, $e->reason);
        }
        self::assertNull($clara->getManager());
    }
}
