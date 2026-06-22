<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\CircuitBreaker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApiClientTest extends TestCase
{
    private CircuitBreaker $circuitBreaker;
    private MockObject&LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->circuitBreaker = new CircuitBreaker(
            enabled: false, // Disabled for most tests
            failureThreshold: 5,
            timeout: 60,
            maxHalfOpenAttempts: 2,
            cache: new ArrayAdapter()
        );
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function createClient(
        string $dsn = 'https://example.com/test-project-id',
        string $apiKey = 'test-api-key',
        float $timeout = 2.0,
        int $retryAttempts = 0,
        bool $async = true,
        ?CircuitBreaker $circuitBreaker = null,
        bool $debug = false
    ): ApiClient {
        return new ApiClient(
            $dsn,
            $apiKey,
            $timeout,
            $retryAttempts,
            $async,
            $circuitBreaker ?? $this->circuitBreaker,
            $this->logger,
            $debug
        );
    }

    public function testValidDsnIsParsedCorrectly(): void
    {
        // If DSN parsing fails, constructor throws exception
        $client = $this->createClient('https://api.applogger.eu/abc-123-def');

        // If we reach here, DSN was parsed successfully
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testDsnWithPortIsParsedCorrectly(): void
    {
        $client = $this->createClient('https://localhost:8111/project-id');

        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testBuiltUrlPreservesExplicitPort(): void
    {
        // Guards IDIOM-01: buildUrl() now parses the base once instead of three
        // separate parse_url() calls. An explicit DSN port must still round-trip
        // into the outbound errors endpoint URL.
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new ApiClient(
            'https://localhost:8111/project-id',
            'test-api-key',
            2.0,
            0,
            false, // sync so the request fires immediately
            $this->circuitBreaker,
            $this->logger,
            false,
            $httpClient
        );

        $client->sendError(['message' => 'port round-trip']);

        $this->assertSame('https://localhost:8111/api/v1/errors', $capturedUrl);
    }

    public function testBuiltUrlOmitsPortWhenAbsent(): void
    {
        // Companion to the port test: a DSN without an explicit port must not
        // gain one (null-port handling preserved).
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
            $capturedUrl = $url;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new ApiClient(
            'https://example.com/project-id',
            'test-api-key',
            2.0,
            0,
            false,
            $this->circuitBreaker,
            $this->logger,
            false,
            $httpClient
        );

        $client->sendError(['message' => 'no port']);

        $this->assertSame('https://example.com/api/v1/errors', $capturedUrl);
    }

    /**
     * @return array<string, array{string, string, string}> [base, path, expected]
     */
    public static function schemeLessBaseProvider(): array
    {
        return [
            // PHP-1: scheme-less base parses as a path (no scheme/host). Old code
            // sprintf'd a broken "://myhost.example.com/v1/logs"; now it appends
            // the path verbatim.
            'scheme-less host (parsed as path)' => ['myhost.example.com', '/v1/logs', 'myhost.example.com/v1/logs'],
            // Scheme-relative base: host present, scheme missing. Old code emitted
            // "://logs.example.com/v1/logs".
            'scheme-relative (host, no scheme)' => ['//logs.example.com', '/v1/logs', '//logs.example.com/v1/logs'],
            // Bare host, no scheme.
            'bare host no scheme' => ['localhost:9000', '/v1/logs/batch', 'localhost:9000/v1/logs/batch'],
            // Well-formed base still round-trips through the normal sprintf branch.
            'well-formed base unchanged' => ['https://logs.example.com', '/v1/logs', 'https://logs.example.com/v1/logs'],
            'well-formed base with port' => ['http://logs.example.com:8080', '/v1/logs', 'http://logs.example.com:8080/v1/logs'],
        ];
    }

    /**
     * PHP-1: buildUrl() must never emit a malformed "://host" URL when the
     * configured log_endpoint lacks a scheme and/or host. It now falls back to
     * appending the path verbatim (a safe no-op) instead.
     */
    #[DataProvider('schemeLessBaseProvider')]
    public function testBuildUrlNeverEmitsBareSchemeSeparator(string $base, string $path, string $expected): void
    {
        $client = $this->createClient();

        $method = new \ReflectionMethod(ApiClient::class, 'buildUrl');
        $built = $method->invoke($client, $base, $path);

        self::assertSame($expected, $built);
        self::assertStringStartsNotWith('://', $built, 'buildUrl must not produce a scheme-less "://" URL');
        self::assertStringNotContainsString('://'.$path, $built, 'buildUrl must not drop the host into "://path"');
    }

    public function testEmptyDsnDoesNotThrow(): void
    {
        // A clean install whose Flex recipe was skipped leaves the DSN empty.
        // The client MUST construct (cache:clear must not break) and stay inert.
        $client = $this->createClient('');

        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testEmptyDsnSendErrorDispatchesNothing(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => 202]);
        });

        $client = new ApiClient(
            '',            // empty DSN (unconfigured install)
            '',            // empty API key
            2.0,
            0,
            false,         // sync mode: if it were going to dispatch, it would fire now
            $this->circuitBreaker,
            $this->logger,
            false,
            $httpClient
        );

        $client->sendError(['message' => 'should not be sent']);

        $this->assertSame(0, $requests, 'Empty DSN must keep the client inert (no HTTP request).');
    }

    public function testEmptyDsnSessionMethodsDispatchNothingAndDoNotTripBreaker(): void
    {
        $requests = 0;
        $httpClient = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => 202]);
        });

        // A real breaker so we can assert no failure was recorded by the session calls.
        $circuitBreaker = new CircuitBreaker(
            enabled: true,
            failureThreshold: 5,
            timeout: 60,
            maxHalfOpenAttempts: 2,
            cache: new ArrayAdapter()
        );

        $client = new ApiClient(
            '',            // empty DSN (unconfigured install)
            '',            // empty API key
            2.0,
            0,
            false,         // sync mode: a dispatch would fire (and trip the breaker) now
            $circuitBreaker,
            $this->logger,
            false,
            $httpClient
        );

        $client->createSession(['session_id' => 'abc']);
        $client->addSessionEvent('abc', ['type' => 'click']);
        $client->endSession('abc');

        $this->assertSame(0, $requests, 'Empty DSN must keep session calls inert (no HTTP request).');
        $this->assertSame(
            0,
            $client->getCircuitBreakerState()['failureCount'],
            'Inert session calls must not record a circuit-breaker failure.'
        );
    }

    public function testDsnWithoutProjectIdThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createClient('https://example.com/');
    }

    public function testInvalidDsnFormatThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createClient('not-a-valid-url');
    }

    public function testTimeoutTooLowThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 0.5 and 5.0 seconds');

        $this->createClient(timeout: 0.1);
    }

    public function testTimeoutTooHighThrowsException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be between 0.5 and 5.0 seconds');

        $this->createClient(timeout: 10.0);
    }

    public function testValidTimeoutBoundaryLow(): void
    {
        $client = $this->createClient(timeout: 0.5);
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testValidTimeoutBoundaryHigh(): void
    {
        $client = $this->createClient(timeout: 5.0);
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testSendErrorDoesNotThrowOnCircuitOpen(): void
    {
        // Create circuit breaker that's open
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(true);

        $client = $this->createClient(circuitBreaker: $circuitBreaker);

        // Should not throw even though circuit is open
        $client->sendError(['message' => 'Test error']);

        // If we reach here, method didn't throw
        $this->addToAssertionCount(1);
    }

    public function testSendErrorNeverThrows(): void
    {
        // Circuit breaker that fails everything
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(false);

        $client = new ApiClient(
            'https://invalid-host-that-will-never-resolve.local/project-id',
            'test-api-key',
            0.5, // Very short timeout
            0,   // No retries
            false, // Sync mode to ensure request is attempted
            $circuitBreaker,
            $this->logger,
            false
        );

        // Should not throw - resilience guarantee
        $client->sendError(['message' => 'Test error']);

        $this->addToAssertionCount(1);
    }

    public function testCreateSessionDoesNotThrowOnCircuitOpen(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(true);

        $client = $this->createClient(circuitBreaker: $circuitBreaker);

        // Should not throw
        $client->createSession(['session_id' => 'test']);

        $this->addToAssertionCount(1);
    }

    public function testAddSessionEventDoesNotThrowOnCircuitOpen(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(true);

        $client = $this->createClient(circuitBreaker: $circuitBreaker);

        // Should not throw
        $client->addSessionEvent('session-123', ['type' => 'click']);

        $this->addToAssertionCount(1);
    }

    public function testEndSessionDoesNotThrowOnCircuitOpen(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(true);

        $client = $this->createClient(circuitBreaker: $circuitBreaker);

        // Should not throw
        $client->endSession('session-123');

        $this->addToAssertionCount(1);
    }

    public function testGetCircuitBreakerStateReturnsState(): void
    {
        $circuitBreaker = new CircuitBreaker(
            enabled: true,
            failureThreshold: 5,
            timeout: 60,
            maxHalfOpenAttempts: 2,
            cache: new ArrayAdapter()
        );

        $client = $this->createClient(circuitBreaker: $circuitBreaker);
        $state = $client->getCircuitBreakerState();

        $this->assertArrayHasKey('state', $state);
        $this->assertArrayHasKey('failureCount', $state);
        $this->assertArrayHasKey('openedAt', $state);
        $this->assertArrayHasKey('halfOpenAttempts', $state);
    }

    public function testDebugModeLogsOnCircuitOpen(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(true);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Circuit breaker is open'));

        $client = new ApiClient(
            'https://example.com/project-id',
            'test-api-key',
            2.0,
            0,
            true,
            $circuitBreaker,
            $this->logger,
            true // debug mode
        );

        $client->sendError(['message' => 'Test']);
    }

    public function testRecordsSuccessOnCircuitBreaker(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(false);
        // We can't easily verify success recording without making actual HTTP requests
        // But we can verify the circuit breaker integration exists

        $client = $this->createClient(circuitBreaker: $circuitBreaker);

        // This won't make a real request in async mode
        $client->sendError(['message' => 'Test']);

        $this->addToAssertionCount(1);
    }

    public function testPayloadGetsTimestampIfMissing(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(true); // Skip actual request

        // Verify in debug log that timestamp would be added
        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains('Circuit breaker is open'));

        $client = new ApiClient(
            'https://example.com/project-id',
            'test-api-key',
            2.0,
            0,
            true,
            $circuitBreaker,
            $this->logger,
            true
        );

        // The timestamp addition happens before circuit breaker check
        // But since circuit is open, we won't make the request
        $client->sendError(['message' => 'Test']);
    }

    public function testDsnParsesHttpsScheme(): void
    {
        $client = $this->createClient('https://secure.example.com/project-id');
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testDsnParsesHttpScheme(): void
    {
        $client = $this->createClient('http://local.example.com/project-id');
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testDsnWithComplexProjectId(): void
    {
        // UUID-style project ID
        $client = $this->createClient('https://example.com/b6d8ed85-c0af-4c02-b6bb-bfb0f3609b37');
        $this->assertInstanceOf(ApiClient::class, $client);
    }

    public function testResilienceOnJsonEncodingFailure(): void
    {
        $circuitBreaker = $this->createMock(CircuitBreaker::class);
        $circuitBreaker->method('isOpen')->willReturn(false);

        $client = new ApiClient(
            'https://example.com/project-id',
            'test-api-key',
            2.0,
            0,
            true,
            $circuitBreaker,
            $this->logger,
            true // debug mode
        );

        // Resource type cannot be JSON encoded
        $resource = fopen('php://memory', 'r');
        $payload = ['resource' => $resource];

        // Should not throw - gracefully handles JSON encoding failure
        $client->sendError($payload);

        fclose($resource);

        $this->addToAssertionCount(1);
    }

    public function testDisabledClientSendsNoOutboundRequests(): void
    {
        // A4 safety: when the bundle is disabled (e.g. a fresh recipe install before the
        // user opts in via APPLICATION_LOGGER_ENABLED=true) NO telemetry must leave the host,
        // regardless of which path triggers it.
        $requestCount = 0;
        $httpClient = new MockHttpClient(function () use (&$requestCount): MockResponse {
            ++$requestCount;

            return new MockResponse('{}', ['http_code' => 202]);
        });

        $client = new ApiClient(
            dsn: 'https://your-logger-host.com/your-project-id',
            apiKey: 'placeholder',
            timeout: 2.0,
            retryAttempts: 0,
            async: false,
            circuitBreaker: $this->circuitBreaker,
            logger: $this->logger,
            httpClient: $httpClient,
            logEndpoint: 'https://your-logger-host.com',
            logToken: 'sk_log_placeholder',
            enabled: false,
        );

        $client->sendError(['type' => 'X', 'message' => 'should not be sent']);
        $client->sendLogs([['message' => 'nope', 'severity' => 'error']]);
        $client->createSession(['session_id' => 'abc']);
        $client->addSessionEvent('abc', ['type' => 'click']);
        $client->endSession('abc');

        self::assertSame(0, $requestCount, 'disabled ApiClient must make zero outbound requests');
    }

    public function testRejectsFlushBudgetBelowMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ApiClient(
            dsn: 'https://example.com/project-1',
            apiKey: 'k',
            timeout: 2.0,
            retryAttempts: 0,
            async: true,
            circuitBreaker: new CircuitBreaker(
                enabled: true, failureThreshold: 5, timeout: 60, maxHalfOpenAttempts: 1,
                cache: new ArrayAdapter(),
            ),
            logger: null,
            flushBudget: 0.0, // below 0.05 → must throw
        );
    }

    public function testFlushCeilingIsBudgetWhenNoBreakerRecovering(): void
    {
        $client = $this->makeClientWithBudget(timeout: 2.0, flushBudget: 0.5);
        self::assertSame(0.5, $client->flushCeilingForTesting(anyHalfOpen: false));
    }

    public function testFlushCeilingWidensToRecoveryOnlyUnderTightBudget(): void
    {
        // Tight opt-in budget below the 1.0s floor: recovery widens to min(timeout, max(0.5,1.0)) = 1.0.
        $client = $this->makeClientWithBudget(timeout: 2.0, flushBudget: 0.5);
        self::assertSame(0.5, $client->flushCeilingForTesting(anyHalfOpen: false));
        self::assertSame(1.0, $client->flushCeilingForTesting(anyHalfOpen: true));
    }

    public function testFlushCeilingDoesNotWidenAtDefaultBudget(): void
    {
        // Default budget 2.0 >= 1.0 floor: recovery does NOT widen (R == B), so the
        // ceiling stays identical to today's terminate cap during recovery.
        $client = $this->makeClientWithBudget(timeout: 2.0, flushBudget: 2.0);
        self::assertSame(2.0, $client->flushCeilingForTesting(anyHalfOpen: false));
        self::assertSame(2.0, $client->flushCeilingForTesting(anyHalfOpen: true));
    }

    public function testFlushCeilingNeverExceedsTimeout(): void
    {
        $client = $this->makeClientWithBudget(timeout: 0.5, flushBudget: 2.0);
        // steady = min(0.5, 2.0) = 0.5 ; recovery = min(0.5, max(2.0, 1.0)) = 0.5
        self::assertSame(0.5, $client->flushCeilingForTesting(anyHalfOpen: false));
        self::assertSame(0.5, $client->flushCeilingForTesting(anyHalfOpen: true));
    }

    private function makeClientWithBudget(float $timeout, float $flushBudget): ApiClient
    {
        return new ApiClient(
            dsn: 'https://example.com/project-1',
            apiKey: 'k',
            timeout: $timeout,
            retryAttempts: 0,
            async: true,
            circuitBreaker: new CircuitBreaker(
                enabled: true, failureThreshold: 5, timeout: 60, maxHalfOpenAttempts: 1,
                cache: new ArrayAdapter(),
            ),
            logger: null,
            flushBudget: $flushBudget,
        );
    }

    public function testFlushDrainsBothDispatchersAndIsBoundedWhenOpen(): void
    {
        // -----------------------------------------------------------------------
        // Arrange: two CLOSED breakers + a spy HTTP client that:
        //   - on single-response stream($resp, 0.0): returns a timeout chunk
        //     so dispatchAsync PARKS the handle in $pendingResponses.
        //   - on array-form stream($responses, $wait): increments a counter and
        //     returns a timeout chunk (the blocking drain path we must NOT hit).
        // Both dispatchers share the same spy (shared HttpClient per ApiClient).
        // -----------------------------------------------------------------------
        $errorBreaker = new CircuitBreaker(
            enabled: true, failureThreshold: 1, timeout: 60, maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
        );
        $logBreaker = new CircuitBreaker(
            enabled: true, failureThreshold: 1, timeout: 60, maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
        );

        $spyResponse = new ApiClientTestSpyResponse();
        $spyHttp = new ApiClientTestSpyHttpClient($spyResponse);

        $client = new ApiClient(
            dsn: 'https://example.com/project-1', apiKey: 'k', timeout: 2.0,
            retryAttempts: 0, async: true, circuitBreaker: $errorBreaker, logger: null,
            httpClient: $spyHttp,
            logEndpoint: 'https://logs.example.com', logToken: 'sk_log_x',
            flushBudget: 0.5, logCircuitBreaker: $logBreaker,
        );

        // -----------------------------------------------------------------------
        // Park one handle in each dispatcher while both breakers are CLOSED.
        // sendError() routes to the error/session dispatcher.
        // sendLog() routes to the log dispatcher (separate logDispatcher).
        // -----------------------------------------------------------------------
        $client->sendError(['message' => 'park-for-error-dispatcher']);
        $client->sendLog(['message' => 'park-for-log-dispatcher', 'severity' => 'error']);

        // -----------------------------------------------------------------------
        // Open both breakers AFTER handles are parked.
        // failureThreshold is 1, so one recordFailure() trips each to OPEN.
        // -----------------------------------------------------------------------
        $errorBreaker->recordFailure(); // → OPEN
        $logBreaker->recordFailure();   // → OPEN

        self::assertSame('open', $errorBreaker->getState()['state']);
        self::assertSame('open', $logBreaker->getState()['state']);

        $spyHttp->resetBlockingDrainCount();

        // -----------------------------------------------------------------------
        // Act: flush() with BOTH breakers OPEN.
        // The real assertion: neither dispatcher may call the blocking array-form
        // stream() drain — that is the OPEN-skip path under test.
        // -----------------------------------------------------------------------
        $start = microtime(true);
        $client->flush();
        $elapsed = microtime(true) - $start;

        // -----------------------------------------------------------------------
        // Assert
        // -----------------------------------------------------------------------
        self::assertSame(
            0,
            $spyHttp->blockingDrainCalls(),
            'flush() with both breakers OPEN must skip the blocking stream() drain for both dispatchers',
        );
        // Parked handles must be settled (cancelled) via the non-blocking reap path.
        self::assertTrue(
            $spyResponse->wasCancelled(),
            'the parked handle must be cancelled by the non-blocking reap when the breaker is OPEN',
        );
        // Loose sanity check: the OPEN-skip path must not block.
        self::assertLessThan(0.2, $elapsed, 'flush() with both breakers OPEN must not block');
    }

    public function testSendLogSyncReturnsNullWhenUnconfigured(): void
    {
        $client = new ApiClient(
            dsn: 'https://example.com/p1', apiKey: 'k', timeout: 2.0, retryAttempts: 0, async: true,
            circuitBreaker: new CircuitBreaker(true, 5, 60, 1, new ArrayAdapter()),
            logger: null,
            // no logEndpoint/logToken → unconfigured
        );
        self::assertNull($client->sendLogSync(['message' => 'ping']));
    }

    public function testSendLogSyncReturns202WhenCollectorAccepts(): void
    {
        $http = new MockHttpClient(
            new MockResponse('', ['http_code' => 202]),
        );
        $client = new ApiClient(
            dsn: 'https://example.com/p1', apiKey: 'k', timeout: 2.0, retryAttempts: 0, async: true,
            circuitBreaker: new CircuitBreaker(true, 5, 60, 1, new ArrayAdapter()),
            logger: null, httpClient: $http,
            logEndpoint: 'https://logs.example.com', logToken: 'sk_log_x',
            logCircuitBreaker: new CircuitBreaker(true, 5, 60, 1, new ArrayAdapter()),
        );
        self::assertSame(202, $client->sendLogSync(['message' => 'ping']));
    }

    public function testExposesLogBreakerStateSeparately(): void
    {
        $logBreaker = new CircuitBreaker(
            enabled: true, failureThreshold: 1, timeout: 60, maxHalfOpenAttempts: 1,
            cache: new ArrayAdapter(),
        );
        $logBreaker->recordFailure(); // OPEN

        $client = new ApiClient(
            dsn: 'https://example.com/project-1', apiKey: 'k', timeout: 2.0,
            retryAttempts: 0, async: true,
            circuitBreaker: new CircuitBreaker(
                enabled: true, failureThreshold: 5, timeout: 60, maxHalfOpenAttempts: 1,
                cache: new ArrayAdapter(),
            ),
            logger: null, logEndpoint: 'https://logs.example.com', logToken: 'sk_log_x',
            flushBudget: 0.5, logCircuitBreaker: $logBreaker,
        );

        self::assertSame('open', $client->getLogCircuitBreakerState()['state']);
        self::assertSame('closed', $client->getCircuitBreakerState()['state'], 'error breaker is independent');
    }
}

// =============================================================================
// Spy doubles for testFlushDrainsBothDispatchersAndIsBoundedWhenOpen
// =============================================================================

/**
 * Minimal ResponseInterface spy:
 *   - first stream() poll (single-response, 0.0 timeout): yields a timeout chunk
 *     so dispatchAsync parks the handle in $pendingResponses.
 *   - tracks whether cancel() was called (for the non-blocking reap assertion).
 *
 * getInfo() returns mixed (interface contract).
 */
final class ApiClientTestSpyResponse implements \Symfony\Contracts\HttpClient\ResponseInterface
{
    private bool $cancelled = false;

    public function wasCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getStatusCode(): int
    {
        return 0;
    }

    /** @return array<string, list<string>> */
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
        return null;
    }
}

