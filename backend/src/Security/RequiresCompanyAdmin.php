<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * The company-admin gate (401 unauthenticated, 403 non-admin) shared by
 * every controller scoped to the requesting admin's own company —
 * `AdminController` and `AdminReportController` at the time of writing. A
 * trait, not a service, since the two call sites just need the same
 * private method, not a collaborator to inject. Deliberately *not*
 * declaring `$authSession`/`$translator` properties here — both using
 * classes already have them as constructor-promoted properties of the
 * same name/type, and a trait redeclaring them would conflict; this trait
 * just assumes they're there (same "trait needs the host class to already
 * have X" contract PHP traits commonly use). Deliberately not shared with
 * `PlatformAdminController` — its `requirePlatformAdmin()` checks a
 * different, platform-wide flag (`isPlatformAdmin()`,
 * `errors.platform_admin_only`), not this one.
 */
trait RequiresCompanyAdmin
{
    private function requireAdmin(Request $request): User
    {
        $user = $this->authSession->getCurrentUser($request);
        if (null === $user) {
            throw new UnauthorizedHttpException('', $this->translator->trans('errors.not_authenticated'));
        }
        if (!$user->isAdmin()) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.admin_only'));
        }

        return $user;
    }
}
