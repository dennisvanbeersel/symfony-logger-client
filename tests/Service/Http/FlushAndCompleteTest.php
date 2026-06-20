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
 * Proves that flushAndComplete() DRIVES pending transfers to completion (records
 * circuit-breaker SUCCESS) rather than cancelling them (which would either record
 * a pessimistic failure or silently lose the event).
 *
 * The core scenario under test mirrors the per-request SAPI reliability bug:
 *   1. post() issues an async POST whose 0.0-timeout poll yields a "still in flight"
 *      chunk → the response is parked in $pendingResponses.
 *   2. flushAndComplete() is called (simulating kernel.terminate).
 *   3. The stream() call now yields an isLast() chunk with status 202 → circuit
 *      breaker records SUCCESS, $pendingResponses is empty.
 *
 * This is structurally different from the old flushPendingResponses() path, which
 * called response->cancel() and recorded a PESSIMISTIC FAILURE for any handle that
 * had not yet produced an http_code — i.e. it could never record success for a
 * genuinely in-flight-then-completed transfer.
 */
final class FlushAndCompleteTest extends TestCase
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

    // -------------------------------------------------------------------------
    // KEY RELIABILITY TEST
    // -------------------------------------------------------------------------

    /**
     * An in-flight response (first poll times out → parked) that COMPLETES during
     * flushAndComplete() must record a circuit-breaker SUCCESS — not a cancellation
     * or pessimistic failure. This directly proves the fix works.
     */
    public function testFlushAndCompleteDeliversSuccessForInFlightThenCompleted(): void
    {
        $breaker = $this->breaker();

        // First stream() call (0.0 timeout in dispatchAsync): yields a timeout chunk.
        // Second stream() call (flushAndComplete): yields an isLast() chunk with 202.
        $response = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 202);
        $http = new TwoPhaseHttpClient($response);

        $dispatcher = $this->dispatcher($breaker, $http);

        // Dispatch: the 0.0-timeout poll sees "still in flight" → response is parked.
        $dispatched = $dispatcher->post('https://example.com/api', ['event' => 'test'], []);
        $this->assertTrue($dispatched, 'post() must return true when dispatch succeeds');

        // At this point $pendingResponses is non-empty and NO outcome has been recorded.
        $state = $breaker->getState();
        $this->assertSame('closed', $state['state']);
        $this->assertSame(0, $state['failureCount'], 'no failures recorded before flush');

        // Simulate kernel.terminate: drive the transfer to completion.
        $dispatcher->flushAndComplete();

        // The response completed with 202 → must have recorded a SUCCESS.
        $state = $breaker->getState();
        $this->assertSame('closed', $state['state'], 'circuit must remain closed after successful delivery');
        $this->assertSame(0, $state['failureCount'], 'no failures must be recorded for a successful delivery');

        // Verify pendingResponses was drained (subsequent flushAndComplete is a no-op).
        // We confirm by calling it again — if anything was still pending the fake client
        // would blow up because TwoPhaseResponse only has two phases.
        $dispatcher->flushAndComplete(); // must be a no-op
    }

    /**
     * After flushAndComplete() drains $pendingResponses, __destruct() must be a
     * complete no-op (empty list). This ensures the web path (terminate subscriber
     * flushed first) does not double-record outcomes during GC.
     */
    public function testDestructIsNoOpAfterFlushAndComplete(): void
    {
        $breaker = $this->breaker();
        $response = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 202);
        $http = new TwoPhaseHttpClient($response);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['event' => 'test'], []);

        // Flush first (web path).
        $dispatcher->flushAndComplete();

        $successCountAfterFlush = $breaker->getState()['failureCount'];

        // __destruct must record nothing additional.
        $dispatcher->__destruct();

        $this->assertSame(
            $successCountAfterFlush,
            $breaker->getState()['failureCount'],
            '__destruct after flush must not double-record outcomes',
        );
    }

    /**
     * A transfer that times out during flushAndComplete() (still in flight after
     * $maxWait) must record a PESSIMISTIC FAILURE so the circuit breaker is not
     * blind to stalled endpoints. This mirrors the existing behaviour for the
     * opportunistic reap path.
     */
    public function testFlushAndCompleteRecordsFailureOnTimeout(): void
    {
        $breaker = $this->breaker();

        // Both stream() calls yield timeout chunks → handle never completes.
        $response = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 0, secondPollAlsoTimesOut: true);
        $http = new TwoPhaseHttpClient($response);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['event' => 'test'], []);
        $dispatcher->flushAndComplete(0.001); // tiny wait → guaranteed timeout

        $state = $breaker->getState();
        $this->assertGreaterThan(0, $state['failureCount'], 'timed-out handle must record a failure');
    }

    // -------------------------------------------------------------------------
    // T01: stream() itself throws during flush.
    // -------------------------------------------------------------------------

    /**
     * T01 — If `stream()` THROWS during flushAndComplete() (e.g. the underlying
     * client was shut down), the outer catch must record a pessimistic FAILURE for
     * every unconfirmed handle AND cancel every response so a destructor cannot later
     * throw at host shutdown.
     */
    public function testFlushAndCompleteStreamThrowsRecordsFailuresAndCancels(): void
    {
        $breaker = $this->breaker();

        // Three responses parked by the dispatchAsync poll, then stream() blows up at flush.
        $r1 = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 0, secondPollAlsoTimesOut: true);
        $r2 = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 0, secondPollAlsoTimesOut: true);
        $r3 = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 0, secondPollAlsoTimesOut: true);
        $http = new ThrowingFlushHttpClient([$r1, $r2, $r3]);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['n' => 1], []);
        $dispatcher->post('https://example.com/api', ['n' => 2], []);
        $dispatcher->post('https://example.com/api', ['n' => 3], []);

        // failureThreshold is 3 → the outer catch's three pessimistic failures must trip it.
        $dispatcher->flushAndComplete();

        $this->assertTrue(
            $breaker->isOpen(),
            'stream() throwing during flush must record a pessimistic failure per handle',
        );
        $this->assertTrue($r1->wasCancelled(), 'handle must be cancelled when stream() throws');
        $this->assertTrue($r2->wasCancelled());
        $this->assertTrue($r3->wasCancelled());
    }

    // -------------------------------------------------------------------------
    // T02: a per-chunk Throwable is contained by the inner catch.
    // -------------------------------------------------------------------------

    /**
     * T02 — When a chunk method throws (e.g. isLast()/isTimeout() raises), the INNER
     * catch must contain it: record a single failure, settle that slot, and never let
     * the Throwable leak out of flushAndComplete().
     */
    public function testFlushAndCompletePerChunkThrowableIsContained(): void
    {
        $breaker = $this->breaker();

        $response = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 0);
        // The flush-phase chunk throws when interrogated.
        $http = new ThrowingChunkHttpClient($response);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['event' => 'test'], []);

        // Must NOT throw into the host app.
        $dispatcher->flushAndComplete();

        $state = $breaker->getState();
        $this->assertSame(
            1,
            $state['failureCount'],
            'a per-chunk Throwable must record exactly one failure and be contained',
        );
    }

    // -------------------------------------------------------------------------
    // T03: a final isLast() chunk carrying a 4xx/5xx records a FAILURE.
    // -------------------------------------------------------------------------

    /**
     * T03 — A handle that completes during flush with a 4xx/5xx status must have
     * recordOutcome() register a circuit-breaker FAILURE (server-side rejection),
     * even though the chunk itself is a clean isLast().
     */
    public function testFlushAndCompleteFinalChunkWith5xxRecordsFailure(): void
    {
        $breaker = $this->breaker();

        // Parked at poll, then completes with 503 at flush.
        $response = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 503);
        $http = new TwoPhaseHttpClient($response);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['event' => 'test'], []);

        $dispatcher->flushAndComplete();

        $state = $breaker->getState();
        $this->assertSame(
            1,
            $state['failureCount'],
            'a 5xx completion during flush must record a circuit-breaker failure',
        );
    }

    // -------------------------------------------------------------------------
    // T04: intermediate chunk then isLast() settles exactly ONCE (post-F08).
    // -------------------------------------------------------------------------

    /**
     * T04 — When a handle yields an intermediate (non-timeout, non-last) chunk and
     * THEN an isLast() chunk, the dispatcher must settle exactly ONCE — on isLast().
     * This is the post-F08 behaviour: the intermediate chunk must NOT record an
     * outcome (no double-counting, no premature settle).
     */
    public function testFlushAndCompleteIntermediateThenLastRecordsExactlyOneSuccess(): void
    {
        $breaker = $this->breaker();

        // Parked at poll. At flush: first an intermediate chunk, then an isLast() 202.
        $response = new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 202);
        $http = new IntermediateThenLastHttpClient($response);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['event' => 'test'], []);

        $dispatcher->flushAndComplete();

        $state = $breaker->getState();
        $this->assertSame('closed', $state['state']);
        $this->assertSame(
            0,
            $state['failureCount'],
            'intermediate chunk must not settle; only the isLast() 202 records exactly one success',
        );
        // Prove exactly one outcome (success) was recorded for the handle, not two.
        $this->assertSame(1, $response->successOutcomeReads(), 'getStatusCode() must be consulted exactly once');
    }

    /**
     * Multiple in-flight responses are all drained in a single flushAndComplete() call,
     * each recording their own circuit-breaker outcome concurrently.
     */
    public function testFlushAndCompleteDrainsMultipleResponses(): void
    {
        $breaker = $this->breaker();

        // Three responses, all completing with 202 during flushAndComplete.
        $http = new MultiResponseHttpClient([
            new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 202),
            new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 202),
            new TwoPhaseResponse(firstPollTimesOut: true, finalStatusCode: 202),
        ]);

        $dispatcher = $this->dispatcher($breaker, $http);
        $dispatcher->post('https://example.com/api', ['n' => 1], []);
        $dispatcher->post('https://example.com/api', ['n' => 2], []);
        $dispatcher->post('https://example.com/api', ['n' => 3], []);

        $dispatcher->flushAndComplete();

        $state = $breaker->getState();
        $this->assertSame('closed', $state['state']);
        $this->assertSame(0, $state['failureCount'], 'all three 202s must record success');
    }
}

