<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service\Http;

use ApplicationLogger\Bundle\Service\CircuitBreaker;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Owns ALL outbound transport machinery for the bundle: the single guarded
 * dispatch envelope, async/sync POST, bounded retry/backoff, circuit-breaker
 * interaction, and the fire-and-forget cURL handle lifecycle.
 *
 * @internal Not part of the bundle's public API; subject to change without notice.
 *
 * Extracted from ApiClient (SRP) so the latter is a thin endpoint facade that
 * only builds URL + payload + headers and hands them here. Every outbound path
 * funnels through {@see post()}, which means the circuit-breaker guard, the
 * `enabled` kill-switch, the try/catch resilience envelope and the
 * record-failure-on-error contract all live in exactly ONE place.
 *
 * RESILIENCE GUARANTEES (unchanged from the previous in-ApiClient logic):
 * - Never blocks the host application in async mode (no synchronous usleep retry).
 * - Never throws to the caller; all transport/encode failures are caught.
 * - Drives the circuit breaker from observed transport/HTTP outcomes, including
 *   async sends (immediate failures via a non-blocking poll, late outcomes at drain).
 *
 * SYNC-MODE WORST CASE: in sync mode {@see post()} blocks. Each attempt may take up
 * to `timeout` seconds, and a failed attempt sleeps up to {@see MAX_BACKOFF_SECONDS}
 * before the next try. With N = retryAttempts retries the worst-case stall is
 * roughly `(N + 1) * timeout + N * MAX_BACKOFF_SECONDS` seconds. Async mode (the
 * default) never incurs this and is what the bundle ships with.
 */
final class ResilientHttpDispatcher
{
    /**
     * Soft cap on retained fire-and-forget responses before we opportunistically
     * drain the whole list. Bounds memory under sustained logging without ever
     * blocking the host (each drain poll is non-blocking).
     */
    private const PENDING_RESPONSES_SOFT_CAP = 32;

    /** Upper bound (seconds) on a single exponential-backoff sleep in sync mode. */
    private const MAX_BACKOFF_SECONDS = 2;

