<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Client for sending errors to Application Logger platform.
 *
 * RESILIENCE GUARANTEES:
 * - Never blocks the host application (2-second max timeout)
 * - Never throws exceptions to caller (all exceptions caught)
 * - Uses circuit breaker to prevent cascade failures
 * - Fire-and-forget mode: doesn't wait for API responses
 * - Gracefully handles all failure scenarios
 *
 * This class is the MOST CRITICAL for ensuring logging never impacts the application.
 */
class ApiClient
{
    private readonly string $endpoint;
    private readonly string $publicKey;
    private readonly string $projectId;
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $dsn,
        private readonly string $apiKey,
        private readonly float $timeout,
        private readonly int $retryAttempts,
        private readonly bool $async,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly ?LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {
        // Validate timeout
        if ($timeout < 0.5 || $timeout > 5.0) {
            throw new \InvalidArgumentException('Timeout must be between 0.5 and 5.0 seconds');
        }

        // Parse DSN and initialize readonly properties
        $parsed = $this->parseDsn($dsn);
        $this->endpoint = $parsed['endpoint'];
        $this->publicKey = $apiKey; // Store API key from constructor parameter
        $this->projectId = $parsed['projectId'];

        // Create HTTP client with aggressive timeout configuration
        $this->httpClient = HttpClient::create([
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
            'http_version' => '1.1', // HTTP/1.1 is more reliable than HTTP/2 for fire-and-forget
        ]);
    }

    /**
     * Send error payload to the platform (fire-and-forget).
     *
     * This method NEVER throws exceptions - all errors are caught and logged.
     * Returns immediately without waiting for response when async=true.
     *
     * @param array<string, mixed> $payload Error data to send
     */
    public function sendError(array $payload): void
    {
        // Check circuit breaker - fast-fail if service is known to be down
        if ($this->circuitBreaker->isOpen()) {
            // Circuit is open - service is down, don't even try
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping error send');
            }

            return;
        }

        try {
            // Add metadata
            $payload['timestamp'] = $payload['timestamp'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
            $payload['platform'] = $payload['platform'] ?? 'symfony';

            // Send to API
            $this->sendToApi($payload);

            // Record success
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            // CRITICAL: Never let exceptions bubble up
            $this->circuitBreaker->recordFailure();

            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to send error', [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            // Optionally: Queue for later retry (future enhancement)
            // $this->queueForRetry($payload);
        }
    }

    /**
     * Create or update a session.
     *
     * This method NEVER throws exceptions - all errors are caught and logged.
     *
     * @param array<string, mixed> $sessionData Session data (session_id, session_hash, ip_address, user_agent, etc.)
     */
    public function createSession(array $sessionData): void
    {
        // Check circuit breaker
        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping session creation');
            }

