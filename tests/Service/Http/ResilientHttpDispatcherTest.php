<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Http;

use ApplicationLogger\Bundle\Service\CircuitBreaker;
use ApplicationLogger\Bundle\Service\Http\ResilientHttpDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Focused tests for the deterministic async circuit-breaker outcome (I6).
 *
 * The bundle's resilience contract requires that, in async (fire-and-forget) mode,
 * the breaker is NEVER blind to a request's fate: an immediate failure trips it, a
 * confirmed 2xx records success (so HALF_OPEN recovery is not flaky), and a request
 * that is still in flight when we force-drain (slow 5xx / hang) records a PESSIMISTIC
 * failure rather than silently recording nothing.
 */
final class ResilientHttpDispatcherTest extends TestCase
{
    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            enabled: true,
            failureThreshold: 3,
            timeout: 60,
            maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
        );
    }

    private function dispatcher(CircuitBreaker $breaker, HttpClientInterface $http): ResilientHttpDispatcher
    {
        return new ResilientHttpDispatcher(
            timeout: 2.0,
            retryAttempts: 0,
            async: true,
            circuitBreaker: $breaker,
            logger: null,
            debug: false,
            httpClient: $http,
            enabled: true,
        );
    }

    public function testInFlightSlow5xxRecordsPessimisticFailureAtDrain(): void
    {
        // The poll sees the request as still in flight (timeout chunk), so it is
        // parked. At drain the handle still has no http_code (slow 5xx / hang): the
        // dispatcher must record a FAILURE, not silently skip it.
        $breaker = $this->breaker();
        // http_code stays 0 -> "could not confirm delivery".
        $http = new FakeHttpClient(new FakeResponse(httpCode: 0, streamTimesOut: true));

        $dispatcher = $this->dispatcher($breaker, $http);

        // Three in-flight requests; failureThreshold is 3 -> breaker must trip after drain.
        $dispatcher->post('https://x/y', ['a' => 1], []);
        $dispatcher->post('https://x/y', ['a' => 2], []);
        $dispatcher->post('https://x/y', ['a' => 3], []);

        // Force the drain that records the pessimistic outcomes.
        $dispatcher->__destruct();

        $this->assertTrue($breaker->isOpen(), 'slow/hung async requests must trip the breaker at drain');
    }

    public function testSuccessfulAsyncSendRecordsSuccess(): void
    {
        // A confirmed 2xx (poll completes immediately) must record success so a
        // HALF_OPEN probe can close the circuit deterministically.
        $breaker = $this->breaker();
        $http = new FakeHttpClient(new FakeResponse(httpCode: 202, streamTimesOut: false));

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://x/y', ['ok' => true], []);

        $state = $breaker->getState();
        $this->assertSame('closed', $state['state']);
        $this->assertSame(0, $state['failureCount']);
    }

    /**
     * REQ-04 — Customer-protection guard: in async mode, post() against a
     * never-completing response (the poll only ever sees a timeout chunk, the handle
     * never resolves) must return PROMPTLY. The host hot path is non-blocking and
     * bounded — it parks the handle and returns rather than waiting for completion.
     */
    public function testAsyncPostAgainstNeverCompletingResponseReturnsPromptly(): void
    {
        $breaker = $this->breaker();
        // streamTimesOut: true -> the poll always sees "still in flight"; the handle
        // never completes. http_code stays 0 forever.
        $http = new FakeHttpClient(new FakeResponse(httpCode: 0, streamTimesOut: true));

        $dispatcher = $this->dispatcher($breaker, $http);

        $start = microtime(true);
        $dispatched = $dispatcher->post('https://x/y', ['event' => 'test'], []);
        $elapsed = microtime(true) - $start;

        $this->assertTrue($dispatched, 'post() must dispatch (and park) the in-flight handle');
        // Generous ceiling: the host path must not block on the 2.0s transport timeout.
        // A non-blocking park returns in well under a millisecond; 0.5s leaves ample
        // slack for CI jitter while still failing hard if post() ever waits for the
        // full transfer/timeout.
        $this->assertLessThan(
            0.5,
            $elapsed,
            'async post() must return promptly (non-blocking) even when the response never completes',
        );
    }

    public function testInFlightThenConfirmed5xxAtDrainRecordsFailure(): void
    {
        // In flight at poll time, but the handle DOES expose a 5xx code at drain.
        $breaker = $this->breaker();
        $http = new FakeHttpClient(new FakeResponse(httpCode: 503, streamTimesOut: true));

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://x/y', ['a' => 1], []);
        $dispatcher->post('https://x/y', ['a' => 2], []);
        $dispatcher->post('https://x/y', ['a' => 3], []);
        $dispatcher->__destruct();

        $this->assertTrue($breaker->isOpen(), 'confirmed 5xx at drain must trip the breaker');
    }

    private function syncDispatcher(
        CircuitBreaker $breaker,
        HttpClientInterface $http,
        int $retryAttempts,
    ): ResilientHttpDispatcher {
        return new ResilientHttpDispatcher(
            timeout: 2.0,
            retryAttempts: $retryAttempts,
            async: false,
            circuitBreaker: $breaker,
            logger: null,
            debug: false,
            httpClient: $http,
            enabled: true,
        );
    }

    /**
     * SYNC-RETRY: a transient transport-read failure (the lazy connection/DNS/timeout
     * error that only surfaces when getStatusCode() reads the response) followed by a
     * success must be RETRIED and end in success. Previously recordOutcome() swallowed
     * the read-exception, so dispatchSync() could not tell a transport failure from a
     * server status and never retried — the bounded sync retry was dead for this most
     * common failure class.
     */
    public function testSyncTransientTransportFailureThenSuccessIsRetried(): void
    {
        $breaker = $this->breaker();
        // First read throws a TransportException; second read returns 202.
        $http = new SequencedHttpClient([
            new ReadFailureResponse(),       // attempt 0 -> transport-read failure
            new StatusResponse(202),         // attempt 1 -> success
        ]);

        $dispatcher = $this->syncDispatcher($breaker, $http, retryAttempts: 2);

        $result = $dispatcher->post('https://x/y', ['event' => 'test'], []);

        $this->assertTrue($result, 'post() reports a request was dispatched');
        $this->assertSame(2, $http->requestCount, 'the failed attempt must be retried exactly once');

        $state = $breaker->getState();
        $this->assertSame('closed', $state['state'], 'a retried-then-succeeded send must leave the breaker closed');
        $this->assertSame(0, $state['failureCount'], 'success after retry records no net failure');
    }

    /**
     * SYNC-RETRY: a PERSISTENT transport-read failure must be retried up to the bound
     * and then degrade SILENTLY (never throw into the host), recording exactly ONE
     * breaker failure for the whole logical send (no double-counting across retries).
     */
    public function testSyncPersistentTransportFailureIsBoundedThenSilent(): void
    {
        $breaker = $this->breaker();
        // Every read throws -> all attempts fail.
        $http = new SequencedHttpClient([
            new ReadFailureResponse(),
            new ReadFailureResponse(),
            new ReadFailureResponse(),
            new ReadFailureResponse(),
        ]);

        $dispatcher = $this->syncDispatcher($breaker, $http, retryAttempts: 2);

        // Must NOT throw into the host application.
        $result = $dispatcher->post('https://x/y', ['event' => 'test'], []);

        $this->assertTrue($result, 'post() never throws and reports dispatch even on persistent failure');
        // attempt 0 + 2 retries = 3 total attempts.
        $this->assertSame(3, $http->requestCount, 'attempts must be bounded to 1 + retryAttempts');

        $state = $breaker->getState();
        $this->assertSame(1, $state['failureCount'], 'one logical send records exactly one breaker failure');
    }
}

