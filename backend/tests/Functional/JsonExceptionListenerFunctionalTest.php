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

        $this->requestThrowing($client, new \RuntimeException('Database deadlock on connection'), '{"password":"hunter2-secret"}');

        self::assertSame(500, $client->getResponse()->getStatusCode());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('RuntimeException', $errors[0]);
        self::assertStringContainsString('Database deadlock on connection', $errors[0]);
        self::assertStringContainsString('POST login', $errors[0]);
        self::assertStringNotContainsString('hunter2-secret', $errors[0]);
    }

    public function testServerErrorHttpExceptionIsLoggedOnce(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $this->requestThrowing($client, new ServiceUnavailableHttpException(null, 'Billing is down.'), '{}');

        self::assertSame(503, $client->getResponse()->getStatusCode());
        $errors = $logger->atLeast('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('Billing is down.', $errors[0]);
    }

    public function testClientErrorHttpExceptionIsNotLogged(): void
    {
        $client = static::createClient();
        $logger = $this->recordLogs();

        $this->requestThrowing($client, new ConflictHttpException('Already done.'), '{}');

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
        $logger = $this->recordLogs();

        $result = $this->jsonRequest($client, 'POST', '/api/login', ['email' => 'not-an-email']);

        self::assertSame(400, $result['status']);
        self::assertSame([], $logger->atLeast('error'));
    }

    private function requestThrowing(KernelBrowser $client, \Throwable $exception, string $body): void
    {
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = static::getContainer()->get('event_dispatcher');
        $listener = static function (ControllerEvent $event) use ($exception): void {
            if ('/api/login' === $event->getRequest()->getPathInfo()) {
                throw $exception;
            }
        };
        $dispatcher->addListener(KernelEvents::CONTROLLER, $listener, 100);

        try {
            $client->request('POST', '/api/login', [], [], ['CONTENT_TYPE' => 'application/json'], $body);
        } finally {
            $dispatcher->removeListener(KernelEvents::CONTROLLER, $listener);
        }
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
