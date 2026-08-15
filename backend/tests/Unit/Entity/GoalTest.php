<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Anketa;
use App\Entity\Company;
use App\Entity\Goal;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class GoalTest extends TestCase
{
    private Company $company;

    protected function setUp(): void
    {
        $this->company = new Company('Test Co');
    }

    public function testIsAuthorTrueForTheCreatingUser(): void
    {
        $author = new User('author@example.com', 'hash', 'pub', 'enc', $this->company);
        $goal = new Goal('goal-uuid-1', $this->makeAnketa($author), $author, 'Ship it', null, null);

        self::assertTrue($goal->isAuthor($author));
    }

    public function testIsAuthorFalseForAnyoneElse(): void
    {
        $author = new User('author@example.com', 'hash', 'pub', 'enc', $this->company);
        $someoneElse = new User('other@example.com', 'hash', 'pub', 'enc', $this->company);
        $goal = new Goal('goal-uuid-1', $this->makeAnketa($author), $author, 'Ship it', null, null);

        self::assertFalse($goal->isAuthor($someoneElse));
    }

    public function testDefaultsToInProgressStatus(): void
    {
        $author = new User('author@example.com', 'hash', 'pub', 'enc', $this->company);
        $goal = new Goal('goal-uuid-1', $this->makeAnketa($author), $author, 'Ship it', null, null);

        self::assertSame(Goal::STATUS_IN_PROGRESS, $goal->getStatus());
    }

    private function makeAnketa(User $employee): Anketa
    {
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc', $this->company);

        return new Anketa($employee, $manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30);
    }
}