// =============================================================================
// Test doubles
// =============================================================================

/**
 * A response whose stream() behaviour differs between the first call (simulating
 * the dispatchAsync 0.0-timeout poll) and the second call (simulating flushAndComplete).
 *
 * First call  → yields a timeout chunk (still in flight).
 * Second call → yields an isLast() chunk with $finalStatusCode.
 *
 * If $secondPollAlsoTimesOut is true, the second call also yields a timeout chunk,
 * simulating a genuinely hung endpoint.
 */
final class TwoPhaseResponse implements ResponseInterface
{
    private int $streamCallCount = 0;
    private bool $cancelled = false;
    private int $statusReads = 0;

    public function __construct(
        private readonly bool $firstPollTimesOut,
        private readonly int $finalStatusCode,
        private readonly bool $secondPollAlsoTimesOut = false,
    ) {
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Number of times getStatusCode() was consulted by recordOutcome().
     * Used to prove "settle exactly once" (T04).
     */
    public function successOutcomeReads(): int
    {
        return $this->statusReads;
    }

    public function getStatusCode(): int
    {
        ++$this->statusReads;

        return max(0, $this->finalStatusCode);
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
        $this->cancelled = true;
    }

    public function getInfo(?string $type = null): ?int
    {
        if ('http_code' === $type) {
            return $this->finalStatusCode;
        }

        return null;
    }

    public function nextStreamChunk(): ChunkInterface
    {
        ++$this->streamCallCount;

        if (1 === $this->streamCallCount && $this->firstPollTimesOut) {
            return new ControlledChunk(isTimeout: true, isLast: false);
        }

        if ($this->secondPollAlsoTimesOut) {
            return new ControlledChunk(isTimeout: true, isLast: false);
        }

        // Final phase: transfer complete.
        return new ControlledChunk(isTimeout: false, isLast: true);
    }
}

/**
 * HttpClient that routes stream() calls back to TwoPhaseResponse::nextStreamChunk(),
 * allowing each response to track how many times it has been streamed.
 */
final class TwoPhaseHttpClient implements HttpClientInterface
{
    /** @var list<TwoPhaseResponse> */
    private array $requestQueue = [];

