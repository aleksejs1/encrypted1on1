<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The identical "optional, trimmed, capped at User::$displayName's own column length"
 * validation needed by both ActivationController::complete() (setting it at
 * registration) and AuthController::setDisplayName() (changing it later) — same
 * "real, mechanical shared call site" reasoning as RateLimitResponse.
 */
final class DisplayNameField
{
    public const MAX_LENGTH = 255;

    /**
     * C0/C1 controls, Unicode bidi-control characters (LRM/RLM, the LRE/RLE/PDF/LRO/RLO
     * embedding/override family, the LRI/RLI/FSI/PDI isolate family), and zero-width/BOM
     * characters — stripped rather than rejected, since this value is echoed verbatim
     * throughout the UI (header, the counterpart-picker typeahead another user clicks to
     * choose who to share a new anketa with, admin panels, comment author tags) and none
     * of those characters have any legitimate reason to appear in a person's name. Left
     * unstripped, a bidi override could visually reorder the name to impersonate a
     * different real name in that picker.
     */
    private const STRIP_PATTERN = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    public static function parse(mixed $raw, TranslatorInterface $translator): string|JsonResponse
    {
        if (!\is_string($raw)) {
            return new JsonResponse(['error' => $translator->trans('errors.missing_or_invalid_field', ['%field%' => 'displayName'])], 400);
        }
        // preg_replace() returns null on a PCRE failure — malformed UTF-8 (e.g. an
        // unpaired surrogate that survived JSON decoding) is the only realistic cause
        // here, given the /u modifier. Treated as invalid input rather than silently
        // coerced to '', so a bad value is rejected instead of silently discarded.
        $stripped = preg_replace(self::STRIP_PATTERN, '', $raw);
        if (null === $stripped) {
            return new JsonResponse(['error' => $translator->trans('errors.missing_or_invalid_field', ['%field%' => 'displayName'])], 400);
        }
        $displayName = trim($stripped);
        // mb_strlen, not strlen — the column is VARCHAR(255) *characters* (MySQL's
        // utf8mb4 counts characters, not bytes; SQLite doesn't enforce length at all),
        // so a byte count would reject legitimate Cyrillic/Latvian names well under
        // the real limit (each such character is 2 bytes in UTF-8).
        if (mb_strlen($displayName) > self::MAX_LENGTH) {
            return new JsonResponse(['error' => $translator->trans('errors.display_name_too_long', ['%max%' => self::MAX_LENGTH])], 400);
        }

        return $displayName;
    }
}
