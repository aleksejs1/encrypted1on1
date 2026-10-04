<?php

namespace App\Anketa;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown by AnketaLifecycleService::archive() when the caller named the topics version
 * its carried-forward topics were built from and the list has changed since (GitHub
 * issue #206). Nothing was archived. AnketaController::archive() catches it to answer
 * with the current list, so the client can rebuild the carry-forward and archive again.
 * A 409 in its own right, for the same reason as AnketaAlreadyArchivedException.
 */
class AnketaTopicsChangedException extends ConflictHttpException
{
    public function __construct()
    {
        // Same wording as errors.topics_conflict's English; see
        // AnketaAlreadyArchivedException. TranslationConsistencyTest keeps them in sync.
        parent::__construct('Topics changed since you last read them.');
    }
}
