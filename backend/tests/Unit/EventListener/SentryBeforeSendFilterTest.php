<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\SentryBeforeSendFilter;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SentryBeforeSendFilterTest extends TestCase
{
    private SentryBeforeSendFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new SentryBeforeSendFilter();
    }

    public function testDropsA404HttpException(): void
    {
        $hint = EventHint::fromArray(['exception' => new NotFoundHttpException('no such anketa')]);

        self::assertNull(($this->filter)(Event::createEvent(), $hint));
    }

    public function testDropsA409HttpException(): void
    {
        $hint = EventHint::fromArray(['exception' => new ConflictHttpException('already published')]);

        self::assertNull(($this->filter)(Event::createEvent(), $hint));
    }

    public function testKeepsA500HttpException(): void
    {
        $hint = EventHint::fromArray(['exception' => new HttpException(500, 'boom')]);

        self::assertNotNull(($this->filter)(Event::createEvent(), $hint));
    }

    public function testKeepsAPlainExceptionThatIsNotAnHttpException(): void
    {
        $hint = EventHint::fromArray(['exception' => new \RuntimeException('unexpected DB error')]);

        self::assertNotNull(($this->filter)(Event::createEvent(), $hint));
    }

    public function testKeepsAnEventWithNoExceptionInTheHintAtAll(): void
    {
        // The shape a transaction/tracing event arrives in — no exception to judge.
        self::assertNotNull(($this->filter)(Event::createEvent(), null));
    }

    public function testRedactsAnActivationTokenFromTheRequestUrl(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://example.com/api/activation-tokens/LIVE_SECRET_ABC123']);

        $result = ($this->filter)($event, null);

        self::assertNotNull($result);
        self::assertSame(
            'https://example.com/api/activation-tokens/[redacted]',
            $result->getRequest()['url'],
        );
    }

    public function testRedactsAPasswordResetTokenFromTheRequestUrlEvenWithATrailingSegment(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://example.com/api/password-reset-tokens/LIVE_SECRET_ABC123/complete']);

        $result = ($this->filter)($event, null);

        self::assertNotNull($result);
        self::assertSame(
            'https://example.com/api/password-reset-tokens/[redacted]/complete',
            $result->getRequest()['url'],
        );
    }

    public function testLeavesAnUnrelatedRequestUrlUntouched(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://example.com/api/anketas/123']);

        $result = ($this->filter)($event, null);

        self::assertNotNull($result);
        self::assertSame('https://example.com/api/anketas/123', $result->getRequest()['url']);
    }

    public function testRedactsTheTracingSpanUrlSeparatelyFromTheRequestUrl(): void
    {
        $event = Event::createEvent();
        $event->setContext('trace', [
            'op' => 'http.server',
            'data' => ['http.url' => 'https://example.com/api/activation-tokens/LIVE_SECRET_ABC123'],
        ]);

        $result = ($this->filter)($event, null);

        self::assertNotNull($result);
        self::assertSame(
            'https://example.com/api/activation-tokens/[redacted]',
            $result->getContexts()['trace']['data']['http.url'],
        );
    }

    public function testDoesNotErrorWhenThereIsNoRequestOrTraceContextAtAll(): void
    {
        $result = ($this->filter)(Event::createEvent(), null);

        self::assertNotNull($result);
        self::assertSame([], $result->getRequest());
    }
}
