<?php

namespace App\Tests\Support;

use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

/**
 * Answers the Stripe SDK's HTTP requests with what a test queued, and keeps what was
 * asked, so StripeBillingProvider's real SDK calls can be tested without the network.
 */
final class StubStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, params: mixed[]}> */
    public array $requests = [];

    /** @var list<array{int, string}|null> */
    private array $answers = [];

    public function willAnswer(int $status, string $json): void
    {
        $this->answers[] = [$status, $json];
    }

    /** No answer in time: what the SDK's own client throws for a timeout or a failed connection. */
    public function willNotAnswer(): void
    {
        $this->answers[] = null;
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
        if ([] === $this->answers) {
            throw new \LogicException('The test queued no answer for '.$method.' '.$absUrl);
        }
        $answer = array_shift($this->answers);
        if (null === $answer) {
            throw new ApiConnectionException('Operation timed out');
        }

        return [$answer[1], $answer[0], []];
    }
}
