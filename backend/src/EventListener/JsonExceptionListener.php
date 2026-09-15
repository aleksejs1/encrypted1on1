<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every controller in this app is a JSON API, but Symfony's default error
 * handling renders thrown exceptions as an HTML page, not JSON — only responses
 * built by hand as JsonResponse were actually JSON. Found via the Phase 6c e2e
 * script hitting a real 403: response body was an HTML comment, not `{"error": ...}`,
 * which broke the frontend's ApiError (falls back to a generic status text
 * instead of the real message). This makes every /api/ route consistent, not
 * just the new ones.
 *
 * This listener standardizes error responses across all /api/* routes:
 * - HttpExceptionInterface instances keep their status code, headers, and message.
 * - Validation errors (MapRequestPayload / ValidationFailedException) are standardized
 *   to HTTP 400 Bad Request with an error message and structured violations array.
 * - All other unhandled \Throwable instances (e.g. database deadlocks, PDOException,
 *   TypeError, unexpected runtime errors) are formatted as HTTP 500 JSON with a
 *   safe, localized error message ("errors.internal_server_error"), avoiding leaking
 *   internal stack traces or database queries in production.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class JsonExceptionListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof HttpExceptionInterface) {
            $validationException = null;
            for ($curr = $exception->getPrevious(); null !== $curr; $curr = $curr->getPrevious()) {
                if ($curr instanceof ValidationFailedException) {
                    $validationException = $curr;
                    break;
                }
            }

            $statusCode = $exception->getStatusCode();
            if (Response::HTTP_UNPROCESSABLE_ENTITY === $statusCode || null !== $validationException) {
                $statusCode = Response::HTTP_BAD_REQUEST;
            }

            $message = '' !== $exception->getMessage() ? $exception->getMessage() : 'An error occurred.';
            $payload = ['error' => $message];

            if (null !== $validationException) {
                $violations = [];
                foreach ($validationException->getViolations() as $violation) {
                    $violations[] = [
                        'property' => $violation->getPropertyPath(),
                        'message' => (string) $violation->getMessage(),
                    ];
                }
                $payload['violations'] = $violations;
            }

            $event->setResponse(new JsonResponse(
                $payload,
                $statusCode,
                $exception->getHeaders(),
            ));

            return;
        }

        $message = $this->translator->trans(
            'errors.internal_server_error',
            locale: $event->getRequest()->getLocale(),
        );

        $event->setResponse(new JsonResponse(
            ['error' => $message],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }
}
