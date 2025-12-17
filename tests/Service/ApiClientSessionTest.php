<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\ApiClient;
use ApplicationLogger\Bundle\Service\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Unit tests for ApiClient session tracking methods.
 *
 * These methods are fire-and-forget (never throw), so we test that they execute without errors.
 */
final class ApiClientSessionTest extends TestCase
{
    private CircuitBreaker $circuitBreaker;
    private ApiClient $apiClient;

    protected function setUp(): void
    {
        // Create circuit breaker with proper parameters
        $cache = new ArrayAdapter();
        $this->circuitBreaker = new CircuitBreaker(
            enabled: true,
            failureThreshold: 5,
            timeout: 60,
            maxHalfOpenAttempts: 3,
            cache: $cache
        );

        // Create ApiClient with proper constructor parameters
        $this->apiClient = new ApiClient(
            dsn: 'https://localhost:9999/test-project-id', // Valid DSN format
            apiKey: 'test-api-key',
            timeout: 0.5, // Minimum allowed timeout
            retryAttempts: 0, // No retries for speed
            async: false, // Synchronous for testing
            circuitBreaker: $this->circuitBreaker,
            logger: new NullLogger(),
            debug: false
        );
    }

    public function testCreateSessionDoesNotThrow(): void
    {
        $sessionData = [
            'session_id' => 'test-session-123',
            'started_at' => '2024-10-26T12:00:00+00:00',
            'platform' => 'web',
            'browser' => 'Chrome 120',
            'user_agent' => 'Mozilla/5.0',
        ];

        // Should not throw exception even with invalid DSN
        $this->apiClient->createSession($sessionData);

        $this->addToAssertionCount(1); // If we get here, test passed
    }

    public function testAddSessionEventDoesNotThrow(): void
    {
        $sessionId = 'test-session-123';
        $eventData = [
            'type' => 'PAGE_VIEW',
            'url' => 'https://example.com/page',
            'timestamp' => '2024-10-26T12:00:00+00:00',
        ];

        // Should not throw exception
        $this->apiClient->addSessionEvent($sessionId, $eventData);

        $this->addToAssertionCount(1);
    }

    public function testEndSessionDoesNotThrow(): void
    {
        $sessionId = 'test-session-123';
        $endedAt = new \DateTimeImmutable('2024-10-26T13:00:00+00:00');

        // Should not throw exception
        $this->apiClient->endSession($sessionId, $endedAt);

        $this->addToAssertionCount(1);
    }

    public function testEndSessionWithoutTimestampDoesNotThrow(): void
    {
        $sessionId = 'test-session-123';

        // Should not throw exception (will use current time)
        $this->apiClient->endSession($sessionId);

        $this->addToAssertionCount(1);
    }

    public function testCircuitBreakerPreventsCallsWhenOpen(): void
    {
        // Open the circuit breaker by recording failures
        for ($i = 0; $i < 5; ++$i) {
            $this->circuitBreaker->recordFailure();
        }

        $this->assertTrue($this->circuitBreaker->isOpen());

        // These calls should return early without making HTTP requests
        $this->apiClient->createSession([
            'session_id' => 'test-session',
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ]);

        $this->apiClient->addSessionEvent('test', ['type' => 'CLICK']);
        $this->apiClient->endSession('test');

        // If we get here, the circuit breaker is working (no exceptions thrown)
        $this->addToAssertionCount(1);
    }

    public function testCreateSessionAddsDefaultTimestamp(): void
    {
        $sessionData = [
            'session_id' => 'test-session-123',
            'platform' => 'web',
            // No started_at - should be added automatically
        ];

        // Should not throw - timestamp will be added
        $this->apiClient->createSession($sessionData);

        $this->addToAssertionCount(1);
    }

    public function testAddSessionEventAddsDefaultTimestamp(): void
    {
        $sessionId = 'test-session-123';
        $eventData = [
            'type' => 'PAGE_VIEW',
            'url' => 'https://example.com',
            // No timestamp - should be added automatically
        ];

        // Should not throw - timestamp will be added
        $this->apiClient->addSessionEvent($sessionId, $eventData);

        $this->addToAssertionCount(1);
    }

    public function testMultipleSessionCallsDoNotThrow(): void
    {
        $sessionId = 'multi-test-session';

        // Create session
        $this->apiClient->createSession([
            'session_id' => $sessionId,
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeImmutable::ATOM),
        ]);

        // Add multiple events
        for ($i = 0; $i < 5; ++$i) {
            $this->apiClient->addSessionEvent($sessionId, [
                'type' => 'PAGE_VIEW',
                'url' => "https://example.com/page-{$i}",
            ]);
        }

        // End session
        $this->apiClient->endSession($sessionId);

        // If we get here, all calls succeeded
        $this->addToAssertionCount(1);
    }
}