    /** Microseconds per second, for the usleep() conversion in sync backoff. */
    private const MICROSECONDS_PER_SECOND = 1_000_000;

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
        private readonly float $timeout,
        private readonly int $retryAttempts,
        private readonly bool $async,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly ?LoggerInterface $logger,
        private readonly bool $debug = false,
        ?HttpClientInterface $httpClient = null,
        private readonly bool $enabled = true,
    ) {
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
        // Best-effort drive-to-completion for in-flight fire-and-forget requests.
        // Uses a small bounded timeout so CLI/worker contexts (where kernel.terminate
        // never fires) still deliver telemetry. If the terminate subscriber already
        // flushed (web path), $pendingResponses is empty and this is a no-op.
        // Never throws during shutdown.
        $this->flushAndComplete(min($this->timeout, 1.0));
    }

    /**
     * Drive all pending fire-and-forget transfers to completion (or timeout).
     *
     * Unlike {@see flushPendingResponses()} which only reads `getInfo('http_code')` then
     * cancels (pessimistically marking unfinished handles as failures), this method
     * ACTIVELY polls the HTTP transport via `stream()` until every handle reaches
     * `isLast()` or the per-handle timeout expires. This is the key to reliable
     * delivery in per-request SAPIs (PHP-FPM, PHP built-in server, FrankenPHP
     * non-worker mode): the cURL transfer only progresses when the client is polled,
     * so without this the GC-cancel in `flushPendingResponses()` aborted the POST
     * before a single byte was transmitted.
     *
     * Caller contract:
     *   - MUST be invoked AFTER the response is already sent to the user (e.g. from
     *     {@see FlushTelemetrySubscriber} on `kernel.terminate`, or from `__destruct`
     *     for CLI/worker contexts). This method blocks — briefly, by at most
     *     `$maxWait` seconds — and must never run on the hot request path.
     *   - Never throws; all transport errors are caught and recorded on the breaker.
     *   - If called when `$pendingResponses` is empty, returns immediately (no-op).
     *
     * @param float|null $maxWait Idle/inactivity timeout passed to each `stream()` poll:
     *                            if no chunk arrives within this window the handle is
     *                            considered timed-out and a pessimistic failure is
     *                            recorded. It is NOT a single total budget for the whole
     *                            flush; in practice one poll loop drains all in-flight
     *                            handles concurrently so the actual wall-clock wait is
     *                            close to $maxWait regardless of the number of handles.
     *                            Null uses the dispatcher's configured timeout.
     */
    public function flushAndComplete(?float $maxWait = null): void
    {
        if ([] === $this->pendingResponses) {
            return;
        }

        $wait = $maxWait ?? $this->timeout;
        $responses = $this->pendingResponses;
        $this->pendingResponses = [];

        // Count pending handles per object identity. The real CurlHttpClient always
        // returns distinct response objects, but test doubles may return the same
        // instance for multiple requests. We track counts so each logical request
        // (even duplicates) is accounted for independently.
        /** @var array<int, int> $pendingCount object-id → remaining count */
        $pendingCount = [];
        foreach ($responses as $response) {
            $id = spl_object_id($response);
            $pendingCount[$id] = ($pendingCount[$id] ?? 0) + 1;
        }

        // Total unresolved handles; decremented as each one is settled.
        $unresolved = \count($responses);

        try {
            // stream() multiplexes ALL handles concurrently; each iteration yields one
            // ($response, $chunk) pair. We stop when every handle has produced an
            // isLast() chunk (or a timeout/error), i.e. $unresolved drops to zero.
            foreach ($this->httpClient->stream($responses, $wait) as $response => $chunk) {
                $id = spl_object_id($response);

                // Only process if we still expect chunks for this response.
                if (!isset($pendingCount[$id]) || $pendingCount[$id] <= 0) {
                    continue;
                }

                try {
                    if ($chunk->isTimeout()) {
                        // Handle did not complete within $wait; record pessimistic failure.
                        $this->circuitBreaker->recordFailure();
                        --$pendingCount[$id];
                        --$unresolved;
                        continue;
                    }

                    if ($chunk->isLast()) {
                        // Transfer complete; read the final status.
                        $this->recordOutcome($response);
                        --$pendingCount[$id];
                        --$unresolved;
                        continue;
                    }

                    // Intermediate chunk (headers received but body still streaming).
                    // Do NOT settle here — wait for isLast() so we record exactly one
                    // outcome per handle and never miss the final HTTP status code.
                    // Stream() will keep yielding chunks for this handle until isLast().
                } catch (\Throwable) {
                    // Per-chunk error: record failure and settle this slot.
                    $this->circuitBreaker->recordFailure();
                    --$pendingCount[$id];
                    --$unresolved;
                }

                if ($unresolved <= 0) {
                    break;
                }
            }
        } catch (\Throwable) {
            // stream() itself threw (e.g. client shut down). Record a pessimistic
            // failure for every handle we could not confirm.
            $unresolved = 0;
            foreach ($pendingCount as $count) {
                for ($i = 0; $i < $count; ++$i) {
                    $this->circuitBreaker->recordFailure();
                }
            }
            $pendingCount = [];
            // Cancel every handle so CurlResponse::__destruct() does not later try to
            // initialize an unconsumed response and throw a TransportException from a
            // destructor (a fatal-level error at host shutdown). Mirrors the cancel in
            // flushPendingResponses()'s finally block.
            foreach ($responses as $response) {
                try {
                    $response->cancel();
                } catch (\Throwable) {
                    // ignore — never throw during cleanup
                }
            }
        }

        // Any handles that never produced a settling chunk (e.g. stream ended early):
        // record pessimistic failures and cancel to free cURL resources.
        if ($unresolved > 0) {
            foreach ($responses as $response) {
                $id = spl_object_id($response);
                if (isset($pendingCount[$id]) && $pendingCount[$id] > 0) {
                    $this->circuitBreaker->recordFailure();
                    --$pendingCount[$id];
                    try {
                        $response->cancel();
                    } catch (\Throwable) {
                        // ignore
                    }
                }
            }
        }
    }

    /**
     * The ONE guarded dispatch entry point for every endpoint.
     *
     * Collapses the formerly-duplicated `isOpen()-guard + try/catch + recordFailure`
     * envelope that lived in each ApiClient endpoint method into a single place,
     * and hosts the `enabled` kill-switch so a disabled install never POSTs
     * regardless of which subscriber/handler triggered it.
     *
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return bool true if a request was actually dispatched (false when disabled,
     *              circuit-open, or the payload could not be encoded)
     */
    public function post(string $url, array $payload, array $headers): bool
    {
        // Single runtime gate for ALL outbound telemetry. `enabled` is commonly an env
        // placeholder (%env(bool:APPLICATION_LOGGER_ENABLED)%) that cannot be resolved when
        // the container compiles, so the bundle's services are registered regardless; this
        // ensures a disabled install (e.g. a fresh recipe install before the user opts in)
        // never POSTs to the placeholder host, no matter which subscriber/handler triggered it.
        if (!$this->enabled) {
            return false;
        }

        // Encode BEFORE consuming a circuit-breaker slot. An unencodable payload must
        // neither penalise the breaker NOR consume a HALF_OPEN probe slot: allowRequest()
        // increments+persists halfOpenAttempts, and a slot consumed here without a later
        // recordSuccess()/recordFailure() (because we bail on JsonException) would, now
        // that HALF_OPEN admission is capped, leave the breaker wedged in HALF_OPEN until
        // the cache entry expires. Encoding first sidesteps that entirely.
        try {
            $jsonBody = json_encode($payload, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Unencodable payload - drop it, do not penalise or perturb the circuit breaker.
            $this->logFailure('Failed to JSON encode payload', $e);

            return false;
        }

        if (!$this->circuitBreaker->allowRequest()) {
            if ($this->shouldLog()) {
                $this->logger?->debug('ApplicationLogger: Circuit breaker is open, skipping send');
            }

            return false;
        }

        try {
            if ($this->async) {
                $this->dispatchAsync($url, $jsonBody, $headers);
            } else {
                $this->dispatchSync($url, $jsonBody, $headers, 0);
            }
        } catch (\Throwable $e) {
            // CRITICAL: Never let exceptions bubble up. The dispatch helpers already
            // record failure for transport errors they observe; this is the last-resort
            // guard for anything unexpected on the build/dispatch path.
            $this->circuitBreaker->recordFailure();
            $this->logFailure('Failed to dispatch request', $e);

            return false;
        }

        return true;
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

                    return;
                }
                // Headers/first chunk arrived: inspect status without buffering body.
                $this->recordOutcome($response);

                return;
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
                $delay = min(self::MAX_BACKOFF_SECONDS, 2 ** $attempt);
                usleep((int) ($delay * self::MICROSECONDS_PER_SECOND));
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
            // getStatusCode() behaviour depends on the caller:
            //  - async fast path: stream() has already yielded a non-timeout chunk, so
            //    CurlHttpClient has cached http_code via its header-parse callback and
            //    cleared the response initializer -> returns the status immediately
            //    (non-blocking). This is what keeps the host request fast.
            //  - sync path: intentionally blocks up to `timeout` until headers arrive.
            // Never add getContent()/body reads here: that would block the host request.
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
        if (\count($this->pendingResponses) < self::PENDING_RESPONSES_SOFT_CAP) {
            return;
        }

        // Over the soft cap: drain everything (each getInfo poll is non-blocking).
        $this->flushPendingResponses();
    }

    /**
     * Drain retained fire-and-forget responses and record a DETERMINISTIC breaker
     * outcome for each.
     *
     * Previously a handle that had not yet produced an http_code at drain time was
     * simply cancelled, recording NEITHER success nor failure - so a slow 5xx or a
     * hung connection in async mode never tripped the breaker. Now:
     *   - a known 2xx/3xx records success (so HALF_OPEN recovery is not flaky),
     *   - a known 4xx/5xx records failure,
     *   - an UNKNOWN code (handle force-cancelled / still incomplete) records a
     *     PESSIMISTIC failure: we could not confirm delivery, so we assume the worst.
     */
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
                // returns 0 until headers arrive.
                $code = $response->getInfo('http_code');
                if (\is_int($code) && $code > 0) {
                    if ($code >= 200 && $code < 400) {
                        $this->circuitBreaker->recordSuccess();
                    } else {
                        $this->circuitBreaker->recordFailure();
                    }
                } else {
                    // Outcome could not be confirmed before we force-cancel the handle
                    // below (slow 5xx / hang). Fail pessimistically so the breaker is
                    // never blind to a stalled endpoint.
                    $this->circuitBreaker->recordFailure();
                }
            } catch (\Throwable) {
                // Never throw during cleanup; treat an unreadable handle as a failure.
                $this->circuitBreaker->recordFailure();
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
     * Expose the circuit-breaker state for monitoring (proxied through ApiClient).
     *
     * @return array{state: string, failureCount: int, openedAt: int|null, halfOpenAttempts: int}
     */
    public function getCircuitBreakerState(): array
    {
        return $this->circuitBreaker->getState();
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
}
