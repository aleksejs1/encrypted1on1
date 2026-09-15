<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class JsonExceptionListenerFunctionalTest extends ApiTestCase
{
    public function testUnhandledNonHttpExceptionOnApiRouteReturns500Json(): void
    {
        $client = static::createClient();

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
}
