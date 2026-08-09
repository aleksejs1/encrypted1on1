<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Checked on every state-changing request, per the spec. Token comes from GET /api/csrf-token. */
class CsrfGuard
{
    private const TOKEN_ID = 'api';

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function assertValid(Request $request): void
    {
        $submitted = $request->headers->get('X-CSRF-Token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, $submitted))) {
            throw new AccessDeniedHttpException($this->translator->trans('errors.invalid_csrf_token'));
        }
    }
}
