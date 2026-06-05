<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * API Client for sending errors AND logs to the Application Logger platform.
 *
 * RESILIENCE GUARANTEES:
 * - Never blocks the host application (2-second max timeout, no synchronous retry in async mode)
 * - Never throws exceptions to caller (all exceptions caught)
 * - Uses circuit breaker to prevent cascade failures
 * - Fire-and-forget mode: dispatches the request with 'buffer' => false and inspects the
 *   outcome WITHOUT blocking, so transport/HTTP failures actually drive the circuit breaker
 * - Gracefully handles all failure scenarios
 *
 * This class is the MOST CRITICAL for ensuring logging never impacts the application.
 *
 * LOG AGGREGATION CONTRACT (Go log-collector, internal/http):
 * - Single log:  POST {log_endpoint}{log_path}            body: LogEntry
 * - Batch logs:  POST {log_endpoint}{log_path}/batch      body: {"logs": [LogEntry, ...]} (<=1000)
 * - Auth header: X-Api-Key: {log_token}
 * - LogEntry fields: timestamp(RFC3339), severity(string|int), message, hostname,
 *   app_name, proc_id, msg_id, environment, context(map<string,string>)
 * - Success: HTTP 202 Accepted
 */
class ApiClient
{
    private readonly string $endpoint;
    private readonly string $errorPath;
    private readonly string $publicKey;
    private readonly HttpClientInterface $httpClient;

    /**
     * Pending fire-and-forget responses. Holding a reference keeps the underlying
     * cURL handle alive so the request is actually transmitted; we drain them at
     * destruct time (or eagerly) without ever blocking the host request path.
     *
     * @var array<int, ResponseInterface>
     */
    private array $pendingResponses = [];

    public function __construct(
        string $dsn,
        string $apiKey,
        private readonly float $timeout,
        private readonly int $retryAttempts,
        private readonly bool $async,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly ?LoggerInterface $logger,
        private readonly bool $debug = false,
        ?HttpClientInterface $httpClient = null,
        string $errorPath = '/api/v1/errors',
        private readonly ?string $logEndpoint = null,
        private readonly ?string $logToken = null,
        private readonly string $logPath = '/v1/logs',
    ) {
        // Validate timeout
        if ($timeout < 0.5 || $timeout > 5.0) {
            throw new \InvalidArgumentException('Timeout must be between 0.5 and 5.0 seconds');
        }

        // Parse DSN and initialize readonly properties
        $parsed = $this->parseDsn($dsn, $errorPath);
        $this->endpoint = $parsed['endpoint'];
        $this->errorPath = $errorPath;
        $this->publicKey = $apiKey;

        // Allow injecting a client (tests / host-app framework.http_client with proxy/SSL
        // configuration). Fall back to a self-created client with aggressive timeouts.
        $this->httpClient = $httpClient ?? HttpClient::create([
            'timeout' => $this->timeout,
            'max_duration' => $this->timeout,
            'http_version' => '1.1', // HTTP/1.1 is more reliable than HTTP/2 for fire-and-forget
        ]);
    }

    public function __destruct()
    {
        // Best-effort drain so in-flight fire-and-forget requests complete and any
        // late transport failure is recorded. Never throws.
        $this->flushPendingResponses();
    }

    /**
     * Build API URL from path against the platform endpoint host.
     */
    private function buildApiUrl(string $path): string
    {
        return $this->buildUrl($this->endpoint, $path);
    }

    private function buildUrl(string $base, string $path): string
    {
        $host = parse_url($base, \PHP_URL_HOST);
        $scheme = parse_url($base, \PHP_URL_SCHEME);
        $port = parse_url($base, \PHP_URL_PORT);

        $hostWithPort = $host;
        if (null !== $port) {
            $hostWithPort .= ':'.$port;
        }

        return \sprintf('%s://%s%s', $scheme, $hostWithPort, $path);
    }

