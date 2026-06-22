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
 * Proves the progress-aware breaker rule (spec §g): an unsettled handle at the
 * drain deadline is classified by non-blocking getInfo so a healthy-but-slow
 * collector is NOT penalised, while a genuinely dead one trips the breaker.
 */
final class ProgressAwareBreakerTest extends TestCase
{
    private function breaker(int $threshold = 1): CircuitBreaker
    {
        return new CircuitBreaker(
            enabled: true, failureThreshold: $threshold, timeout: 60,
            maxHalfOpenAttempts: 1, cache: new ArrayAdapter(),
        );
    }

    private function dispatcher(CircuitBreaker $breaker, HttpClientInterface $http): ResilientHttpDispatcher
    {
        return new ResilientHttpDispatcher(
            timeout: 2.0, retryAttempts: 0, async: true, circuitBreaker: $breaker,
            logger: null, debug: false, httpClient: $http, enabled: true, flushBudget: 0.5,
        );
    }

    /** http_code 202 at the deadline → SUCCESS (server accepted), no failure. */
    public function testAcceptedStatusRecordsSuccess(): void
    {
        $breaker = $this->breaker();
        $response = new ProgressResponse(httpCode: 202, connectTime: 0.01, primaryIp: '203.0.113.1');
        $dispatcher = $this->dispatcher($breaker, new ProgressHttpClient($response));

        $dispatcher->post('https://example.com/api', ['e' => 1], []);
        $dispatcher->flushAndComplete(0.001); // never reaches isLast → settled at deadline

        self::assertSame(0, $breaker->getState()['failureCount'], '2xx must not record a failure');
        self::assertTrue($response->wasCancelled());
    }

    /** http_code 0 but TCP connected → DROP without recording (alive but slow). */
    public function testConnectedButNoStatusDropsWithoutFailure(): void
    {
        $breaker = $this->breaker();
        $response = new ProgressResponse(httpCode: 0, connectTime: 0.02, primaryIp: '203.0.113.2');
        $dispatcher = $this->dispatcher($breaker, new ProgressHttpClient($response));

        $dispatcher->post('https://example.com/api', ['e' => 1], []);
        $dispatcher->flushAndComplete(0.001);

        self::assertSame(0, $breaker->getState()['failureCount'], 'alive-but-slow must NOT trip the breaker');
        self::assertSame('closed', $breaker->getState()['state']);
        self::assertTrue($response->wasCancelled());
    }

    /** http_code 0 and never connected → FAILURE (dead/unreachable). */
    public function testNeverConnectedRecordsFailure(): void
    {
        $breaker = $this->breaker();
        $response = new ProgressResponse(httpCode: 0, connectTime: 0.0, primaryIp: '');
        $dispatcher = $this->dispatcher($breaker, new ProgressHttpClient($response));

        $dispatcher->post('https://example.com/api', ['e' => 1], []);
        $dispatcher->flushAndComplete(0.001);

        self::assertGreaterThan(0, $breaker->getState()['failureCount'], 'dead collector must trip the breaker');
    }

    /** 503 at the deadline → FAILURE (server rejection). */
    public function testServerErrorRecordsFailure(): void
    {
        $breaker = $this->breaker();
        $response = new ProgressResponse(httpCode: 503, connectTime: 0.01, primaryIp: '203.0.113.3');
        $dispatcher = $this->dispatcher($breaker, new ProgressHttpClient($response));

        $dispatcher->post('https://example.com/api', ['e' => 1], []);
        $dispatcher->flushAndComplete(0.001);

        self::assertGreaterThan(0, $breaker->getState()['failureCount'], '5xx must record a failure');
    }

