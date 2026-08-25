<?php

namespace App\Tests\Support;

use App\Company\SingleCompanyProvider;
use App\Entity\ActivationToken;
use App\Entity\Company;
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
     * A private "IP" for this test method only (Phase 7f: the login/activation-complete
     * rate limiters are keyed by client IP; without this, every test sharing the
     * default 127.0.0.1 — including every activateUser() call across every test
     * file — would draw from the same shared budget and trip each other's limits).
     * A dedicated rate-limit test can still reliably trip its own limiter purely
     * with its own requests, since nothing else shares this IP.
     */
    private string $clientIp;

    protected function setUp(): void
    {
        parent::setUp();
        // 3 random octets (~16.7M combinations) — a single-octet range (254 values)
        // would make collisions likely across ~90 tests by the birthday paradox.
        $this->clientIp = \sprintf('203.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(0, 255));
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $extraHeaders
     *
     * @return array{status: int, json: mixed}
     */
    protected function jsonRequest(KernelBrowser $client, string $method, string $path, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge(['REMOTE_ADDR' => $this->clientIp], $extraHeaders);
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

    /** Same reasoning as entityManager() above — a typed accessor for the one other container service test code regularly needs directly. */
    protected function singleCompanyProvider(): SingleCompanyProvider
    {
        $provider = self::getContainer()->get(SingleCompanyProvider::class);
        \assert($provider instanceof SingleCompanyProvider);

        return $provider;
    }

    /**
     * Activates a brand-new user through the real activation endpoints (same
     * flow the frontend uses, opaque placeholder crypto material — the
     * backend never inspects it). Leaves $client logged in as this user,
     * exactly like a real activation completes with an immediate session.
     *
     * $company defaults to the single seeded company every test database has (Phase A
     * of private/cloud-service-plan.md, not tracked in git) — the vast majority of
     * existing tests don't care about company boundaries at all and should keep working
     * unchanged. Pass an explicit, distinct Company to set up a genuine cross-company
     * scenario (see Functional/CompanyIsolationTest.php) — re-fetched by id here rather
     * than used as-is, since a real HTTP request through $client (this method's own
     * jsonRequest() call below, or any earlier one in the same test) clears the entity
     * manager's identity map, leaving a Company object created before that request
     * detached; re-fetching is cheap and correct whether or not a clear happened.
     *
     * @return array{id: string, email: string, isAdmin: bool}
     */
    protected function activateUser(KernelBrowser $client, string $email, bool $admin = false, string $locale = 'en', ?Company $company = null, ?string $displayName = null): array
    {
        if (null !== $company) {
            $company = $this->entityManager()->find(Company::class, $company->getId()) ?? $company;
        } else {
            $company = $this->singleCompanyProvider()->get();
        }

        [$token, $rawToken] = ActivationToken::issue($email, $company, $admin);
        $this->entityManager()->persist($token);
        $this->entityManager()->flush();

        $result = $this->jsonRequest($client, 'POST', "/api/activation-tokens/{$rawToken}/complete", [
            'authKey' => str_repeat('a', 44),
            'publicKey' => str_repeat('b', 44),
            'encryptedPrivateKey' => str_repeat('c', 44),
            'locale' => $locale,
            ...(null !== $displayName ? ['displayName' => $displayName] : []),
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
