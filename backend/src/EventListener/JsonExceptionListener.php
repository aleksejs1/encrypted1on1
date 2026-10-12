<?php

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 * would, but this listener's setResponse() stops the event before it runs (priority 1
 * here makes that order explicit, so a 5xx is logged once, by this class), and without
 * this an unhandled exception left no log line at all unless Sentry was on.
 *
 * What the line carries: the route name and not the URL (activation and password-reset
 * tokens are path segments); never the request body; no stack trace, only where the
 * exception was thrown and, when that is outside src/, the nearest line of src/ that
 * led there. The exception's own message, and that of the exception it wraps, is logged
 * as it is, cut to MESSAGE_LIMIT and without control characters. A database driver or a
 * mail transport can put an email address into its message, so the log can contain one;
 * Sentry, where it is on, gets the same message.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 1)]
class JsonExceptionListener
{
    private const int MESSAGE_LIMIT = 500;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/src/')]
        private readonly string $appSourceDir,
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
                $this->log($event, $statusCode);
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

        $this->log($event, Response::HTTP_INTERNAL_SERVER_ERROR);

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

    private function log(ExceptionEvent $event, int $statusCode): void
    {
        $request = $event->getRequest();
        $exception = $event->getThrowable();
        $route = $request->attributes->get('_route');

        $cause = $exception;
        while (null !== $cause->getPrevious()) {
            $cause = $cause->getPrevious();
        }

        $this->logger->error('API error {status} on {method} {route}: {exception}'.($cause === $exception ? '' : '; caused by {cause}'), [
            'status' => $statusCode,
            'method' => $request->getMethod(),
            'route' => \is_string($route) ? $route : '(no route)',
            'exception' => $this->describe($exception),
            ...($cause === $exception ? [] : ['cause' => $this->describe($cause)]),
        ]);
    }

    /** A string, never the exception itself: a logger given the object may print its trace. */
    private function describe(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        if (mb_strlen($message) > self::MESSAGE_LIMIT) {
            $message = mb_substr($message, 0, self::MESSAGE_LIMIT).'…';
        }
        $origin = $exception->getFile().':'.$exception->getLine();

        if (!str_starts_with($exception->getFile(), $this->appSourceDir)) {
            foreach ($exception->getTrace() as $frame) {
                if (isset($frame['file'], $frame['line']) && str_starts_with($frame['file'], $this->appSourceDir)) {
                    $origin .= ', called from '.$frame['file'].':'.$frame['line'];
                    break;
                }
            }
        }

        // One line, nothing a terminal would act on. The class name is covered too: an
        // anonymous class has a NUL byte in its name. The second pattern is for text
        // that isn't valid UTF-8, which the first one refuses.
        $line = \sprintf('%s: %s at %s', $exception::class, $message, $origin);

        return preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $line)
            ?? (string) preg_replace('/[\x00-\x1F\x7F-\x9F]+/', ' ', $line);
    }
}
