<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * PUT /api/anketas/{id}/private-notes — see AnketaPrivateNote and GitHub issue #132 §5.2.
 */
readonly class SavePrivateNotesRequest
{
    /**
     * The cap on the encrypted blob, measured on what's sent (about 190 KB of plaintext).
     * The client measures the same encoded length before saving, so this is a backstop.
     */
    public const int MAX_NOTES_BLOB_LENGTH = 262_144;

    /** A 32-byte key in an authenticated box: nonce (24) + MAC (16) + key (32) = 72 bytes, base64. */
    public const int ENCRYPTED_NOTES_KEY_BYTES = 72;

    /** Its base64 length: 96. Exact, with no padding, because 72 is a multiple of 3. */
    private const int ENCRYPTED_NOTES_KEY_LENGTH = self::ENCRYPTED_NOTES_KEY_BYTES / 3 * 4;

    public function __construct(
        /**
         * The user the client encrypted these notes for. Must be the session's own user,
         * so a stale tab on a shared browser can never write one user's notes into
         * another user's account (§6.3).
         */
        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public ?string $authorId = null,

        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public ?string $encryptedNotesKey = null,

        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public ?string $notesBlob = null,

        #[Assert\NotNull]
        #[Assert\Type('int')]
        #[Assert\PositiveOrZero]
        public ?int $expectedVersion = null,
    ) {
    }

    // A bare #[Assert\Length] would use Symfony's own validator translation domain;
    // DtoViolation::add() gives an app errors.* key, translated in every locale.
    #[Assert\Callback]
    public function validateNotesBlobLength(ExecutionContextInterface $context): void
    {
        if (\strlen((string) $this->notesBlob) > self::MAX_NOTES_BLOB_LENGTH) {
            DtoViolation::add($context, 'notesBlob', 'errors.private_notes_too_large');
        }
    }

    #[Assert\Callback]
    public function validateEncryptedNotesKey(ExecutionContextInterface $context): void
    {
        // Blank or missing is NotBlank's violation, not this one's.
        if (null === $this->encryptedNotesKey || '' === $this->encryptedNotesKey) {
            return;
        }
        // The exact length also rejects whitespace, which base64_decode() skips even in
        // strict mode.
        $decoded = base64_decode($this->encryptedNotesKey, true);
        if (self::ENCRYPTED_NOTES_KEY_LENGTH !== \strlen($this->encryptedNotesKey) || false === $decoded || self::ENCRYPTED_NOTES_KEY_BYTES !== \strlen($decoded)) {
            DtoViolation::add($context, 'encryptedNotesKey', 'errors.private_notes_key_invalid');
        }
    }
}
