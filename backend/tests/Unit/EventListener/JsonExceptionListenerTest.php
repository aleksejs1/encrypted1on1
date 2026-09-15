<?php

namespace App\Tests\Unit\EventListener;

use App\EventListener\JsonExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

class JsonExceptionListenerTest extends TestCase
{
    private function createTranslator(): TranslatorInterface
    {
        $translator = self::createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string => match ($id) {
                'errors.internal_server_error' => 'de' === $locale ? 'Interner Serverfehler.' : 'Internal server error.',
                default => $id,
            }
        );

        return $translator;
    }

    public function testIgnoresNonApiRequests(): void
    {
        $listener = new JsonExceptionListener($this->createTranslator());
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/health');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException('Forbidden'));

        $listener($event);

        self::assertNull($event->getResponse());
    }

    public function testIgnoresNonApiNonHttpExceptions(): void
    {
        $listener = new JsonExceptionListener($this->createTranslator());
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/health');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Database unreachable'));

        $listener($event);

        self::assertNull($event->getResponse());
    }

    public function testHandlesStandardHttpException(): void
    {
        $listener = new JsonExceptionListener($this->createTranslator());
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $exception = new AccessDeniedHttpException('Access denied.');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('{"error":"Access denied."}', $response->getContent());
    }

    public function testFormatsNonHttpExceptionAs500JsonWithSafeLocalizedMessage(): void
    {
        $translator = self::createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('errors.internal_server_error', [], null, 'en')
            ->willReturn('Internal server error.');

        $listener = new JsonExceptionListener($translator);
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->setLocale('en');
        $exception = new \RuntimeException('Sensitive DB deadlock: query table anketas locked');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $content = (string) $response->getContent();
        self::assertSame('{"error":"Internal server error."}', $content);
        self::assertStringNotContainsString('Sensitive DB deadlock', $content);
        self::assertStringNotContainsString('anketas locked', $content);
    }

    public function testFormatsFatalErrorAs500Json(): void
    {
        $translator = self::createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('errors.internal_server_error', [], null, 'en')
            ->willReturn('Internal server error.');

        $listener = new JsonExceptionListener($translator);
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->setLocale('en');
        $error = new \TypeError('Argument #1 must be of type string, int given');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $error);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $content = (string) $response->getContent();
        self::assertSame('{"error":"Internal server error."}', $content);
        self::assertStringNotContainsString('Argument #1', $content);
    }

    public function testRespectsRequestLocaleFor500Message(): void
    {
        $translator = self::createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('errors.internal_server_error', [], null, 'de')
            ->willReturn('Interner Serverfehler.');

        $listener = new JsonExceptionListener($translator);
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/api/test');
        $request->setLocale('de');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Crash'));

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('{"error":"Interner Serverfehler."}', $response->getContent());
    }

    public function testMaps422To400WithViolations(): void
    {
        $listener = new JsonExceptionListener($this->createTranslator());
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/api/test');

        $violations = new ConstraintViolationList([
            new ConstraintViolation('Field is required.', null, [], null, 'username', null),
            new ConstraintViolation('Too short.', null, [], null, 'password', null),
        ]);
        $validationException = new ValidationFailedException('invalid data', $violations);
        $httpException = new UnprocessableEntityHttpException('Validation failed', $validationException);

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $httpException);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Validation failed', $data['error']);
        self::assertCount(2, $data['violations']);
        self::assertSame('username', $data['violations'][0]['property']);
        self::assertSame('Field is required.', $data['violations'][0]['message']);
        self::assertSame('password', $data['violations'][1]['property']);
        self::assertSame('Too short.', $data['violations'][1]['message']);
    }

    public function testFindsNestedValidationFailedExceptionInDeepChain(): void
    {
        $listener = new JsonExceptionListener($this->createTranslator());
        $kernel = self::createStub(HttpKernelInterface::class);
        $request = Request::create('/api/test');

        $violations = new ConstraintViolationList([
            new ConstraintViolation('Invalid value.', null, [], null, 'email', null),
        ]);
        $validationException = new ValidationFailedException('invalid data', $violations);
        $intermediateException = new \RuntimeException('Intermediate wrapper', 0, $validationException);
        $httpException = new UnprocessableEntityHttpException('Outer error', $intermediateException);

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $httpException);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $data = json_decode((string) $response->getContent(), true);
        self::assertSame('Outer error', $data['error']);
        self::assertCount(1, $data['violations']);
        self::assertSame('email', $data['violations'][0]['property']);
        self::assertSame('Invalid value.', $data['violations'][0]['message']);
    }
}
