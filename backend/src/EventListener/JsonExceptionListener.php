<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
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
 *
 * It also logs every 5xx it answers. Symfony's own ErrorListener::logKernelException()
 * has the same priority and is registered after this one, and setResponse() stops the
 * event, so without this an unhandled exception left no log line at all unless Sentry
 * was on. The line carries the route name, not the URL (activation and password-reset
 * tokens are path segments), and never the request body or a stack trace (a trace
 * can print argument values).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
class JsonExceptionListener
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        if ($exception instanceof HttpExceptionInterface) {
            $validationException = $this->validationFailure($exception);

            $statusCode = $exception->getStatusCode();
            if (Response::HTTP_UNPROCESSABLE_ENTITY === $statusCode || null !== $validationException) {
                $statusCode = Response::HTTP_BAD_REQUEST;
            }

            if ($statusCode >= Response::HTTP_INTERNAL_SERVER_ERROR) {
                $this->log($event);
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

        $this->log($event);

        $message = $this->translator->trans(
            'errors.internal_server_error',
            locale: $event->getRequest()->getLocale(),
        );

        $event->setResponse(new JsonResponse(
            ['error' => $message],
            Response::HTTP_INTERNAL_SERVER_ERROR,
        ));
    }

    private function validationFailure(\Throwable $exception): ?ValidationFailedException
    {
        for ($curr = $exception->getPrevious(); null !== $curr; $curr = $curr->getPrevious()) {
            if ($curr instanceof ValidationFailedException) {
                return $curr;
            }
        }

        return null;
    }

    private function log(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        $route = $request->attributes->get('_route');

        $this->logger->error('Unhandled exception on {method} {route}: {class}: {message} at {file}:{line}', [
            'method' => $request->getMethod(),
            'route' => \is_string($route) ? $route : '(no route)',
            'class' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'exception' => $exception,
        ]);
    }
}
