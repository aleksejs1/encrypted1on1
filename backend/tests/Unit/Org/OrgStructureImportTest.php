<?php

namespace App\Tests\Unit\Org;

use App\Entity\Company;
use App\Entity\User;
use App\Org\OrgImportPlan;
use App\Org\OrgImportProblem;
use App\Org\OrgStructure;
use App\Org\OrgStructureImport;
use PHPUnit\Framework\TestCase;

class OrgStructureImportTest extends TestCase
{
    private Company $company;
    /** @var array<string, User> by first name */
    private array $users = [];

    protected function setUp(): void
    {
        $this->company = new Company('Test Co');
        foreach (['anna', 'boris', 'clara', 'dana'] as $name) {
            $this->users[$name] = new User("{$name}@example.com", 'hash', 'pub', 'enc', $this->company);
        }
    }

    /** @param list<array{0: string, 1: ?string}> $rows each an employee email and a manager email */
    private function plan(array $rows): OrgImportPlan
    {
        return (new OrgStructureImport(new OrgStructure()))->plan(
            array_values($this->users),
            array_map(static fn (array $row): array => ['employeeEmail' => $row[0], 'managerEmail' => $row[1]], $rows),
        );
    }

    /** @return list<array{0: string, 1: ?string}> who gets which manager, by first name */
    private function changes(OrgImportPlan $plan): array
    {
        $name = fn (User $user): string => (string) array_search($user, $this->users, true);

        return array_map(static fn (array $change): array => [$name($change[0]), null === $change[1] ? null : $name($change[1])], $plan->changes);
    }

    /** @return list<array{0: int, 1: OrgImportProblem}> */
    private static function problems(OrgImportPlan $plan): array
    {
        return array_map(static fn (array $p): array => [$p['row'], $p['problem']], $plan->problems);
    }

    public function testValidRowsBecomeChangesAndNothingIsApplied(): void
    {
        $plan = $this->plan([['anna@example.com', 'boris@example.com'], ['clara@example.com', 'boris@example.com']]);

        self::assertSame([['anna', 'boris'], ['clara', 'boris']], $this->changes($plan));
        self::assertSame([], $plan->problems);
        self::assertFalse($plan->isBlocked());
        self::assertSame(['rows' => 2, 'changes' => 2, 'unchanged' => 0, 'warnings' => 0, 'errors' => 0, 'blocking' => 0], $plan->counts());
        self::assertNull($this->users['anna']->getManager());
    }

    public function testEmailsMatchWhateverTheirCaseAndSurroundingSpaces(): void
    {
        $this->users['anna'] = new User('Anna.Smith@Example.COM', 'hash', 'pub', 'enc', $this->company);

        $plan = $this->plan([[' anna.smith@example.com ', 'BORIS@EXAMPLE.COM']]);

        self::assertSame([['anna', 'boris']], $this->changes($plan));
    }

    public function testANullOrEmptyManagerClearsIt(): void
    {
        $this->users['anna']->setManager($this->users['boris']);
        $this->users['clara']->setManager($this->users['boris']);

        $plan = $this->plan([['anna@example.com', null], ['clara@example.com', '  ']]);

        self::assertSame([['anna', null], ['clara', null]], $this->changes($plan));
    }

    public function testPeopleTheRowsDoNotNameKeepTheirManager(): void
    {
        $this->users['dana']->setManager($this->users['boris']);

        $plan = $this->plan([['anna@example.com', 'boris@example.com']]);

        self::assertSame([['anna', 'boris']], $this->changes($plan));
        self::assertSame($this->users['boris'], $this->users['dana']->getManager());
    }

    public function testARowThatRepeatsWhatIsStoredIsUnchangedEvenOnceTheManagerIsBlocked(): void
    {
        $this->users['anna']->setManager($this->users['boris']);
        $this->users['boris']->setBlocked(true);

        $plan = $this->plan([['anna@example.com', 'boris@example.com'], ['clara@example.com', null]]);

        self::assertSame([], $plan->changes);
        self::assertSame([], $plan->problems);
        self::assertSame(2, $plan->counts()['unchanged']);
    }

    public function testAnUnknownEmployeeOrManagerIsAWarningAndTheRowIsSkipped(): void
    {
        $plan = $this->plan([
            ['nobody@example.com', 'boris@example.com'],
            ['anna@example.com', 'ghost@example.com'],
            ['clara@example.com', 'boris@example.com'],
        ]);

        self::assertSame([[0, OrgImportProblem::EmployeeNotFound], [1, OrgImportProblem::ManagerNotFound]], self::problems($plan));
        self::assertSame([['clara', 'boris']], $this->changes($plan));
        self::assertFalse($plan->isBlocked());
        self::assertSame(2, $plan->counts()['warnings']);
    }

