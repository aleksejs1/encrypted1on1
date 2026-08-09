<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Anketa;
use App\Entity\Goal;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class GoalTest extends TestCase
{
    public function testIsAuthorTrueForTheCreatingUser(): void
    {
        $author = new User('author@example.com', 'hash', 'pub', 'enc');
        $goal = new Goal('goal-uuid-1', $this->makeAnketa($author), $author, 'Ship it', null, null);

        self::assertTrue($goal->isAuthor($author));
    }

    public function testIsAuthorFalseForAnyoneElse(): void
    {
        $author = new User('author@example.com', 'hash', 'pub', 'enc');
        $someoneElse = new User('other@example.com', 'hash', 'pub', 'enc');
        $goal = new Goal('goal-uuid-1', $this->makeAnketa($author), $author, 'Ship it', null, null);

        self::assertFalse($goal->isAuthor($someoneElse));
    }

    public function testDefaultsToInProgressStatus(): void
    {
        $author = new User('author@example.com', 'hash', 'pub', 'enc');
        $goal = new Goal('goal-uuid-1', $this->makeAnketa($author), $author, 'Ship it', null, null);

        self::assertSame(Goal::STATUS_IN_PROGRESS, $goal->getStatus());
    }

    private function makeAnketa(User $employee): Anketa
    {
        $manager = new User('manager@example.com', 'hash', 'pub', 'enc');

        return new Anketa($employee, $manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30);
    }
}
