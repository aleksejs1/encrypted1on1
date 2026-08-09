<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every controller in this app is a JSON API, but Symfony's default error
 * handling renders thrown HttpException(s) (403/404/409/etc., used
 * throughout — see findAccessible(), AnketaController) as an HTML page, not
 * JSON — only responses built by hand as JsonResponse were actually JSON.
 * Found via the Phase 6c e2e script hitting a real 403: response body was
 * an HTML comment, not `{"error": ...}`, which broke the frontend's
 * ApiError (falls back to a generic status text instead of the real
 * message). This makes every /api/ route consistent, not just the new ones.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class JsonExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => $exception->getMessage() ?: 'An error occurred.'],
            $exception->getStatusCode(),
            $exception->getHeaders(),
        ));
    }
}