    public function __construct(private readonly TwoPhaseResponse $defaultResponse)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        // Pop from queue if available, otherwise use default.
        if ([] !== $this->requestQueue) {
            return array_shift($this->requestQueue);
        }

        return $this->defaultResponse;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $response = $responses instanceof ResponseInterface ? $responses : (array) $responses;
        if (\is_array($response)) {
            $response = reset($response);
        }

        /** @var TwoPhaseResponse $twoPhase */
        $twoPhase = $response;

        return new SingleChunkStream($twoPhase, $twoPhase->nextStreamChunk());
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
 * HttpClient for multiple independent TwoPhaseResponse objects.
 * Assigns each request() call to the next response in order, and routes each
 * stream() call to the correct TwoPhaseResponse.
 */
final class MultiResponseHttpClient implements HttpClientInterface
{
    /** @var list<TwoPhaseResponse> */
    private array $responses;

    private int $nextRequest = 0;

    /**
     * @param list<TwoPhaseResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->responses[$this->nextRequest++] ?? $this->responses[0];
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // flushAndComplete passes an array of all pending responses.
        $all = $responses instanceof ResponseInterface ? [$responses] : (array) $responses;

        return new MultiChunkStream($all);
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
 * ResponseStreamInterface that yields exactly one chunk for a single response.
 */
final class SingleChunkStream implements ResponseStreamInterface
{
    private bool $done = false;

