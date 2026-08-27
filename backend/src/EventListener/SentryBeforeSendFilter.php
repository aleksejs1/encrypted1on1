<?php

namespace App\EventListener;

use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sentry's `before_send` hook (config/packages/sentry.php). This app throws
 * HttpException(s) throughout for expected control flow, not just genuine failures —
 * a wrong password, a 404 on someone else's anketa, a 409 on an already-published
 * cycle (see JsonExceptionListener's own docblock for why the app leans on
 * HttpException this way). The <500 cutoff that turns that into "don't report it" is
 * this class's own call, made here rather than in JsonExceptionListener, which has no
 * status-code branching of its own. Reporting every one of those to Sentry as an
 * "error" would bury the 5xx bugs Sentry actually exists to catch.
 *
 * Separately: Sentry attaches the full request URL to every event unconditionally,
 * through two independent mechanisms — RequestIntegration's `request.url`
 * (config/packages/sentry.php's max_request_body_size/send_default_pii only govern the
 * body/user data, not the URL) and, found by actually inspecting a captured envelope
 * rather than assuming one redaction covered both, the tracing span's own
 * `contexts.trace.data.http.url` — and ActivationController / PasswordResetController
 * carry their one-time tokens as URL path segments, not query params or headers. A 500
 * while looking up or completing one of those tokens would otherwise ship a live,
 * still-valid activation/password-reset token to Sentry via either field.
 */
class SentryBeforeSendFilter
{
    private const TOKEN_URL_PATTERN = '#(/api/(?:activation|password-reset)-tokens/)[^/?]+#';

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        $this->redactRequestUrl($event);
        $this->redactTraceUrl($event);

        return $event;
    }

    private function redactRequestUrl(Event $event): void
    {
        $request = $event->getRequest();
        if (isset($request['url']) && \is_string($request['url'])) {
            $request['url'] = $this->redact($request['url']);
            $event->setRequest($request);
        }
    }

    private function redactTraceUrl(Event $event): void
    {
        $trace = $event->getContexts()['trace'] ?? null;
        if (!\is_array($trace) || !\is_array($trace['data'] ?? null) || !\is_string($trace['data']['http.url'] ?? null)) {
            return;
        }

        $trace['data']['http.url'] = $this->redact($trace['data']['http.url']);
        $event->setContext('trace', $trace);
    }

    private function redact(string $url): string
    {
        // Fail closed, not open: if the regex engine itself errors (e.g. a pathological
        // backtracking case on a very long URL), preg_replace() returns null — treat
        // that the same as "couldn't confirm this is safe" and drop the whole URL,
        // rather than let a possibly-still-secret-bearing string through unredacted.
        return preg_replace(self::TOKEN_URL_PATTERN, '$1[redacted]', $url) ?? '[redacted]';
    }
}