    /**
     * Get common API headers.
     *
     * @return array<string, string>
     */
    private function getApiHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->publicKey,
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];
    }

    /**
     * Send error payload to the platform (fire-and-forget).
     *
     * This method NEVER throws exceptions - all errors are caught and logged.
     *
     * @param array<string, mixed> $payload Error data to send
     */
    public function sendError(array $payload): void
    {
        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping error send');
            }

            return;
        }

        try {
            $payload['timestamp'] = $payload['timestamp'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
            $payload['platform'] = $payload['platform'] ?? 'symfony';

            $url = $this->buildApiUrl($this->errorPath);
            $this->dispatch($url, $payload, $this->getApiHeaders());
        } catch (\Throwable $e) {
            // CRITICAL: Never let exceptions bubble up. dispatch() already records
            // failure for transport errors it observes; this guards the encode/build path.
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Failed to send error', $e);
        }
    }

    /**
     * Ship a single non-exception log record to the log-collector ingestion endpoint.
     *
     * This is the LOG AGGREGATION path (ClickHouse via the Go collector), distinct
     * from sendError() which targets the error/PostgreSQL pipeline.
     *
     * Silently no-ops (returns false) when log aggregation is not configured.
     *
     * @param array<string, mixed> $logEntry LogEntry per the collector contract
     *
     * @return bool true if a request was dispatched
     */
    public function sendLog(array $logEntry): bool
    {
        return $this->sendLogs([$logEntry]);
    }

    /**
     * Ship a batch of log records to the collector's /batch endpoint in one request.
     *
     * The collector hard-caps batches at 1000; we chunk defensively. Empty input and
     * unconfigured log aggregation both no-op.
     *
     * @param array<int, array<string, mixed>> $logEntries
     *
     * @return bool true if at least one request was dispatched
     */
    public function sendLogs(array $logEntries): bool
    {
        if (null === $this->logEndpoint || null === $this->logToken) {
            // Log aggregation not configured - nothing to do (never an error).
            return false;
        }

        if ([] === $logEntries) {
            return false;
        }

        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping log send');
            }

            return false;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->logToken,
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];

        $dispatched = false;

        try {
            // A single entry uses the single endpoint; multiple entries use /batch.
            if (1 === \count($logEntries)) {
                $url = $this->buildUrl($this->logEndpoint, $this->logPath);
                $this->dispatch($url, reset($logEntries), $headers);

                return true;
            }

            $batchUrl = $this->buildUrl($this->logEndpoint, $this->logPath.'/batch');
            foreach (array_chunk($logEntries, 1000) as $chunk) {
                $this->dispatch($batchUrl, ['logs' => array_values($chunk)], $headers);
                $dispatched = true;
            }
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Failed to send logs', $e);
        }

        return $dispatched;
    }

    /**
     * Create or update a session.
     *
     * @param array<string, mixed> $sessionData
     */
    public function createSession(array $sessionData): void
    {
        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping session creation');
            }

            return;
        }

        try {
            $sessionData['started_at'] = $sessionData['started_at'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
            $this->dispatch($this->buildApiUrl('/api/v1/sessions'), $sessionData, $this->getApiHeaders());
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Failed to create session', $e);
        }
    }

    /**
     * Add event(s) to a session.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $eventData
     */
    public function addSessionEvent(string $sessionId, array $eventData): void
    {
        if ($this->circuitBreaker->isOpen()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping session event');
            }

            return;
        }

        try {
            $url = $this->buildApiUrl(\sprintf('/api/v1/sessions/%s/events', $sessionId));
            $this->dispatch($url, $eventData, $this->getApiHeaders());
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Failed to add session event', $e);
        }
    }

    /**
     * End a session.
     */
    public function endSession(string $sessionId, ?\DateTimeImmutable $endedAt = null): void
    {
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
            $url = $this->buildApiUrl(\sprintf('/api/v1/sessions/%s/end', $sessionId));
            $this->dispatch($url, $data, $this->getApiHeaders());
        } catch (\Throwable $e) {
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Failed to end session', $e);
        }
    }

    /**
     * Dispatch a POST request with full resilience.
     *
     * In SYNC mode: we read the status code now (blocking) and drive the circuit
     * breaker from it, with bounded exponential backoff retries.
     *
     * In ASYNC mode (default): we issue the request with 'buffer' => false and DO NOT
     * block. We try a single non-blocking poll of the transport to catch immediate
     * failures (e.g. connection refused / DNS) so the circuit breaker is not blind,
     * then retain the response so the handle stays alive and completes in the
     * background. We never call getStatusCode()/getContent() synchronously, so the
     * host application is never stalled and synchronous usleep() retries are skipped.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @throws ExceptionInterface on encode failure escalated by callers; transport
     *                            failures observed here are recorded, not re-thrown
     */
    private function dispatch(string $url, array $payload, array $headers): void
    {
        try {
            $jsonBody = json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Unencodable payload - drop it, do not penalise the circuit breaker.
            $this->logFailure('Failed to JSON encode payload', $e);

            return;
        }

        if ($this->async) {
            $this->dispatchAsync($url, $jsonBody, $headers);

            return;
        }

        $this->dispatchSync($url, $jsonBody, $headers, 0);
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatchAsync(string $url, string $jsonBody, array $headers): void
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $jsonBody,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
                // CRITICAL: without this the lazy CurlHttpClient may never transmit a
                // fire-and-forget request before the response is GC'd.
                'buffer' => false,
            ]);
        } catch (ExceptionInterface $e) {
            // Eagerly-thrown transport error (rare in async) - record and move on.
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Transport error dispatching request', $e);

            return;
        }

        // Non-blocking poll: ask the transport to make progress with a zero timeout.
        // This surfaces immediate connection failures so the circuit breaker can trip,
        // WITHOUT waiting for the full response (the host app is never blocked).
        try {
            foreach ($this->httpClient->stream($response, 0.0) as $chunk) {
                if ($chunk->isTimeout()) {
                    // Still in flight - good enough. Retain it and let it finish later.
                    break;
                }
                if ($chunk->isLast()) {
                    // Completed already (e.g. MockHttpClient): inspect the outcome now.
                    $this->recordOutcome($response);
                    break;
                }
                // Headers/first chunk arrived: inspect status without buffering body.
                $this->recordOutcome($response);
                break;
            }
        } catch (ExceptionInterface $e) {
            // Connection refused / DNS failure detected immediately.
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Transport error (async poll)', $e);

            return;
        }

        // Keep a reference so the request is not aborted by GC; drain opportunistically.
        $this->pendingResponses[] = $response;
        $this->reapPendingResponses();
    }

    /**
     * @param array<string, string> $headers
     */
    private function dispatchSync(string $url, string $jsonBody, array $headers, int $attempt): void
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body' => $jsonBody,
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
                'buffer' => false,
            ]);

            $this->recordOutcome($response);
        } catch (ExceptionInterface $e) {
            // Bounded retry ONLY in sync mode (async never blocks the host app).
            if ($attempt < $this->retryAttempts) {
                $delay = min(2, 2 ** $attempt);
                usleep((int) ($delay * 1_000_000));
                $this->dispatchSync($url, $jsonBody, $headers, $attempt + 1);

                return;
            }

            $this->circuitBreaker->recordFailure();
            $this->logFailure('Transport error after retries', $e);
        }
    }

    /**
     * Inspect a (completed or completing) response and drive the circuit breaker.
     * Reads only the status code, never buffers the body.
     */
    private function recordOutcome(ResponseInterface $response): void
    {
        try {
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 400) {
                $this->circuitBreaker->recordSuccess();
            } else {
                // 4xx/5xx: server-side rejection counts as a failure for the breaker.
                $this->circuitBreaker->recordFailure();
                if ($this->shouldLog()) {
                    $this->logger?->warning('ApplicationLogger: Unexpected status code', [
                        'status_code' => $status,
                    ]);
                }
            }
        } catch (ExceptionInterface $e) {
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Transport error reading response', $e);
        }
    }

    /**
     * Opportunistically reap already-completed pending responses without blocking.
     * Bounds memory under sustained logging by never letting the list grow unbounded.
     */
    private function reapPendingResponses(): void
    {
        if (\count($this->pendingResponses) < 32) {
            return;
        }

        // Over the soft cap: drain everything (each getInfo poll is non-blocking).
        $this->flushPendingResponses();
    }

    private function flushPendingResponses(): void
    {
        if ([] === $this->pendingResponses) {
            return;
        }

        $responses = $this->pendingResponses;
        $this->pendingResponses = [];

        foreach ($responses as $response) {
            try {
                // getInfo('http_code') does not block on an in-flight request; it
                // returns 0 until headers arrive. We record an outcome only if known.
                $code = $response->getInfo('http_code');
                if (\is_int($code) && $code > 0) {
                    if ($code >= 200 && $code < 400) {
                        $this->circuitBreaker->recordSuccess();
                    } else {
                        $this->circuitBreaker->recordFailure();
                    }
                }
            } catch (\Throwable) {
                // Never throw during cleanup.
            } finally {
                // Cancel so the (buffer=>false) response is fully torn down here and
                // cannot throw a deferred transport exception from its own destructor
                // during host-app shutdown.
                try {
                    $response->cancel();
                } catch (\Throwable) {
                    // ignore
                }
            }
        }
    }

    /**
     * Parse DSN into components.
     *
     * DSN format: {protocol}://{host}/{projectId}
     *
     * @return array{endpoint: string, projectId: string}
     *
     * @throws \InvalidArgumentException
     */
    private function parseDsn(string $dsn, string $errorPath): array
    {
        if (empty($dsn)) {
            throw new \InvalidArgumentException('ApplicationLogger DSN cannot be empty');
        }

        try {
            $url = parse_url($dsn);

            if (false === $url || !isset($url['scheme'], $url['host'], $url['path'])) {
                throw new \InvalidArgumentException('Invalid DSN format. Expected: https://host/project-id');
            }

            $projectId = trim($url['path'], '/');

            $host = $url['host'];
            if (isset($url['port'])) {
                $host .= ':'.$url['port'];
            }
            $endpoint = \sprintf('%s://%s%s', $url['scheme'], $host, $errorPath);

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

    private function logFailure(string $message, \Throwable $e): void
    {
        if ($this->shouldLog()) {
            $this->logger?->error('ApplicationLogger: '.$message, [
                'exception' => $e->getMessage(),
            ]);
        }
    }

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
