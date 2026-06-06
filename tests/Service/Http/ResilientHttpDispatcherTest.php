<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service\Http;

use ApplicationLogger\Bundle\Service\CircuitBreaker;
use ApplicationLogger\Bundle\Service\Http\ResilientHttpDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
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