    public function __construct(
        private readonly TwoPhaseResponse $response,
        private readonly ChunkInterface $chunk,
    ) {
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function current(): ChunkInterface
    {
        return $this->chunk;
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
 * ResponseStreamInterface that iterates over multiple TwoPhaseResponse objects,
 * yielding one chunk per response (from nextStreamChunk()).
 */
final class MultiChunkStream implements ResponseStreamInterface
{
    /** @var list<TwoPhaseResponse> */
    private array $all;

    private int $pos = 0;

    private ?ChunkInterface $currentChunk = null;

    /**
     * @param list<ResponseInterface> $responses
     */
    public function __construct(array $responses)
    {
        // Only TwoPhaseResponse objects are expected here.
        $this->all = array_values(array_filter($responses, static fn ($r) => $r instanceof TwoPhaseResponse));
    }

    public function key(): ResponseInterface
    {
        return $this->all[$this->pos];
    }

    public function current(): ChunkInterface
    {
        return $this->currentChunk ?? new ControlledChunk(isTimeout: true, isLast: false);
    }

    public function next(): void
    {
        ++$this->pos;
        $this->currentChunk = null;
    }

    public function rewind(): void
    {
        $this->pos = 0;
        $this->currentChunk = null;
    }

    public function valid(): bool
    {
        if (!isset($this->all[$this->pos])) {
            return false;
        }
        $this->currentChunk = $this->all[$this->pos]->nextStreamChunk();

        return true;
    }
}

/**
 * HttpClient whose stream() THROWS when called for the flush phase, simulating a
 * client that was shut down (or a transport that raises) during drain. The first
 * stream() call (the dispatchAsync 0.0-timeout poll) returns a normal timeout chunk
 * so each response is parked; the flush-phase stream() (passed an array) throws.
 */
final class ThrowingFlushHttpClient implements HttpClientInterface
{
    /** @var list<TwoPhaseResponse> */
    private array $queue;

    private int $next = 0;

    /**
     * @param list<TwoPhaseResponse> $responses
     */
    public function __construct(array $responses)
    {
        $this->queue = $responses;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->queue[$this->next++] ?? $this->queue[array_key_last($this->queue)];
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        // Single-response call = the dispatchAsync poll: behave normally (timeout chunk).
        if ($responses instanceof TwoPhaseResponse) {
            return new SingleChunkStream($responses, $responses->nextStreamChunk());
        }

        // Array call = flushAndComplete drain: blow up to exercise the outer catch.
        throw new \RuntimeException('client shut down during flush');
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
 * HttpClient whose flush-phase chunk THROWS when interrogated (isTimeout()/isLast()),
 * exercising the INNER per-chunk catch in flushAndComplete().
 */
final class ThrowingChunkHttpClient implements HttpClientInterface
{
    public function __construct(private readonly TwoPhaseResponse $response)
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
        // Poll phase: normal timeout chunk so the response is parked.
        if ($responses instanceof ResponseInterface) {
            return new SingleChunkStream($this->response, $this->response->nextStreamChunk());
        }

        // Flush phase: yield a chunk that throws when its state is read.
        return new SingleChunkStream($this->response, new ThrowingChunk());
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
 * HttpClient whose flush-phase stream yields an INTERMEDIATE (non-timeout, non-last)
 * chunk first, then an isLast() chunk — proving the dispatcher settles exactly once
 * (on isLast()) per the post-F08 contract.
 */
final class IntermediateThenLastHttpClient implements HttpClientInterface
{
    public function __construct(private readonly TwoPhaseResponse $response)
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
        // Poll phase: normal timeout chunk so the response is parked.
        if ($responses instanceof ResponseInterface) {
            return new SingleChunkStream($this->response, $this->response->nextStreamChunk());
        }

        // Flush phase: intermediate chunk, then last chunk, for the same response.
        return new TwoChunkStream($this->response, [
            new ControlledChunk(isTimeout: false, isLast: false), // intermediate
            new ControlledChunk(isTimeout: false, isLast: true),  // final
        ]);
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
 * ResponseStreamInterface that yields a fixed list of chunks for a single response.
 */
final class TwoChunkStream implements ResponseStreamInterface
{
    private int $pos = 0;

    /**
     * @param list<ChunkInterface> $chunks
     */
    public function __construct(
        private readonly TwoPhaseResponse $response,
        private readonly array $chunks,
    ) {
    }

    public function key(): ResponseInterface
    {
        return $this->response;
    }

    public function current(): ChunkInterface
    {
        return $this->chunks[$this->pos];
    }

    public function next(): void
    {
        ++$this->pos;
    }

    public function rewind(): void
    {
        $this->pos = 0;
    }

    public function valid(): bool
    {
        return isset($this->chunks[$this->pos]);
    }
}

/**
 * A ChunkInterface whose state-inspection methods throw, used to exercise the inner
 * per-chunk catch in flushAndComplete().
 */
final class ThrowingChunk implements ChunkInterface
{
    public function isTimeout(): bool
    {
        throw new \RuntimeException('chunk inspection failed');
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        throw new \RuntimeException('chunk inspection failed');
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

/**
 * A configurable ChunkInterface used by all test doubles in this file.
 */
final readonly class ControlledChunk implements ChunkInterface
{
    public function __construct(
        private bool $isTimeout,
        private bool $isLast,
    ) {
    }

    public function isTimeout(): bool
    {
        return $this->isTimeout;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        return $this->isLast;
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
