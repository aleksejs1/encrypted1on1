<?php

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Support\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

class AuthControllerTest extends ApiTestCase
{
    public function testMeReturns401WhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame(401, $result['status']);
        self::assertSame('Not authenticated.', $result['json']['error']);
    }

    public function testMeReturnsTheCurrentUserAfterActivation(): void
    {
        $client = static::createClient();
        $user = $this->activateUser($client, $this->uniqueEmail('auth-me'));

        $result = $this->jsonRequest($client, 'GET', '/api/me');

        self::assertSame(200, $result['status']);
        self::assertSame($user['id'], $result['json']['id']);
        self::assertSame($user['email'], $result['json']['email']);
        self::assertArrayHasKey('publicKey', $result['json']);
        self::assertArrayHasKey('encryptedPrivateKey', $result['json']);
    }

    public function testLoginSucceedsWithTheCredentialsSetAtActivation(): void
    {
        $email = $this->uniqueEmail('auth-login-ok');
        $client = static::createClient();
        $this->activateUser($client, $email);
        // Activation logs in immediately; log out so login() starts from a clean session.
        $this->jsonRequest($client, 'POST', '/api/logout');

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44), // matches ApiTestCase::activateUser()
        ]);

        self::assertSame(200, $result['status']);
        self::assertArrayHasKey('publicKey', $result['json']);
    }

    public function testLoginFailsWithTheWrongPassword(): void
    {
        $email = $this->uniqueEmail('auth-login-bad');
        $client = static::createClient();
        $this->activateUser($client, $email);
        $this->jsonRequest($client, 'POST', '/api/logout');

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('z', 44),
        ]);

        self::assertSame(401, $result['status']);
        self::assertSame('Invalid email or password.', $result['json']['error']);
    }

    public function testLoginFailsForAnUnknownEmail(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $this->uniqueEmail('no-such-user'),
            'authKey' => str_repeat('a', 44),
        ]);

        self::assertSame(401, $result['status']);
    }

    public function testLoginRejectsABlockedAccountAfterProvingTheCorrectPassword(): void
    {
        $email = $this->uniqueEmail('auth-login-blocked');
        $client = static::createClient();
        $user = $this->activateUser($client, $email);
        $this->jsonRequest($client, 'POST', '/api/logout');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entity = $entityManager->find(User::class, $user['id']);
        $entity->setBlocked(true);
        $entityManager->flush();

        $result = $this->jsonRequest($client, 'POST', '/api/login', [
            'email' => $email,
            'authKey' => str_repeat('a', 44),
        ]);

        self::assertSame(403, $result['status']);
        self::assertSame('This account has been blocked.', $result['json']['error']);
    }

    public function testStateChangingRequestWithoutACsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'x@example.com', 'authKey' => str_repeat('a', 44)]),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testErrorMessagesAreTranslatedByTheXLocaleHeader(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/me', extraHeaders: ['HTTP_X_LOCALE' => 'ru']);

        self::assertSame('Вы не авторизованы.', $result['json']['error']);
    }

    public function testSetLocaleUpdatesTheStoredLocale(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-locale'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/locale', ['locale' => 'es']);

        self::assertSame(200, $result['status']);
        self::assertSame('es', $result['json']['locale']);
    }

    public function testSetLocaleRejectsAnUnsupportedCode(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-set-locale-bad'));

        $result = $this->jsonRequest($client, 'PUT', '/api/me/locale', ['locale' => 'fr']);

        self::assertSame(400, $result['status']);
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('auth-logout'));

        $logout = $this->jsonRequest($client, 'POST', '/api/logout');
        self::assertSame(200, $logout['status']);

        $me = $this->jsonRequest($client, 'GET', '/api/me');
        self::assertSame(401, $me['status']);
    }
}
