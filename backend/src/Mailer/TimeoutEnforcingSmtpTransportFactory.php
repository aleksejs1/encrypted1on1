<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Decorates the built-in "smtp" transport factory (registered as
 * mailer.transport_factory.smtp, see services.php) to bound the socket timeout on the
 * resulting connection. Without this, a connect/read hang against MAILER_DSN falls back
 * to PHP's `default_socket_timeout` ini (60s by default) — AnketaNotifier::send() calls
 * $mailer->send() synchronously in the request/response cycle (Phase 6e; ADR 5 keeps mail
 * synchronous on purpose, no queue/worker), so a hung SMTP server would otherwise tie up
 * a FrankenPHP thread for up to a minute. This bounds that cost instead of removing the
 * synchronous design.
 *
 * Only ever touches an EsmtpTransport's SocketStream — every other transport this app
 * could be configured with (null:// in tests, sendmail, an HTTP-API provider bridge) goes
 * through a different factory entirely and is untouched.
 *
 * Decoration, not a from-scratch transport factory, so DSN parsing/auth/TLS/auto_tls/
 * source_ip/max_per_second/etc. stay exactly Symfony's own EsmtpTransportFactory
 * behavior — this only adds the one thing that factory doesn't expose via the DSN itself
 * (Symfony Mailer has no `?timeout=` DSN option; confirmed directly against
 * EsmtpTransportFactory's source, which reads no such option).
 */
final class TimeoutEnforcingSmtpTransportFactory implements TransportFactoryInterface
{
    /** Wired explicitly in services.php — decoration + the tag that puts this back in mailer.transport_factory's tagged_iterator need config, not autowiring. */
    public function __construct(
        private readonly TransportFactoryInterface $inner,
        private readonly float $timeout,
    ) {
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $transport = $this->inner->create($dsn);

        if ($transport instanceof EsmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                $stream->setTimeout($this->timeout);
            }
        }

        return $transport;
    }

    public function supports(Dsn $dsn): bool
    {
        return $this->inner->supports($dsn);
    }
}
