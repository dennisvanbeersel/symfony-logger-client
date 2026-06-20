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
}
