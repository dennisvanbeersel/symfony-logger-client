<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service\Sdk;

use ApplicationLogger\Sdk\CircuitBreaker;
use DateTimeImmutable;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Slim bundle-owned session transport.
 *
 * Handles the session-tracking pipeline — create, add events, end — which
 * sdk-core does NOT model. POSTs to {dsn-host}/api/v1/sessions[...] with
 * X-Api-Key authentication.
 *
 * RESILIENCE CONTRACT (matches the wider bundle transport contract):
 * - Never throws to the caller; all transport/encode failures are caught silently.
 * - Loopback-guarded: skips when the current request IS an ingest route
 *   (same-host self-monitoring guard, path-based via LoopbackGuard).
 * - Breaker-gated: checks CircuitBreaker::allowRequest() before each POST;
 *   records success/failure on the observed HTTP/transport outcome.
 * - Disabled: all methods no-op when $enabled is false or DSN is empty.
 *
 * Wire contract (preserved from ApiClient::createSession/addSessionEvent/endSession):
 * - POST {base}/api/v1/sessions             — create
 * - POST {base}/api/v1/sessions/{id}/events — add events
 * - POST {base}/api/v1/sessions/{id}/end    — end
 * - Headers: Content-Type: application/json, X-Api-Key: {api_key}
 * - Payload: JSON-encoded array; createSession injects started_at if absent;
 *   endSession sends {"ended_at": <ATOM-formatted DateTimeImmutable>}.
 *
 * @internal not part of the bundle's public API
 */
final readonly class SessionApiClient implements SessionClientInterface
{
    private string $baseUrl;

    public function __construct(
        string $dsn,
        private string $apiKey,
        private HttpClientInterface $httpClient,
        private LoopbackGuard $loopback,
        private CircuitBreaker $breaker,
        private bool $enabled = true,
        private float $timeout = 2.0,
    ) {
        $this->baseUrl = $this->parseBaseUrl($dsn);
    }

    /**
     * Create a new session.
     *
     * @param array<string, mixed> $sessionData
     */
    public function createSession(array $sessionData): void
    {
        if (!$this->shouldSend()) {
            return;
        }

        $sessionData['started_at'] ??= (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->post('/api/v1/sessions', $sessionData);
    }

    /**
     * Add event(s) to an existing session.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $eventData
     */
    public function addSessionEvent(string $sessionId, array $eventData): void
    {
        if (!$this->shouldSend()) {
            return;
        }

        $this->post(\sprintf('/api/v1/sessions/%s/events', $sessionId), $eventData);
    }

    /**
     * End a session.
     */
    public function endSession(string $sessionId, ?\DateTimeImmutable $endedAt = null): void
    {
        if (!$this->shouldSend()) {
            return;
        }

        $data = [
            'ended_at' => ($endedAt ?? new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        $this->post(\sprintf('/api/v1/sessions/%s/end', $sessionId), $data);
    }

    /**
     * Flush pending sends. This implementation POSTs synchronously per call
     * (simple bounded fire-and-forget), so flush is a no-op. Never throws.
     */
    public function flush(): void
    {
        // Synchronous sends — nothing buffered to drain.
    }

    /**
     * Returns false when the send should be skipped (disabled, no DSN, or
     * current request is an ingest route).
     */
    private function shouldSend(): bool
    {
        if (!$this->enabled || '' === $this->baseUrl) {
            return false;
        }

        if ($this->loopback->isIngestRequest()) {
            return false;
        }

        return true;
    }

    /**
     * POST the payload to {baseUrl}{path} with session auth headers.
     * Breaker-gated. Never throws.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $payload
     */
    private function post(string $path, array $payload): void
    {
        if (!$this->breaker->allowRequest()) {
            return;
        }

        try {
            $response = $this->httpClient->request('POST', $this->baseUrl.$path, [
                'headers' => $this->headers(),
                'json' => $payload,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
            ]);

            // Trigger the response to surface transport failures so the breaker
            // can observe them (mirrors the dispatcher's non-blocking poll).
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 400) {
                $this->breaker->recordSuccess();
            } else {
                $this->breaker->recordFailure();
            }
        } catch (\Throwable) {
            $this->breaker->recordFailure();
        }
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->apiKey,
        ];
    }

    /**
     * Extract scheme://host[:port] from a DSN string.
     * DSN format: {scheme}://{host}[:{port}]/{projectId}
     * Returns empty string for blank or unparseable DSNs (keeps class inert).
     */
    private function parseBaseUrl(string $dsn): string
    {
        if ('' === $dsn) {
            return '';
        }

        $parts = parse_url($dsn);
        if (false === $parts) {
            return '';
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (null === $scheme || null === $host || '' === $host) {
            return '';
        }

        $port = $parts['port'] ?? null;

        return \sprintf('%s://%s%s', $scheme, $host, null !== $port ? ':'.$port : '');
    }
}