            return;
        }

        try {
            // Add timestamp
            $sessionData['started_at'] = $sessionData['started_at'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);

            // Send to session API
            $this->sendToSessionApi($sessionData);

            // Record success
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();

            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to create session', [
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Add event(s) to a session.
     *
     * This method NEVER throws exceptions - all errors are caught and logged.
     *
     * @param string $sessionId Session ID
     * @param array<string, mixed>|array<int, array<string, mixed>> $eventData Single event or array of events
     */
    public function addSessionEvent(string $sessionId, array $eventData): void
    {
        // Check circuit breaker
        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping session event');
            }

            return;
        }

        try {
            // Send to session event API
            $this->sendToSessionEventApi($sessionId, $eventData);

            // Record success
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();

            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to add session event', [
                    'session_id' => $sessionId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * End a session.
     *
     * This method NEVER throws exceptions - all errors are caught and logged.
     *
     * @param string $sessionId Session ID
     * @param \DateTimeImmutable|null $endedAt End timestamp (null = now)
     */
    public function endSession(string $sessionId, ?\DateTimeImmutable $endedAt = null): void
    {
        // Check circuit breaker
        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping session end');
            }

            return;
        }

        try {
            $data = [
                'ended_at' => ($endedAt ?? new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
            ];

            // Send to session end API
            $this->sendToSessionEndApi($sessionId, $data);

            // Record success
            $this->circuitBreaker->recordSuccess();
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();

            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to end session', [
                    'session_id' => $sessionId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send payload to API.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ExceptionInterface
     */
    private function sendToApi(array $payload, int $attempt = 0): void
    {
        $headers = [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->publicKey,  // Send API key, not full DSN
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];

        try {
            // Encode payload with error handling
            try {
                $jsonBody = json_encode($payload, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                // JSON encoding failed - log and skip this error
                if ($this->shouldLog()) {
                    $this->logger?->error('ApplicationLogger: Failed to JSON encode payload', [
                        'error' => $e->getMessage(),
                    ]);
                }

                return;
            }

            $response = $this->httpClient->request('POST', $this->endpoint, [
                'headers' => $headers,
                'body' => $jsonBody,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
            ]);

            // In async mode (fire-and-forget), we don't wait for the response
            // The HTTP client will complete the request in the background
            if (!$this->async) {
                // Only in sync mode do we wait for response
                $statusCode = $response->getStatusCode();

                // Log non-202 responses for debugging
                if (202 !== $statusCode && $this->shouldLog()) {
                    $this->logger?->warning('ApplicationLogger: Unexpected status code', [
                        'status_code' => $statusCode,
                        'response' => $response->getContent(false),
                    ]);
                }
            }
        } catch (ExceptionInterface $e) {
            // Network/timeout errors - retry if configured
            if ($attempt < $this->retryAttempts) {
                // Exponential backoff: wait 2^attempt seconds
                // But don't wait too long - max 2 seconds per retry
                $delay = min(2, 2 ** $attempt);
                usleep((int) ($delay * 1000000));

                $this->sendToApi($payload, $attempt + 1);

                return;
            }

            // Max retries reached or retries disabled - throw to be caught by sendError()
            throw $e;
        }
    }

    /**
     * Send session data to API.
     *
     * @param array<string, mixed> $sessionData
     *
     * @throws ExceptionInterface
     */
    private function sendToSessionApi(array $sessionData): void
    {
        $host = parse_url($this->endpoint, \PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, \PHP_URL_SCHEME);
        $port = parse_url($this->endpoint, \PHP_URL_PORT);

        $hostWithPort = $host;
        if (null !== $port) {
            $hostWithPort .= ':'.$port;
        }

        $url = \sprintf('%s://%s/api/v1/sessions', $scheme, $hostWithPort);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->publicKey,
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];

        try {
            $jsonBody = json_encode($sessionData, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to JSON encode session data', [
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'body' => $jsonBody,
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
        ]);

        // In async mode, don't wait for response
        if (!$this->async) {
            $statusCode = $response->getStatusCode();
            if (202 !== $statusCode && $this->shouldLog()) {
                $this->logger?->warning('ApplicationLogger: Unexpected session API status code', [
                    'status_code' => $statusCode,
                ]);
            }
        }
    }

    /**
     * Send session event to API.
     *
     * @param array<string, mixed> $eventData
     *
     * @throws ExceptionInterface
     */
    private function sendToSessionEventApi(string $sessionId, array $eventData): void
    {
        $host = parse_url($this->endpoint, \PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, \PHP_URL_SCHEME);
        $port = parse_url($this->endpoint, \PHP_URL_PORT);

        $hostWithPort = $host;
        if (null !== $port) {
            $hostWithPort .= ':'.$port;
        }

        $url = \sprintf('%s://%s/api/v1/sessions/%s/events', $scheme, $hostWithPort, $sessionId);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->publicKey,
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];

        try {
            $jsonBody = json_encode($eventData, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to JSON encode event data', [
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'body' => $jsonBody,
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
        ]);

        if (!$this->async) {
            $statusCode = $response->getStatusCode();
            if (202 !== $statusCode && $this->shouldLog()) {
                $this->logger?->warning('ApplicationLogger: Unexpected session event API status code', [
                    'status_code' => $statusCode,
                ]);
            }
        }
    }

    /**
     * Send session end to API.
     *
     * @param array<string, mixed> $data
     *
     * @throws ExceptionInterface
     */
    private function sendToSessionEndApi(string $sessionId, array $data): void
    {
        $host = parse_url($this->endpoint, \PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, \PHP_URL_SCHEME);
        $port = parse_url($this->endpoint, \PHP_URL_PORT);

        $hostWithPort = $host;
        if (null !== $port) {
            $hostWithPort .= ':'.$port;
        }

        $url = \sprintf('%s://%s/api/v1/sessions/%s/end', $scheme, $hostWithPort, $sessionId);

        $headers = [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->publicKey,
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];

        try {
            $jsonBody = json_encode($data, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($this->shouldLog()) {
                $this->logger?->error('ApplicationLogger: Failed to JSON encode session end data', [
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => $headers,
            'body' => $jsonBody,
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
        ]);

        if (!$this->async) {
            $statusCode = $response->getStatusCode();
            if (202 !== $statusCode && $this->shouldLog()) {
                $this->logger?->warning('ApplicationLogger: Unexpected session end API status code', [
                    'status_code' => $statusCode,
                ]);
            }
        }
    }

    /**
     * Parse DSN into components.
     *
     * DSN format: {protocol}://{host}/{projectId}
     * Example: https://localhost:8111/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37
     *
     * Note: API key is NOT in the DSN. It's passed separately to the constructor.
     *
     * @return array{endpoint: string, projectId: string}
     *
     * @throws \InvalidArgumentException
     */
    private function parseDsn(string $dsn): array
    {
        if (empty($dsn)) {
            throw new \InvalidArgumentException('ApplicationLogger DSN cannot be empty');
        }

        try {
            $url = parse_url($dsn);

            // API key is NO LONGER in the URL - it's a separate constructor parameter
            if (false === $url || !isset($url['scheme'], $url['host'], $url['path'])) {
                throw new \InvalidArgumentException('Invalid DSN format. Expected: https://host/project-id');
            }

            $projectId = trim($url['path'], '/');

            // Build endpoint with optional port
            $host = $url['host'];
            if (isset($url['port'])) {
                $host .= ':'.$url['port'];
            }
            $endpoint = \sprintf('%s://%s/api/errors/ingest', $url['scheme'], $host);

            if (empty($projectId)) {
                throw new \InvalidArgumentException('DSN must include project ID in path');
            }

            return [
                'endpoint' => $endpoint,
                'projectId' => $projectId,
            ];
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid DSN format: %s. Expected: https://host/project-id', $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * Check if we should log debug information.
     */
    private function shouldLog(): bool
    {
        return $this->debug && null !== $this->logger;
    }

    /**
     * Get circuit breaker state for monitoring.
     *
     * @return array{state: string, failureCount: int, openedAt: int|null, halfOpenAttempts: int}
     */
    public function getCircuitBreakerState(): array
    {
        return $this->circuitBreaker->getState();
    }
}
