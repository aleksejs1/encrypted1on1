<?php

namespace App\Dto;

use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Shared helper for the #[Assert\Callback] validators in this directory's DTOs.
 * Symfony's validator defaults a Callback-built violation's message to its own
 * `validators` translation domain, not this app's own `messages` domain where
 * `errors.*` actually lives — every call site needs `->setTranslationDomain('messages')`
 * to pick up the app's real translations instead of silently falling back to
 * untranslated English. One shared call site instead of repeating that chain (and
 * risking someone forgetting it) at every `buildViolation()` across these DTOs.
 */
final class DtoViolation
{
    /** @param array<string, string> $parameters */
    public static function add(ExecutionContextInterface $context, string $path, string $translationKey, array $parameters = []): void
    {
        $builder = $context->buildViolation($translationKey)
            ->setTranslationDomain('messages')
            ->atPath($path);

        foreach ($parameters as $name => $value) {
            $builder->setParameter($name, $value);
        }

        $builder->addViolation();
    }
}
