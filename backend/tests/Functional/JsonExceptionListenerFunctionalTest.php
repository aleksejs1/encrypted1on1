<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RecordingLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class JsonExceptionListenerFunctionalTest extends ApiTestCase
{
    public function testUnhandledNonHttpExceptionOnApiRouteReturns500Json(): void
    {
        $client = static::createClient();
        $this->recordLogs();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $listener = static function (ControllerEvent $event): void {
            if ('/api/csrf-token' === $event->getRequest()->getPathInfo()) {
                throw new \RuntimeException('Database deadlock on connection');
            }
        };

        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener, 100);

        try {
            $client->request('GET', '/api/csrf-token');

            $response = $client->getResponse();
            self::assertSame(500, $response->getStatusCode());
            self::assertTrue($response->headers->contains('content-type', 'application/json'));

            $content = (string) $response->getContent();
            $data = json_decode($content, true);
            self::assertIsArray($data);
            self::assertSame('Internal server error.', $data['error']);
            self::assertStringNotContainsString('Database deadlock', $content);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }
    }

    public function testUnhandledNonHttpExceptionOnApiRouteTranslatesErrorWithXLocale(): void
    {
        $client = static::createClient();
        $this->recordLogs();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $listener = static function (ControllerEvent $event): void {
            if ('/api/csrf-token' === $event->getRequest()->getPathInfo()) {
                throw new \RuntimeException('Deadlock');
            }
        };

        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener, 100);

        try {
            $client->request('GET', '/api/csrf-token', [], [], ['HTTP_X_LOCALE' => 'de']);

            $response = $client->getResponse();
            self::assertSame(500, $response->getStatusCode());
            self::assertTrue($response->headers->contains('content-type', 'application/json'));

            $data = json_decode((string) $response->getContent(), true);
            self::assertIsArray($data);
            self::assertSame('Interner Serverfehler.', $data['error']);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }
    }

    public function testUnhandledErrorOnApiRouteReturns500Json(): void
    {
        $client = static::createClient();
        $this->recordLogs();

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $listener = static function (ControllerEvent $event): void {
            if ('/api/csrf-token' === $event->getRequest()->getPathInfo()) {
                throw new \TypeError('Simulated fatal type error');
            }
        };

        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener, 100);

        try {
            $client->request('GET', '/api/csrf-token');

            $response = $client->getResponse();
            self::assertSame(500, $response->getStatusCode());

            $data = json_decode((string) $response->getContent(), true);
            self::assertIsArray($data);
            self::assertSame('Internal server error.', $data['error']);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }
    }

    public function testUnhandledExceptionIsLoggedOnceWithoutTheRequestBody(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $this->requestThrowing($client, '/api/login', new \RuntimeException('Database deadlock on connection'), '{"password":"hunter2-secret"}');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringStartsWith('API error 500 on POST login: RuntimeException: Database deadlock on connection at ', $errors[0]);
        $this->assertNothingOfTheRequestIsLogged($logger, 'hunter2-secret');
    }

    public function testATokenInThePathIsNotLogged(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();
        $token = 'raw-activation-token-value';

        $this->requestThrowing($client, "/api/activation-tokens/{$token}/complete", new \RuntimeException('Crash'), '{}');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('POST activation_token_complete', $errors[0]);
        $this->assertNothingOfTheRequestIsLogged($logger, $token);
    }

    public function testServerErrorHttpExceptionIsLoggedOnce(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $this->requestThrowing($client, '/api/login', new ServiceUnavailableHttpException(null, 'Billing is down.'), '{}');

        self::assertSame(503, $client->getResponse()->getStatusCode());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringStartsWith('API error 503 on POST login: ', $errors[0]);
        self::assertStringContainsString('Billing is down.', $errors[0]);
    }

    public function testClientErrorHttpExceptionIsNotLogged(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $this->requestThrowing($client, '/api/login', new ConflictHttpException('Already done.'), '{}');

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame([], $logger->atLeast('error'));
    }

    public function testNotFoundIsNotLogged(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $client->request('GET', '/api/no-such-route');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame([], $logger->atLeast('error'));
    }

    public function testValidationErrorIsNotLogged(): void
    {
        $client = static::createClient();
        // jsonRequest() fetches a CSRF token first, and the browser would boot a fresh
        // kernel, with its own logger, for the request under test.
        $client->disableReboot();
        $logger = $this->recordLogs();

        $result = $this->jsonRequest($client, 'POST', '/api/login', ['email' => 'not-an-email']);

        self::assertSame(400, $result['status']);
        self::assertArrayHasKey('violations', $result['json']);
        self::assertSame([], $logger->atLeast('error'));
    }

    /**
     * Symfony's own logging of an exception this listener leaves alone goes through the
     * same logger, which is what makes the "logged once" and "not logged" assertions
     * above mean something.
     */
    public function testTheRecordingLoggerSeesWhatSymfonyLogs(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $client->request('GET', '/no-such-page-outside-the-api');

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertCount(1, $logger->atLeast('error'));
    }

    private function requestThrowing(KernelBrowser $client, string $path, \Throwable $exception, string $body): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $listener = static function (ControllerEvent $event) use ($exception, $path): void {
            if ($path === $event->getRequest()->getPathInfo()) {
                throw $exception;
            }
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener, 100);

        try {
            $client->request('POST', $path, [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }
    }

    /**
     * The line's context is checked too, key by key: a logger other than Symfony's
     * fallback one prints context the message has no placeholder for. Only `warning` and
     * up is checked, which is what gets written: Symfony's router logs the matched
     * URL at `info`.
     */
    private function assertNothingOfTheRequestIsLogged(RecordingLogger $logger, string $secret): void
    {
        $contexts = $logger->contextsAtLeast('warning');
        self::assertCount(1, $contexts);
        self::assertSame(['status', 'method', 'route', 'exception'], array_keys($contexts[0]));
        self::assertStringNotContainsString($secret, serialize($contexts[0]));
        self::assertStringNotContainsString($secret, $logger->atLeast('warning')[0]);
    }

    /**
     * Replaces the container's logger, which is the one both this app's listener and
     * Symfony's own ErrorListener get.
     */
    private function recordLogs(): RecordingLogger
    {
        $logger = new RecordingLogger();
        static::getContainer()->set('logger', $logger);

        return $logger;
    }
}
