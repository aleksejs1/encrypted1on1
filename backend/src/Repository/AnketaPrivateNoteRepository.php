<?php

namespace App\Repository;

use App\Entity\Anketa;
use App\Entity\AnketaPrivateNote;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Every lookup takes the requester as the author: private notes are only ever read
 * back by the user who wrote them (see AnketaPrivateNote).
 *
 * @extends ServiceEntityRepository<AnketaPrivateNote>
 */
class AnketaPrivateNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AnketaPrivateNote::class);
    }

    public function findOwn(Anketa $anketa, User $author): ?AnketaPrivateNote
    {
        return $this->findOneBy(['anketa' => $anketa, 'author' => $author]);
    }

    /**
     * @return AnketaPrivateNote[]
     */
    public function findAllOwn(User $author): array
    {
        return $this->findBy(['author' => $author]);
    }

    /**
     * Overwrites the author's notes on this anketa only if they're still at
     * `$expectedVersion`, as one conditional UPDATE, and reports whether it did.
     * Atomic, unlike loading the row and comparing in memory: of two concurrent saves
     * against the same version, the database lets only one match (the same approach
     * as AnketaRepository::markArchivedIfOpen()). It also spares each autosave from
     * reading the notes row, blob included, just to check one integer.
     */
    public function overwriteIfVersion(Anketa $anketa, User $author, string $encryptedNotesKey, string $notesBlob, int $expectedVersion): bool
    {
        $affected = $this->getEntityManager()->createQuery(
            'UPDATE '.AnketaPrivateNote::class.' n SET n.encryptedNotesKey = :key, n.notesBlob = :blob, n.version = n.version + 1, n.updatedAt = :now'
            .' WHERE n.anketa = :anketa AND n.author = :author AND n.version = :expectedVersion'
        )
            ->setParameter('key', $encryptedNotesKey)
            ->setParameter('blob', $notesBlob)
            ->setParameter('now', new \DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('anketa', $anketa->getId())
            ->setParameter('author', $author->getId())
            ->setParameter('expectedVersion', $expectedVersion)
            ->execute();

        return 1 === $affected;
    }
}