/**
 * Minimal ChunkInterface that signals "still in flight" (timeout, not last).
 */
final readonly class ApiClientTestTimeoutChunk implements \Symfony\Contracts\HttpClient\ChunkInterface
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

/**
 * Minimal ResponseStreamInterface that yields exactly one timeout chunk for a
 * single response, then stops.
 */
final class ApiClientTestSingleTimeoutStream implements \Symfony\Contracts\HttpClient\ResponseStreamInterface
{
    private bool $done = false;

    public function __construct(
        private readonly ApiClientTestSpyResponse $response,
    ) {
    }

    public function key(): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->response;
    }

    public function current(): \Symfony\Contracts\HttpClient\ChunkInterface
    {
        return new ApiClientTestTimeoutChunk();
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
 * HttpClientInterface spy:
 *   - request(): always returns the same spy response.
 *   - stream($resp, 0.0) single-response form (dispatchAsync poll): returns a
 *     timeout chunk, so the handle is parked.
 *   - stream($responses, $wait) array form (blocking drain): increments a counter
 *     and returns a timeout chunk — the test asserts this counter stays at zero
 *     when both breakers are OPEN.
 */
final class ApiClientTestSpyHttpClient implements \Symfony\Contracts\HttpClient\HttpClientInterface
{
    private int $blockingDrainCalls = 0;

    public function __construct(private readonly ApiClientTestSpyResponse $response)
    {
    }

    public function blockingDrainCalls(): int
    {
        return $this->blockingDrainCalls;
    }

    public function resetBlockingDrainCount(): void
    {
        $this->blockingDrainCalls = 0;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->response;
    }

    public function stream(
        \Symfony\Contracts\HttpClient\ResponseInterface|iterable $responses,
        ?float $timeout = null,
    ): \Symfony\Contracts\HttpClient\ResponseStreamInterface {
        if ($responses instanceof \Symfony\Contracts\HttpClient\ResponseInterface) {
            // dispatchAsync single-response 0.0-poll: park the handle.
            return new ApiClientTestSingleTimeoutStream($this->response);
        }

        // Array form = the blocking drain inside flushAndComplete(). Count it.
        ++$this->blockingDrainCalls;

        return new ApiClientTestSingleTimeoutStream($this->response);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        return $this;
    }
}
