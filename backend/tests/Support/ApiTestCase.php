<?php

namespace App\Tests\Support;

use App\Entity\ActivationToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Shared helpers for hitting the real API through KernelBrowser — every
 * functional test needs a CSRF token and (almost always) a logged-in user,
 * so this is a legitimate shared abstraction with dozens of real call
 * sites, unlike the single-use widgets elsewhere in this codebase that
 * deliberately stayed unabstracted.
 */
abstract class ApiTestCase extends WebTestCase
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     *
     * @return array{status: int, json: mixed}
     */
    protected function jsonRequest(KernelBrowser $client, string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = $extraHeaders;
        $content = null;

        // Every state-changing route checks CSRF first, regardless of whether it
        // also expects a JSON body (see AuthController::logout()).
        if ('GET' !== $method) {
            $headers['HTTP_X_CSRF_TOKEN'] = $this->csrfToken($client);
        }
        if (null !== $body) {
            $headers['CONTENT_TYPE'] = 'application/json';
            $content = json_encode($body, \JSON_THROW_ON_ERROR);
        }

        $client->request($method, $path, server: $headers, content: $content);

        $responseContent = $client->getResponse()->getContent();
        $json = '' === $responseContent ? null : json_decode((string) $responseContent, true);

        return ['status' => $client->getResponse()->getStatusCode(), 'json' => $json];
    }

    protected function csrfToken(KernelBrowser $client): string
    {
        $client->request('GET', '/api/csrf-token');
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        \assert(\is_array($data) && \is_string($data['token']));

        return $data['token'];
    }

    /** The container's real entity manager, typed — ContainerInterface::get() only declares `object`. */
    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \assert($entityManager instanceof EntityManagerInterface);

        return $entityManager;
    }

    /**
     * Activates a brand-new user through the real activation endpoints (same
     * flow the frontend uses, opaque placeholder crypto material — the
     * backend never inspects it). Leaves $client logged in as this user,
     * exactly like a real activation completes with an immediate session.
     *
     * @return array{id: string, email: string, isAdmin: bool}
     */
    protected function activateUser(KernelBrowser $client, string $email, bool $admin = false, string $locale = 'en'): array
    {
        [$token, $rawToken] = ActivationToken::issue($email, $admin);
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
            'locale' => $locale,
        ]);

        self::assertSame(200, $result['status'], 'activation should succeed in test setup: '.json_encode($result));

        /** @var array{id: string, email: string, isAdmin: bool} $json */
        $json = $result['json'];

        return $json;
    }

    /** A fresh, collision-free email for this test run. */
    protected function uniqueEmail(string $label): string
    {
        return sprintf('%s-%s@example.com', $label, bin2hex(random_bytes(6)));
    }

    /**
     * A second, independent client (own cookie jar, own session) sharing the
     * kernel already booted by static::createClient() — WebTestCase::createClient()
     * itself can only be called once per test, but two-participant scenarios
     * (employee + manager) need two genuinely separate logged-in sessions.
     */
    protected function secondClient(): KernelBrowser
    {
        /** @var KernelInterface $kernel */
        $kernel = self::$kernel;

        return new KernelBrowser($kernel);
    }
}