    public function testABlockedManagerIsAWarning(): void
    {
        $this->users['boris']->setBlocked(true);

        $plan = $this->plan([['anna@example.com', 'boris@example.com']]);

        self::assertSame([[0, OrgImportProblem::ManagerUnavailable]], self::problems($plan));
        self::assertSame('warning', OrgImportProblem::ManagerUnavailable->severity());
        self::assertSame([], $plan->changes);
    }

    /** A deleted account's address is a placeholder; even written out, it names nobody. */
    public function testADeletedAccountIsNobody(): void
    {
        $this->users['boris']->delete();
        $placeholder = $this->users['boris']->getEmail();

        $plan = $this->plan([['anna@example.com', $placeholder], [$placeholder, 'clara@example.com']]);

        self::assertSame([[0, OrgImportProblem::ManagerNotFound], [1, OrgImportProblem::EmployeeNotFound]], self::problems($plan));
    }

    public function testAnAddressThatMatchesTwoAccountsIsAmbiguous(): void
    {
        $this->users['anna2'] = new User('ANNA@example.com', 'hash', 'pub', 'enc', $this->company);

        $plan = $this->plan([['anna@example.com', 'boris@example.com'], ['clara@example.com', 'Anna@example.com']]);

        self::assertSame([[0, OrgImportProblem::AmbiguousEmail], [1, OrgImportProblem::AmbiguousEmail]], self::problems($plan));
        self::assertSame([], $plan->changes);
    }

    public function testAPersonAsTheirOwnManagerIsARowError(): void
    {
        $plan = $this->plan([['anna@example.com', 'ANNA@example.com'], ['clara@example.com', 'boris@example.com']]);

        self::assertSame([[0, OrgImportProblem::OwnManager]], self::problems($plan));
        self::assertSame('error', OrgImportProblem::OwnManager->severity());
        self::assertSame([['clara', 'boris']], $this->changes($plan));
        self::assertFalse($plan->isBlocked());
    }

    public function testTheSamePersonWithTwoDifferentManagersLosesBothRows(): void
    {
        $plan = $this->plan([
            ['anna@example.com', 'boris@example.com'],
            ['clara@example.com', 'boris@example.com'],
            ['Anna@example.com', 'dana@example.com'],
        ]);

        self::assertSame([[0, OrgImportProblem::ConflictingRows], [2, OrgImportProblem::ConflictingRows]], self::problems($plan));
        self::assertSame([['clara', 'boris']], $this->changes($plan));
        self::assertSame(2, $plan->counts()['errors']);
    }

    public function testClearingAndSettingTheSamePersonConflictToo(): void
    {
        $plan = $this->plan([['anna@example.com', null], ['anna@example.com', 'boris@example.com']]);

        self::assertSame([[0, OrgImportProblem::ConflictingRows], [1, OrgImportProblem::ConflictingRows]], self::problems($plan));
    }

    public function testTheSameRowTwiceCountsOnce(): void
    {
        $plan = $this->plan([['anna@example.com', 'boris@example.com'], ['ANNA@example.com', 'Boris@example.com']]);

        self::assertSame([['anna', 'boris']], $this->changes($plan));
        self::assertSame([], $plan->problems);
        self::assertSame(['rows' => 2, 'changes' => 1, 'unchanged' => 0, 'warnings' => 0, 'errors' => 0, 'blocking' => 0], $plan->counts());
    }

    public function testACycleMadeOnlyByTheRowsBlocksTheImport(): void
    {
        $plan = $this->plan([
            ['anna@example.com', 'boris@example.com'],
            ['boris@example.com', 'anna@example.com'],
            ['clara@example.com', 'dana@example.com'],
        ]);

        self::assertTrue($plan->isBlocked());
        self::assertSame([[0, OrgImportProblem::Cycle], [1, OrgImportProblem::Cycle]], self::problems($plan));
        self::assertSame('blocking', OrgImportProblem::Cycle->severity());
        self::assertSame(2, $plan->counts()['blocking']);
    }

    /** Boris already reports to Clara, who isn't in the rows at all. */
    public function testACycleThroughSomeoneOutsideTheRowsBlocksTheImport(): void
    {
        $this->users['boris']->setManager($this->users['clara']);
        $this->users['anna']->setManager($this->users['boris']);

        $plan = $this->plan([['clara@example.com', 'anna@example.com']]);

        self::assertTrue($plan->isBlocked());
        self::assertSame([[0, OrgImportProblem::Cycle]], self::problems($plan));
    }

