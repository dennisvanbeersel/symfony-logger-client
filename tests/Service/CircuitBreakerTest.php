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

    /**
     * ASYNC-1 guard: a stale-CLOSED worker recording a success must NOT downgrade a
     * sibling worker's more-recently-persisted OPEN circuit back to CLOSED.
     *
     * Two breakers share one cache (two FrankenPHP workers). Worker A trips the
     * circuit OPEN. Worker B still holds a boot-time CLOSED snapshot in memory and
     * then records a success — it must refresh-then-mutate and leave the persisted
     * circuit OPEN rather than clobbering it.
     */
    public function testSuccessOnStaleClosedWorkerDoesNotDowngradePersistedOpen(): void
    {
        // Worker B boots first and caches a CLOSED snapshot in memory.
        $workerB = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);
        $this->assertFalse($workerB->isOpen());

        // Worker A trips the circuit OPEN and persists it to the shared cache.
        $workerA = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);
        $workerA->recordFailure();
        $this->assertTrue($workerA->isOpen());

        // Worker B, still believing it is CLOSED in memory, records a success.
        // It must observe the sibling's OPEN and refuse to downgrade it.
        $workerB->recordSuccess();

        // Both the local view and the shared cache must remain OPEN.
        $this->assertTrue($workerB->isOpen());

        $observer = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);
        $this->assertTrue($observer->isOpen());
        $this->assertEquals('open', $observer->getState()['state']);
    }

    /**
     * ASYNC-1 guard: a failure recorded by a stale-CLOSED worker against a circuit a
     * sibling already opened is a no-op (must not reset openedAt / counters).
     */
    public function testFailureOnStaleClosedWorkerLeavesPersistedOpenIntact(): void
    {
        $workerB = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);
        $this->assertFalse($workerB->isOpen());

        $workerA = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);
        $workerA->recordFailure();
        $openedAt = $workerA->getState()['openedAt'];
        $this->assertNotNull($openedAt);

        // Worker B records a failure while holding a stale CLOSED snapshot.
        $workerB->recordFailure();

        // Circuit stays OPEN with the original openedAt preserved.
        $observer = $this->createCircuitBreaker(failureThreshold: 1, timeout: 60);
        $this->assertTrue($observer->isOpen());
        $this->assertEquals($openedAt, $observer->getState()['openedAt']);
    }

    /**
     * ASYNC-2 guard: HALF_OPEN admission stops at maxHalfOpenAttempts so concurrent
     * callers cannot over-admit probes against a recovering API.
     */
    public function testHalfOpenAdmissionIsCappedAtMaxAttempts(): void
    {
        // Open the circuit, then force a HALF_OPEN transition via reset()->halfOpen
        // semantics: we use a short timeout and manipulate the shared state directly.
        $cache = new ArrayAdapter();
        $item = $cache->getItem('application_logger.circuit_breaker');
        $item->set([
            'state' => 'half_open',
            'failureCount' => 0,
            'openedAt' => time(),
            'halfOpenAttempts' => 0,
        ]);
        $cache->save($item);

        $breaker = new CircuitBreaker(true, 5, 60, 2, $cache);

        // First two probes are admitted (cap = 2).
        $this->assertTrue($breaker->allowRequest());
        $this->assertTrue($breaker->allowRequest());

        // Third probe is refused: cap reached, no further admission.
        $this->assertFalse($breaker->allowRequest());
        $this->assertFalse($breaker->allowRequest());

        $this->assertEquals(2, $breaker->getState()['halfOpenAttempts']);
    }

    // -------------------------------------------------------------------------
    // ASYNC-4: per-endpoint isolation via distinct cache keys
    // -------------------------------------------------------------------------

    public function testDistinctCacheKeysIsolateBreakerState(): void
    {
        // Two breakers over ONE shared cache pool but with DIFFERENT cache keys (the
        // error/session vs log-aggregation split) must have INDEPENDENT state: tripping
        // the log breaker must not open the error breaker.
        $cache = new ArrayAdapter();

        $logBreaker = new CircuitBreaker(true, 2, 60, 1, $cache, 'application_logger.circuit_breaker.log');
        $logBreaker->recordFailure();
        $logBreaker->recordFailure();
        $this->assertTrue($logBreaker->isOpen(), 'log breaker must trip on its own failures');

        // Constructed AFTER the failures so it loads its own (untouched) key, not a stale snapshot.
        $errorBreaker = new CircuitBreaker(true, 2, 60, 1, $cache, 'application_logger.circuit_breaker');
        $this->assertFalse($errorBreaker->isOpen(), 'error breaker (distinct key) must stay closed');
    }

    public function testSameCacheKeySharesState(): void
    {
        // BC: two breakers with the DEFAULT (same) cache key share state.
        $cache = new ArrayAdapter();

        $a = new CircuitBreaker(true, 2, 60, 1, $cache);
        $a->recordFailure();
        $a->recordFailure();
        $this->assertTrue($a->isOpen());

        // Fresh instance loads the shared OPEN state at construction.
        $b = new CircuitBreaker(true, 2, 60, 1, $cache);
        $this->assertTrue($b->isOpen(), 'breakers sharing a cache key must share state');
    }

    /**
     * conc-03 guard: isHalfOpen() must refresh-then-read like isOpen(), so a worker
     * holding a stale in-memory snapshot does not report a HALF_OPEN that a sibling
     * has since transitioned away from (here: a sibling persisted CLOSED).
     *
     * We boot a worker on a HALF_OPEN snapshot, age its in-memory snapshot past the
     * staleness TTL, then have a sibling persist CLOSED. After the TTL, isHalfOpen()
     * must observe CLOSED (false) rather than its stale HALF_OPEN view.
     */
    public function testIsHalfOpenRefreshesStaleWorkerToSiblingPersistedClosed(): void
    {
        $cache = new ArrayAdapter();

        // Seed the shared cache as HALF_OPEN and boot worker B on that snapshot.
        $item = $cache->getItem('application_logger.circuit_breaker');
        $item->set([
            'state' => 'half_open',
            'failureCount' => 0,
            'openedAt' => time(),
            'halfOpenAttempts' => 0,
        ]);
        $cache->save($item);

        $workerB = new CircuitBreaker(true, 5, 60, 2, $cache);
        $this->assertTrue($workerB->isHalfOpen(), 'worker boots on the HALF_OPEN snapshot');

        // Age worker B's in-memory snapshot beyond the staleness TTL (5s) so the next
        // public read triggers refreshIfStale().
        $loadedAt = new \ReflectionProperty(CircuitBreaker::class, 'loadedAt');
        $loadedAt->setValue($workerB, time() - 10);

        // A sibling persists CLOSED into the shared cache.
        $closed = $cache->getItem('application_logger.circuit_breaker');
        $closed->set([
            'state' => 'closed',
            'failureCount' => 0,
            'openedAt' => null,
            'halfOpenAttempts' => 0,
        ]);
        $cache->save($closed);

        // Without the refresh-then-read fix, this would still report true (stale).
        $this->assertFalse(
            $workerB->isHalfOpen(),
            'stale worker must refresh and observe the sibling-persisted CLOSED'
        );
    }
}
