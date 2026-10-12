<?php

namespace App\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * Answers the Stripe SDK's HTTP requests with what a test queued, and keeps what was
 * asked, so StripeBillingProvider's real SDK calls can be tested without the network.
 */
final class StubStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: mixed[]}> */
    public array $requests = [];

    /** @var list<array{int, string}> */
    private array $answers = [];

    public function willAnswer(int $status, string $json): void
    {
        $this->answers[] = [$status, $json];
    }

    /**
     * @param string   $method
     * @param string   $absUrl
     * @param mixed[]  $headers
     * @param mixed[]  $params
     * @param bool     $hasFile
     * @param string   $apiMode
     * @param int|null $maxNetworkRetries
     *
     * @return array{string, int, array<string, string>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];
        $answer = array_shift($this->answers);
        if (null === $answer) {
            throw new \LogicException('The test queued no answer for '.$method.' '.$absUrl);
        }

        return [$answer[1], $answer[0], []];
    }
}
