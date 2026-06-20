<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use ApplicationLogger\Bundle\Service\Http\ResilientHttpDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Client for sending errors AND logs to the Application Logger platform.
 *
 * This is a THIN endpoint facade. Each public method only builds the target URL,
 * the JSON-able payload and the auth headers, then delegates to the
 * {@see ResilientHttpDispatcher}, which owns the entire transport concern:
 * the single guarded dispatch envelope (enabled kill-switch + circuit-breaker
 * guard + try/catch + record-failure), async/sync POST, retry/backoff, and the
 * fire-and-forget cURL handle lifecycle. See that class for the resilience
 * guarantees (never blocks the host, never throws, drives the breaker).
 *
 * PUBLIC API STABILITY: the six endpoint methods + getCircuitBreakerState() and
 * this class name are part of the bundle's contract (the host app ships a
 * decorator extending this class). They MUST NOT change.
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
    /** Collector hard-caps batches at 1000; we chunk defensively to match. */
    private const MAX_LOG_BATCH_SIZE = 1000;

    private readonly string $endpoint;
    private readonly string $errorPath;
    private readonly string $publicKey;
    private readonly ResilientHttpDispatcher $dispatcher;
    /**
     * Dedicated transport for the LOG-aggregation path so it carries its OWN circuit
     * breaker, independent of the error/session path. Without this, a healthy platform
     * (error/session 2xx) keeps resetting a shared breaker and masks a failing log
     * collector, so log sends never shed load. Falls back to the main dispatcher when
     * no separate log breaker is wired (BC).
     */
    private readonly ResilientHttpDispatcher $logDispatcher;
    private readonly float $timeout;

    public function __construct(
        string $dsn,
        string $apiKey,
        float $timeout,
        int $retryAttempts,
        bool $async,
        CircuitBreaker $circuitBreaker,
        ?LoggerInterface $logger,
        bool $debug = false,
        ?HttpClientInterface $httpClient = null,
        string $errorPath = '/api/v1/errors',
        private readonly ?string $logEndpoint = null,
        private readonly ?string $logToken = null,
        private readonly string $logPath = '/v1/logs',
        bool $enabled = true,
        ?CircuitBreaker $logCircuitBreaker = null,
    ) {
        // Validate timeout
        if ($timeout < 0.5 || $timeout > 5.0) {
            throw new \InvalidArgumentException('Timeout must be between 0.5 and 5.0 seconds');
        }

        // Parse DSN and initialize readonly properties.
        $this->timeout = $timeout;
        $this->endpoint = $this->parseDsnEndpoint($dsn, $errorPath);
        $this->errorPath = $errorPath;
        $this->publicKey = $apiKey;

        // Share ONE underlying HttpClient across BOTH dispatchers. When the host app
        // injects its framework.http_client we already share it. When it does NOT, each
        // dispatcher would otherwise call HttpClient::create() and spin up its OWN
        // CurlHttpClient — two independent cURL connection pools/multi-handles for the
        // same install. Create a single client here (with the dispatcher's own aggressive
        // fire-and-forget options) and pass it to both, so the error/session and log
        // paths reuse one connection pool. The dispatchers KEEP their independent circuit
        // breakers; only the transport is shared.
        $sharedHttpClient = $httpClient ?? HttpClient::create([
            'timeout' => $timeout,
            'max_duration' => $timeout,
            'http_version' => '1.1', // HTTP/1.1 is more reliable than HTTP/2 for fire-and-forget
        ]);

        // The dispatcher owns all transport machinery. We construct it here from the
        // raw transport args so the bundle's services.yaml + the host decorator (which
        // never calls parent::__construct) keep working unchanged.
        $this->dispatcher = new ResilientHttpDispatcher(
            timeout: $timeout,
            retryAttempts: $retryAttempts,
            async: $async,
            circuitBreaker: $circuitBreaker,
            logger: $logger,
            debug: $debug,
            httpClient: $sharedHttpClient,
            enabled: $enabled,
        );

        // The log-aggregation path gets its OWN dispatcher+breaker when a separate log
        // breaker is wired, so a failing collector trips an INDEPENDENT breaker instead
        // of being masked by healthy error/session traffic on a shared one. When not
        // wired (older config / host decorator), logs reuse the main dispatcher (BC).
        // Either way it reuses the SAME shared HttpClient (single connection pool).
        $this->logDispatcher = (null !== $logCircuitBreaker)
            ? new ResilientHttpDispatcher(
                timeout: $timeout,
                retryAttempts: $retryAttempts,
                async: $async,
                circuitBreaker: $logCircuitBreaker,
                logger: $logger,
                debug: $debug,
                httpClient: $sharedHttpClient,
                enabled: $enabled,
            )
            : $this->dispatcher;
    }

    /**
     * Send error payload to the platform (fire-and-forget).
     *
     * This method NEVER throws exceptions - all errors are caught in the dispatcher.
     *
     * @param array<string, mixed> $payload Error data to send
     */
    public function sendError(array $payload): void
    {
        // Inert when unconfigured: no DSN means no platform endpoint to POST to.
        if ('' === $this->endpoint) {
            return;
        }

        $payload['timestamp'] = $payload['timestamp'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);
        $payload['platform'] = $payload['platform'] ?? 'symfony';

        $this->dispatcher->post($this->buildApiUrl($this->errorPath), $payload, $this->getApiHeaders());
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
        // Treat null AND empty-string as "unconfigured": env placeholders resolve to ''
        // (not null) when the var is unset, so guard on both to cleanly no-op rather than
        // build a malformed URL and penalise the log circuit breaker.
        if (\in_array($this->logEndpoint, [null, ''], true) || \in_array($this->logToken, [null, ''], true)) {
            // Log aggregation not configured - nothing to do (never an error).
            return false;
        }

        if ([] === $logEntries) {
            return false;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'X-Api-Key' => $this->logToken,
            'User-Agent' => 'ApplicationLogger-Symfony-Bundle/1.0',
        ];

        // A single entry uses the single endpoint; multiple entries use /batch.
        // Routed through the dedicated log dispatcher (independent circuit breaker).
        if (1 === \count($logEntries)) {
            return $this->logDispatcher->post(
                $this->buildUrl($this->logEndpoint, $this->logPath),
                reset($logEntries),
                $headers,
            );
        }

        $batchUrl = $this->buildUrl($this->logEndpoint, $this->logPath.'/batch');
        $dispatched = false;
        foreach (array_chunk($logEntries, self::MAX_LOG_BATCH_SIZE) as $chunk) {
            $this->logDispatcher->post($batchUrl, ['logs' => array_values($chunk)], $headers);
            $dispatched = true;
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
        $sessionData['started_at'] = $sessionData['started_at'] ?? (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM);

        $this->dispatcher->post($this->buildApiUrl('/api/v1/sessions'), $sessionData, $this->getApiHeaders());
    }

    /**
     * Add event(s) to a session.
     *
     * @param array<string, mixed>|array<int, array<string, mixed>> $eventData
     */
    public function addSessionEvent(string $sessionId, array $eventData): void
    {
        $url = $this->buildApiUrl(\sprintf('/api/v1/sessions/%s/events', $sessionId));

        $this->dispatcher->post($url, $eventData, $this->getApiHeaders());
    }

    /**
     * End a session.
     */
    public function endSession(string $sessionId, ?\DateTimeImmutable $endedAt = null): void
    {
        $data = [
            'ended_at' => ($endedAt ?? new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ];
        $url = $this->buildApiUrl(\sprintf('/api/v1/sessions/%s/end', $sessionId));

        $this->dispatcher->post($url, $data, $this->getApiHeaders());
    }

    /**
     * Drive all pending async transfers to completion, blocking until each one either
     * finishes or the timeout expires.
     *
     * Intended to be called from {@see FlushTelemetrySubscriber} on `kernel.terminate`
     * (i.e. AFTER the response has been sent to the client) so that fire-and-forget
     * telemetry is reliably delivered in per-request SAPIs (PHP-FPM, FrankenPHP
     * non-worker mode) without ever blocking the user-visible response.
     *
     * The post-response flush is capped at min(configured_timeout, 2.0) seconds so
     * a misconfigured or slow backend cannot cause excessive post-response latency
     * even when the dispatcher's configured timeout is higher.
     *
     * Safe to call when no requests are pending (no-op). Never throws.
     */
    public function flush(): void
    {
        // Cap at 2 s: the post-response flush must not stall FPM worker recycling.
        $cap = min($this->timeout, 2.0);
        $this->dispatcher->flushAndComplete($cap);
        // Drain the dedicated log dispatcher too (no-op if it is the same instance or
        // has no pending handles).
        if ($this->logDispatcher !== $this->dispatcher) {
            $this->logDispatcher->flushAndComplete($cap);
        }
    }

    /**
     * Get circuit breaker state for monitoring.
     *
     * @return array{state: string, failureCount: int, openedAt: int|null, halfOpenAttempts: int}
     */
    public function getCircuitBreakerState(): array
    {
        return $this->dispatcher->getCircuitBreakerState();
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
        // Parse once and read the components from the assoc array, rather than
        // calling parse_url() three times on the same string (IDIOM-01). Output
        // is byte-identical to the previous component-specific calls.
        //
        // parse_url() returns false on a severely malformed URL; array access on a
        // bool is a PHP 8.1+ deprecation. $base can be config-sourced (a misconfigured
        // log_endpoint), so guard against false and fall back to returning the base
        // with the path appended verbatim rather than emitting "://".
        $parts = parse_url($base);
        if (false === $parts) {
            return $base.$path;
        }
        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;
        $port = $parts['port'] ?? null;

        // A scheme-less or host-less $base (e.g. a misconfigured log_endpoint like
        // "host/path" — parse_url() treats that as a path, not a host — or "//host"
        // with no scheme) would otherwise sprintf into a broken "://host" /
        // "https://". Fall back to appending the path verbatim, mirroring the
        // parse_url()===false guard above (no-op, never emits a malformed URL).
        if (null === $scheme || null === $host) {
            return $base.$path;
        }

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
     * Parse the DSN into the platform endpoint base URL.
     *
     * NOTE: This is the CLIENT-INTERNAL parser, deliberately distinct from the
     * host-facing DSN generation the platform's project-provisioning
     * commands/fixtures perform. They serve opposite directions of the same
     * format and live on opposite sides of the wire (platform vs. client bundle).
     *
     * DSN format: {protocol}://{host}/{projectId}. We validate that a project-id
     * path segment is present (it guards malformed DSNs) but do not otherwise use
     * it - the public key is sent separately via the X-Api-Key header.
     *
     * An empty DSN returns '' (unconfigured install → inert). A non-empty but
     * malformed DSN is an active misconfiguration and still throws.
     *
     * @throws \InvalidArgumentException on a non-empty, malformed DSN
     */
    private function parseDsnEndpoint(string $dsn, string $errorPath): string
    {
        // An empty DSN means the bundle was installed but not configured (e.g. the
        // Flex recipe was skipped/auto-generated). Stay inert rather than throw: the
        // constructor must never break the host's container build (resilience rule #1).
        // sendError() short-circuits on the resulting empty endpoint. A NON-empty but
        // malformed DSN is still rejected below — that is an active misconfiguration.
        if ('' === $dsn) {
            return '';
        }

        try {
            $url = parse_url($dsn);

            if (false === $url || !isset($url['scheme'], $url['host'], $url['path'])) {
                throw new \InvalidArgumentException('Invalid DSN format. Expected: https://host/project-id');
            }

            if ('' === trim($url['path'], '/')) {
                throw new \InvalidArgumentException('DSN must include project ID in path');
            }

            $host = $url['host'];
            if (isset($url['port'])) {
                $host .= ':'.$url['port'];
            }

            return \sprintf('%s://%s%s', $url['scheme'], $host, $errorPath);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                \sprintf('Invalid DSN format: %s. Expected: https://host/project-id', $e->getMessage()),
                0,
                $e
            );
        }
    }
}