    /**
     * Soft-cap reap (Fix 1 regression): 32 CONNECTED-but-slow handles trigger
     * reapPendingResponses() → flushPendingResponses(), which now delegates to
     * settleUnconfirmed(). http_code=0 but connect_time>0 must DROP without recording,
     * so the breaker stays CLOSED with failureCount=0.
     *
     * With the old pessimistic inline code every handle recorded recordFailure();
     * a threshold-1 breaker would immediately open.
     */
    public function testSoftCapReapWithConnectedHandlesDoesNotTripBreaker(): void
    {
        $breaker = $this->breaker(threshold: 1); // one failure trips it — strict guard

        // 32 connected-but-slow responses: http_code 0, TCP established.
        $responses = [];
        for ($i = 0; $i < 32; ++$i) {
            $responses[] = new ProgressResponse(httpCode: 0, connectTime: 0.01, primaryIp: '203.0.113.10');
        }
        $http = new MultiProgressHttpClient($responses);

        $dispatcher = new ResilientHttpDispatcher(
            timeout: 2.0, retryAttempts: 0, async: true, circuitBreaker: $breaker,
            logger: null, debug: false, httpClient: $http, enabled: true, flushBudget: 0.5,
        );

        // Each post() parks one handle; the 32nd triggers the soft-cap reap.
        for ($i = 0; $i < 32; ++$i) {
            $dispatcher->post('https://example.com/api', ['n' => $i], []);
        }

        self::assertSame(0, $breaker->getState()['failureCount'], 'alive-but-slow handles must not trip the breaker via the soft-cap reap');
        self::assertSame('closed', $breaker->getState()['state']);
    }

    /** HALF_OPEN probe that does not complete → FAILURE (must resolve the state machine). */
    public function testHalfOpenProbeAliveButSlowRecordsFailure(): void
    {
        $breaker = $this->breaker(threshold: 1);
        $breaker->recordFailure();        // → OPEN
        // Force OPEN→HALF_OPEN by simulating the elapsed timeout via allowRequest is not
        // possible without time travel; instead drive the breaker to half-open directly:
        $breaker->forceHalfOpenForTesting();
        self::assertTrue($breaker->isHalfOpen());

        // A connected-but-slow probe (would be "dropped" in CLOSED) MUST fail in HALF_OPEN.
        $response = new ProgressResponse(httpCode: 0, connectTime: 0.02, primaryIp: '203.0.113.9');
        $dispatcher = $this->dispatcher($breaker, new ProgressHttpClient($response));

        $dispatcher->post('https://example.com/api', ['e' => 1], []);
        $dispatcher->flushAndComplete(0.001);

        self::assertTrue($breaker->isOpen(), 'a non-completing HALF_OPEN probe must re-open the breaker');
    }
}

/**
 * A response that always reports "in flight" on stream() (timeout chunk) but exposes
 * configurable getInfo() values, so the deadline path classifies it via getInfo.
 */
final class ProgressResponse implements ResponseInterface
{
    private bool $cancelled = false;

    public function __construct(
        private readonly int $httpCode,
        private readonly float $connectTime,
        private readonly string $primaryIp,
    ) {
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getStatusCode(): int
    {
        return max(0, $this->httpCode);
    }

    public function getHeaders(bool $throw = true): array
    {
        return [];
    }

    public function getContent(bool $throw = true): string
    {
        return '';
    }

    /** @return array<string, mixed> */
    public function toArray(bool $throw = true): array
    {
        return [];
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function getInfo(?string $type = null): mixed
    {
        return match ($type) {
            'http_code' => $this->httpCode,
            'connect_time' => $this->connectTime,
            'primary_ip' => $this->primaryIp,
            default => null,
        };
    }

    public function nextChunk(): ChunkInterface
    {
        return new ProgressChunk();
    }
}

/** Always "still in flight". */
final class ProgressChunk implements ChunkInterface
{
    public function isTimeout(): bool
    {
        return true;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return false;
    }

    /** @return array{0: int, 1: string}|null */
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

final class ProgressHttpClient implements HttpClientInterface
{
    public function __construct(private readonly ProgressResponse $response)
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return new ProgressStream($this->response);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}

final class ProgressStream implements ResponseStreamInterface
{
    private bool $done = false;

    public function __construct(private readonly ProgressResponse $response)
    {
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function current(): ChunkInterface
    {
        return $this->response->nextChunk();
    }

    public function next(): void
    {
    }

    public function rewind(): void
    {
    }

    public function valid(): bool
    {
        if ($this->done) {
            return false;
        }
        $this->done = true;

        return true;
    }
}

/**
 * HttpClient that serves a pool of ProgressResponse objects (one per request()) and
 * routes stream() to the correct response. Used by the soft-cap reap regression test.
 */
final class MultiProgressHttpClient implements HttpClientInterface
{
    private int $nextRequest = 0;

    /** @param list<ProgressResponse> $responses */
    public function __construct(private readonly array $responses)
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->responses[$this->nextRequest++] ?? $this->responses[0];
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $response = $responses;
        } else {
            $arr = (array) $responses;
            $response = reset($arr);
        }

        /** @var ProgressResponse $progress */
        $progress = $response;

        return new ProgressStream($progress);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
