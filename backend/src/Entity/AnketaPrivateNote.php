<?php

namespace App\Entity;

use App\Repository\AnketaPrivateNoteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One participant's private notes on one anketa (GitHub issues #132, #136): readable
 * only by the author, never by the counterpart, the server or an admin.
 *
 * The client wraps a random notes key with an authenticated self-box that only the
 * author's own keypair can open (`encryptedNotesKey`), and encrypts the notes with it,
 * bound to this anketa and author as AEAD associated data (`notesBlob`). The server
 * only stores and versions the two: an overwrite is a conditional UPDATE in
 * AnketaPrivateNoteRepository::overwriteIfVersion(), so this entity has no setters.
 *
 * A separate table rather than columns on Anketa, so no shared serializer
 * (AnketaPresenter's detail/bulk/live-state payloads) can ever carry the other side's
 * notes: every read is by anketa *and* the requester as author. `company` is
 * denormalized from the anketa so CompanyFilter scopes this entity like Anketa itself
 * (docs/architecture-invariants.md §3).
 */
#[ORM\Entity(repositoryClass: AnketaPrivateNoteRepository::class)]
#[ORM\Table(name: 'anketa_private_notes')]
#[ORM\UniqueConstraint(name: 'uniq_anketa_private_notes_anketa_author', columns: ['anketa_id', 'author_id'])]
class AnketaPrivateNote
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Anketa::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Anketa $anketa;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $author;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Company $company;

    /** The notes key, in an authenticated box from the author to themselves (nonce || box, base64). */
    #[ORM\Column(type: 'text')]
    private string $encryptedNotesKey;

    #[ORM\Column(type: 'text')]
    private string $notesBlob;

    #[ORM\Column(type: 'integer')]
    private int $version;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Anketa $anketa, User $author, string $encryptedNotesKey, string $notesBlob)
    {
        // The controller already checks this (findAccessible()). Checked here too so no
        // other caller can store notes for a non-participant, stamped with the anketa's
        // company — the same kind of guard as Anketa's own same-company check.
        if (!$anketa->isParticipant($author)) {
            throw new \InvalidArgumentException('Only a participant of the anketa can author its private notes.');
        }
        $this->id = Uuid::v7()->toRfc4122();
        $this->anketa = $anketa;
        $this->author = $author;
        $this->company = $anketa->getCompany();
        $this->encryptedNotesKey = $encryptedNotesKey;
        $this->notesBlob = $notesBlob;
        $this->version = 1;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAnketa(): Anketa
    {
        return $this->anketa;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getEncryptedNotesKey(): string
    {
        return $this->encryptedNotesKey;
    }

    public function getNotesBlob(): string
    {
        return $this->notesBlob;
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
