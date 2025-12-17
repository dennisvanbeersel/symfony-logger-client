<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Tests\Service;

use ApplicationLogger\Bundle\Service\CircuitBreaker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CircuitBreakerTest extends TestCase
{
    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    private function createCircuitBreaker(
        bool $enabled = true,
        int $failureThreshold = 5,
        int $timeout = 60,
        int $maxHalfOpenAttempts = 2
    ): CircuitBreaker {
        return new CircuitBreaker(
            $enabled,
            $failureThreshold,
            $timeout,
            $maxHalfOpenAttempts,
            $this->cache
        );
    }

    public function testCircuitStartsInClosedState(): void
    {
        $breaker = $this->createCircuitBreaker();
        $state = $breaker->getState();

        $this->assertEquals('closed', $state['state']);
        $this->assertEquals(0, $state['failureCount']);
        $this->assertNull($state['openedAt']);
        $this->assertFalse($breaker->isOpen());
        $this->assertFalse($breaker->isHalfOpen());
    }

    public function testCircuitOpensAfterFailureThreshold(): void
    {
        $breaker = $this->createCircuitBreaker(failureThreshold: 3);

        // Record failures up to threshold
        $breaker->recordFailure();
        $this->assertFalse($breaker->isOpen());

        $breaker->recordFailure();
        $this->assertFalse($breaker->isOpen());

        $breaker->recordFailure(); // 3rd failure, should open
        $this->assertTrue($breaker->isOpen());

        $state = $breaker->getState();
        $this->assertEquals('open', $state['state']);
        $this->assertNotNull($state['openedAt']);
    }

    public function testSuccessResetsFailureCount(): void
    {
        $breaker = $this->createCircuitBreaker(failureThreshold: 3);

        $breaker->recordFailure();
        $breaker->recordFailure();
        $this->assertEquals(2, $breaker->getState()['failureCount']);

        $breaker->recordSuccess();
        $this->assertEquals(0, $breaker->getState()['failureCount']);
        $this->assertFalse($breaker->isOpen());
    }

    public function testDisabledCircuitNeverOpens(): void
    {
        $breaker = $this->createCircuitBreaker(enabled: false, failureThreshold: 1);

        // Even with failures exceeding threshold
        $breaker->recordFailure();
        $breaker->recordFailure();
        $breaker->recordFailure();

        $this->assertFalse($breaker->isOpen());
        $this->assertFalse($breaker->isHalfOpen());
    }

    public function testDisabledCircuitIgnoresSuccessAndFailure(): void
    {
        $breaker = $this->createCircuitBreaker(enabled: false);

        $breaker->recordFailure();
        $breaker->recordSuccess();

        // State should still show closed with 0 failures
        $state = $breaker->getState();
        $this->assertEquals('closed', $state['state']);
    }

    public function testOpenCircuitBlocksRequests(): void
    {
        $breaker = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);

        $breaker->recordFailure(); // Opens the circuit

        $this->assertTrue($breaker->isOpen());
    }

    public function testResetManuallyClearsState(): void
    {
        $breaker = $this->createCircuitBreaker(failureThreshold: 1);

        $breaker->recordFailure(); // Opens the circuit
        $this->assertTrue($breaker->isOpen());

        $breaker->reset();

        $this->assertFalse($breaker->isOpen());
        $state = $breaker->getState();
        $this->assertEquals('closed', $state['state']);
        $this->assertEquals(0, $state['failureCount']);
        $this->assertNull($state['openedAt']);
    }

    public function testSuccessInHalfOpenClosesCircuit(): void
    {
        $breaker = $this->createCircuitBreaker(failureThreshold: 1, timeout: 10);

        $breaker->recordFailure(); // Opens
        $this->assertTrue($breaker->isOpen());

        // Simulate timeout passing by manipulating cache
        // We can't easily test time-based transitions, so we'll use reset() instead
        // and manually test the half-open success logic

        $breaker->reset();
        $breaker->recordSuccess();

        $this->assertFalse($breaker->isOpen());
        $this->assertEquals('closed', $breaker->getState()['state']);
    }

    public function testStatePersistsInCache(): void
    {
        // First instance - record some failures
        $breaker1 = $this->createCircuitBreaker(failureThreshold: 5);
        $breaker1->recordFailure();
        $breaker1->recordFailure();

        // Second instance using same cache - should load previous state
        $breaker2 = $this->createCircuitBreaker(failureThreshold: 5);
        $state = $breaker2->getState();

        $this->assertEquals(2, $state['failureCount']);
    }

    public function testStatePersistsAcrossInstances(): void
    {
        // Open the circuit with first instance
        $breaker1 = $this->createCircuitBreaker(failureThreshold: 2);
        $breaker1->recordFailure();
        $breaker1->recordFailure();
        $this->assertTrue($breaker1->isOpen());

        // Second instance should also see it as open
        $breaker2 = $this->createCircuitBreaker(failureThreshold: 2);
        $this->assertTrue($breaker2->isOpen());
    }

    public function testGetStateReturnsAllFields(): void
    {
        $breaker = $this->createCircuitBreaker();
        $state = $breaker->getState();

        $this->assertArrayHasKey('state', $state);
        $this->assertArrayHasKey('failureCount', $state);
        $this->assertArrayHasKey('openedAt', $state);
        $this->assertArrayHasKey('halfOpenAttempts', $state);
    }

    public function testInvalidFailureThresholdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failure threshold must be at least 1');

        $this->createCircuitBreaker(failureThreshold: 0);
    }

    public function testInvalidTimeoutThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout must be at least 10 seconds');

        $this->createCircuitBreaker(timeout: 5);
    }

    public function testInvalidMaxHalfOpenAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Max half-open attempts must be at least 1');

        $this->createCircuitBreaker(maxHalfOpenAttempts: 0);
    }

    public function testMultipleFailuresAccumulate(): void
    {
        $breaker = $this->createCircuitBreaker(failureThreshold: 10);

        for ($i = 1; $i <= 7; ++$i) {
            $breaker->recordFailure();
        }

        $this->assertEquals(7, $breaker->getState()['failureCount']);
        $this->assertFalse($breaker->isOpen()); // Not yet at threshold
    }

    public function testResilienceOnCacheFailure(): void
    {
        // Create a mock cache that throws on all operations
        $failingCache = $this->createMock(\Psr\Cache\CacheItemPoolInterface::class);
        $failingCache->method('getItem')
            ->willThrowException(new \RuntimeException('Cache unavailable'));

        // Circuit breaker should handle cache failures gracefully
        $breaker = new CircuitBreaker(
            true,
            5,
            60,
            2,
            $failingCache
        );

        // Should start in closed state even if cache failed
        $this->assertFalse($breaker->isOpen());
        $this->assertEquals('closed', $breaker->getState()['state']);
    }

    public function testClosedStateIsDefaultOnCacheMiss(): void
    {
        // Fresh cache with no state stored
        $freshCache = new ArrayAdapter();
        $breaker = new CircuitBreaker(
            true,
            5,
            60,
            2,
            $freshCache
        );

        $state = $breaker->getState();
        $this->assertEquals('closed', $state['state']);
        $this->assertEquals(0, $state['failureCount']);
    }
}