    /** Anna reported to Boris; now Boris reports to Anna. A cycle if taken row by row. */
    public function testSwappingTwoPeopleInOneImportIsValid(): void
    {
        $this->users['anna']->setManager($this->users['boris']);

        $plan = $this->plan([['boris@example.com', 'anna@example.com'], ['anna@example.com', null]]);

        self::assertFalse($plan->isBlocked());
        self::assertSame([], $plan->problems);
        self::assertSame([['boris', 'anna'], ['anna', null]], $this->changes($plan));
    }

    public function testARowSkippedForItsOwnSakeDoesNotMakeACycleForTheOthers(): void
    {
        $this->users['boris']->setBlocked(true);

        $plan = $this->plan([['anna@example.com', 'boris@example.com'], ['boris@example.com', 'anna@example.com']]);

        self::assertFalse($plan->isBlocked());
        self::assertSame([[0, OrgImportProblem::ManagerUnavailable]], self::problems($plan));
        self::assertSame([['boris', 'anna']], $this->changes($plan));
    }

    public function testProblemsComeInRowOrder(): void
    {
        $plan = $this->plan([
            ['anna@example.com', 'anna@example.com'],
            ['nobody@example.com', null],
            ['clara@example.com', 'boris@example.com'],
            ['clara@example.com', 'dana@example.com'],
        ]);

        self::assertSame([0, 1, 2, 3], array_column($plan->problems, 'row'));
    }

    public function testAnEmptyImportIsFine(): void
    {
        $plan = $this->plan([]);

        self::assertSame(['rows' => 0, 'changes' => 0, 'unchanged' => 0, 'warnings' => 0, 'errors' => 0, 'blocking' => 0], $plan->counts());
        self::assertFalse($plan->isBlocked());
    }

    /** The file contradicts itself about Anna, whichever of the two addresses exists. */
    public function testRowsDisagreeEvenWhenOneNamesAnUnknownManager(): void
    {
        $plan = $this->plan([['anna@example.com', 'ghost@example.com'], ['anna@example.com', 'boris@example.com']]);

        self::assertSame([[0, OrgImportProblem::ConflictingRows], [1, OrgImportProblem::ConflictingRows]], self::problems($plan));
        self::assertSame([], $plan->changes);
    }

    public function testAProblemWithARepeatedRowIsSaidOfEachOfItsRows(): void
    {
        $this->users['dana']->setBlocked(true);

        $plan = $this->plan([
            ['boris@example.com', 'boris@example.com'],
            ['anna@example.com', 'ghost@example.com'],
            ['clara@example.com', 'dana@example.com'],
            ['Boris@example.com', 'BORIS@example.com'],
            ['anna@example.com', 'Ghost@example.com'],
            ['clara@example.com', 'dana@example.com'],
        ]);

        self::assertSame([
            [0, OrgImportProblem::OwnManager],
            [1, OrgImportProblem::ManagerNotFound],
            [2, OrgImportProblem::ManagerUnavailable],
            [3, OrgImportProblem::OwnManager],
            [4, OrgImportProblem::ManagerNotFound],
            [5, OrgImportProblem::ManagerUnavailable],
        ], self::problems($plan));
    }

    /** What a spreadsheet export leaves around a cell: a byte-order mark, a no-break space, a zero-width space. */
    public function testAddressesAreTrimmedOfInvisibleCharacters(): void
    {
        $plan = $this->plan([["\u{FEFF}anna@example.com\u{00A0}", "\u{200B} boris@example.com\t"], ['clara@example.com', "\u{00A0}"]]);

        self::assertSame([], $plan->problems);
        self::assertSame([['anna', 'boris']], $this->changes($plan));
        self::assertSame(1, $plan->counts()['unchanged']);
    }

    /**
     * Accepted: with Anna's row skipped she keeps her stored manager, and Boris under
     * Anna would then close a loop for real, so the import is blocked although the
     * file by itself has no loop.
     */
    public function testASkippedRowKeepsItsStoredLinkForTheCycleCheck(): void
    {
        $this->users['anna']->setManager($this->users['boris']);

        $plan = $this->plan([['anna@example.com', 'ghost@example.com'], ['boris@example.com', 'anna@example.com']]);

        self::assertTrue($plan->isBlocked());
        self::assertSame([[0, OrgImportProblem::ManagerNotFound], [1, OrgImportProblem::Cycle]], self::problems($plan));
    }
}
