<?php

namespace App\Tests\Unit\Entity;

use App\Entity\Anketa;
use App\Entity\AnketaPrivateNote;
use App\Entity\Company;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class AnketaPrivateNoteTest extends TestCase
{
    private Company $company;
    private User $employee;
    private User $manager;
    private Anketa $anketa;

    protected function setUp(): void
    {
        $this->company = new Company('Test Co');
        $this->employee = new User('employee@example.com', 'hash', 'pub', 'enc', $this->company);
        $this->manager = new User('manager@example.com', 'hash', 'pub', 'enc', $this->company);
        $this->anketa = new Anketa($this->employee, $this->manager, new \DateTimeImmutable('+1 day'), 'sealed-e', 'sealed-m', 30);
    }

    public function testStartsAtVersionOneWithTheGivenKeyAndBlob(): void
    {
        $note = new AnketaPrivateNote($this->anketa, $this->manager, 'key', 'blob');

        self::assertSame(1, $note->getVersion());
        self::assertSame($this->manager, $note->getAuthor());
        self::assertSame('key', $note->getEncryptedNotesKey());
        self::assertSame('blob', $note->getNotesBlob());
    }

    public function testRejectsAnAuthorWhoIsNotAParticipant(): void
    {
        $outsider = new User('outsider@example.com', 'hash', 'pub', 'enc', $this->company);

        $this->expectException(\InvalidArgumentException::class);
        new AnketaPrivateNote($this->anketa, $outsider, 'key', 'blob');
    }
}