/**
 * HttpClient that returns a pre-seeded SEQUENCE of responses (one per request()).
 * Lets a test simulate "fail then succeed" across the sync bounded-retry loop.
 */
final class SequencedHttpClient implements HttpClientInterface
{
    public int $requestCount = 0;

    /** @param list<ResponseInterface> $responses */
    public function __construct(private array $responses)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->responses[$this->requestCount] ?? end($this->responses);
        ++$this->requestCount;

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw new \LogicException('stream() is not used on the sync dispatch path');
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

/**
 * Response whose getStatusCode() throws a TransportException — simulating a lazy
 * connection/DNS/timeout error that only surfaces when the status is read (the case
 * the sync bounded retry previously could not see).
 */
final class ReadFailureResponse implements ResponseInterface
{
    public function getStatusCode(): int
    {
        throw new TransportException('Connection refused');
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        return null;
    }
}

/**
 * Response that returns a fixed HTTP status code from getStatusCode() (no stream()).
 */
final class StatusResponse implements ResponseInterface
{
    public function __construct(private readonly int $statusCode)
    {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        if ('http_code' === $type) {
            return $this->statusCode;
        }

        return null;
    }
}

/**
 * Minimal HttpClient that always returns a single pre-built fake response.
 */
final class FakeHttpClient implements HttpClientInterface
{
    public function __construct(private readonly FakeResponse $response)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $response = $this->response;

        return new class($response) implements ResponseStreamInterface {
            public function __construct(private readonly FakeResponse $response)
            {
            }

            public function key(): ResponseInterface
            {
                return $this->response;
            }

            public function current(): ChunkInterface
            {
                return $this->response->chunk();
            }

            public function next(): void
            {
            }

            public function rewind(): void
            {
            }

            private bool $done = false;

            public function valid(): bool
            {
                if ($this->done) {
                    return false;
                }
                $this->done = true;

                return true;
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

/**
 * Minimal ResponseInterface whose stream() chunk can be made to "time out" (still in
 * flight) or be "last" (complete), and whose getInfo('http_code') is configurable to
 * simulate an unknown/known outcome at drain.
 */
final class FakeResponse implements ResponseInterface
{
    public function __construct(
        private readonly int $httpCode,
        private readonly bool $streamTimesOut,
    ) {
    }

    public function chunk(): ChunkInterface
    {
        return new FakeChunk($this->streamTimesOut);
    }

    public function getStatusCode(): int
    {
        return $this->httpCode > 0 ? $this->httpCode : 0;
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        if ('http_code' === $type) {
            return $this->httpCode;
        }

        return null;
    }
}

final class FakeChunk implements ChunkInterface
{
    public function __construct(private readonly bool $timeout)
    {
    }

    public function isTimeout(): bool
    {
        return $this->timeout;
    }

    public function isFirst(): bool
    {
        return !$this->timeout;
    }

    public function isLast(): bool
    {
        // When not a timeout, treat the single chunk as the last one so the poll
        // inspects the outcome immediately (the "confirmed 2xx" path).
        return !$this->timeout;
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return '';
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return null;
    }
}
