<?php

namespace App\Anketa;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown by AnketaLifecycleService::archive() when the anketa turned out to be archived
 * already — by a concurrent request that got there first (GitHub issue #130).
 * AnketaController::archive() catches it to answer with its translated 409 and the
 * state that did get applied. A 409 in its own right, same reasoning as the service's
 * BadRequestHttpException re-checks: a future caller that doesn't catch it still gets
 * a 409 from JsonExceptionListener, not an opaque 500.
 */
class AnketaAlreadyArchivedException extends ConflictHttpException
{
    public function __construct()
    {
        // Same wording as errors.anketa_archived's English; this class has no
        // translator, so only the controller's own 409 is localized.
        parent::__construct('Anketa is archived.');
    }
}
