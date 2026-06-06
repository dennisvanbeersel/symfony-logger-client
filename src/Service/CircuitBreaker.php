<?php

declare(strict_types=1);

namespace ApplicationLogger\Bundle\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Circuit Breaker pattern implementation.
 *
 * Prevents repeated attempts to call a failing service. Three states:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: Service is down, requests are blocked (fast-fail)
 * - HALF_OPEN: Testing if service has recovered
 *
 * This is CRITICAL for resilience - prevents cascade failures and resource exhaustion.
 */
class CircuitBreaker
{
    private const STATE_CLOSED = 'closed';
    private const STATE_OPEN = 'open';
    private const STATE_HALF_OPEN = 'half_open';
    private const CACHE_KEY = 'application_logger.circuit_breaker';

    /**
     * How long (seconds) in-memory state may be trusted before re-reading the shared
     * cache. Keeps long-lived workers (FrankenPHP worker mode) eventually consistent
     * with sibling workers without a cache read on every single isOpen() call.
     */
    private const STALENESS_TTL = 5;

    private string $state;
    private int $failureCount = 0;
    private int $halfOpenAttempts = 0;
    private ?int $openedAt = null;

    /** Unix timestamp of the last cache (re)load, for staleness detection. */
    private int $loadedAt = 0;

    /** True once at least one cache load has occurred (distinguishes first boot from eviction). */
    private bool $everLoaded = false;

    public function __construct(
        private readonly bool $enabled,
        private readonly int $failureThreshold,
        private readonly int $timeout,
        private readonly int $maxHalfOpenAttempts,
        private readonly CacheItemPoolInterface $cache,
    ) {
        // Validate parameters
        if ($failureThreshold < 1) {
            throw new \InvalidArgumentException('Failure threshold must be at least 1');
        }

        if ($timeout < 10) {
            throw new \InvalidArgumentException('Timeout must be at least 10 seconds');
        }

        if ($maxHalfOpenAttempts < 1) {
            throw new \InvalidArgumentException('Max half-open attempts must be at least 1');
        }

        $this->loadState();
    }

    /**
     * Decide whether a request may proceed, MUTATING state as a side effect (CQS:
     * this is the command). Drives the OPEN->HALF_OPEN transition when the timeout
     * has elapsed and consumes a HALF_OPEN probe slot (incrementing attempts and
     * persisting). Callers gate every outbound request on this.
     *
     * @return bool true if the request is allowed (circuit not OPEN); false to skip
     */
    public function allowRequest(): bool
    {
        if (!$this->enabled) {
            return true;
        }

        // Re-read shared state if our in-memory copy is stale. In worker mode the
        // service is a singleton loaded once at boot; without this, a sibling worker
        // opening the circuit would never be observed here.
        $this->refreshIfStale();

        // Check if we should transition from OPEN to HALF_OPEN
        if (self::STATE_OPEN === $this->state && $this->shouldAttemptReset()) {
            $this->halfOpen();
        }

        // In half-open state, track test attempts
        if (self::STATE_HALF_OPEN === $this->state) {
            ++$this->halfOpenAttempts;
            $this->saveState();
        }

        return self::STATE_OPEN !== $this->state;
    }

    /**
     * Pure read of whether the circuit is currently OPEN (no mutation, no probe
     * transition). Kept for external monitoring/tests that must observe state
     * without consuming a HALF_OPEN slot. The request-gating decision lives in
     * {@see allowRequest()}.
     */
    public function isOpen(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $this->refreshIfStale();

        return self::STATE_OPEN === $this->state;
    }

    /**
     * Check if circuit is in half-open state (testing recovery).
     */
    public function isHalfOpen(): bool
    {
        return $this->enabled && self::STATE_HALF_OPEN === $this->state;
    }

    /**
     * Record a successful request.
     */
    public function recordSuccess(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (self::STATE_HALF_OPEN === $this->state) {
            // Success in half-open state = circuit closes (service recovered)
            $this->close();
        } elseif (self::STATE_CLOSED === $this->state) {
            // Reset failure count on success
            $this->failureCount = 0;
            $this->saveState();
        }
    }

    /**
     * Record a failed request.
     */
    public function recordFailure(): void
    {
        if (!$this->enabled) {
            return;
        }

        if (self::STATE_HALF_OPEN === $this->state) {
            // In half-open state, only reopen circuit if max attempts exhausted
            if ($this->halfOpenAttempts >= $this->maxHalfOpenAttempts) {
                $this->open();
            } else {
                // Allow more test attempts
                $this->saveState();
            }
        } elseif (self::STATE_CLOSED === $this->state) {
            ++$this->failureCount;

            if ($this->failureCount >= $this->failureThreshold) {
                $this->open();
            } else {
                $this->saveState();
            }
        }
    }

    /**
     * Get current state for monitoring/debugging.
     *
     * @return array{state: string, failureCount: int, openedAt: int|null, halfOpenAttempts: int}
     */
    public function getState(): array
    {
        return [
            'state' => $this->state,
            'failureCount' => $this->failureCount,
            'openedAt' => $this->openedAt,
            'halfOpenAttempts' => $this->halfOpenAttempts,
        ];
    }

    /**
     * Manually reset circuit breaker (for testing/debugging).
     */
    public function reset(): void
    {
        $this->close();
    }

    /**
     * Transition to CLOSED state (normal operation).
     */
    private function close(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->openedAt = null;
        $this->halfOpenAttempts = 0;
        $this->saveState();
    }

    /**
     * Transition to OPEN state (service is down, block requests).
     */
    private function open(): void
    {
        $this->state = self::STATE_OPEN;
        $this->openedAt = time();
        $this->halfOpenAttempts = 0;
        $this->saveState();
    }

    /**
     * Transition to HALF_OPEN state (test if service recovered).
     */
    private function halfOpen(): void
    {
        $this->state = self::STATE_HALF_OPEN;
        $this->halfOpenAttempts = 0;
        $this->saveState();
    }

    /**
     * Check if enough time has passed to attempt reset from OPEN state.
     */
    private function shouldAttemptReset(): bool
    {
        if (null === $this->openedAt) {
            return false;
        }

        return (time() - $this->openedAt) >= $this->timeout;
    }

    /**
     * Re-read shared state from cache if the in-memory copy is older than the
     * staleness TTL. Cheap no-op within the TTL window.
     */
    private function refreshIfStale(): void
    {
        if ((time() - $this->loadedAt) < self::STALENESS_TTL) {
            return;
        }

        $this->loadState();
    }

    /**
     * Load state from cache.
     *
     * Fail-safe policy: on a cache MISS we default to OPEN (not CLOSED). A miss
     * under memory pressure / eviction is indistinguishable from "circuit may be
     * open", so defaulting closed would unleash a thundering herd against an
     * already-degraded API. The OPEN state self-heals via the normal HALF_OPEN
     * probe after the timeout window. A genuinely-never-initialised breaker is
     * seeded CLOSED once on construction (see below) so first boot is permissive.
     */
    private function loadState(): void
    {
        $this->loadedAt = time();

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);

            if ($item->isHit()) {
                $state = $item->get();
                $this->state = $state['state'] ?? self::STATE_CLOSED;
                $this->failureCount = $state['failureCount'] ?? 0;
                $this->openedAt = $state['openedAt'] ?? null;
                $this->halfOpenAttempts = $state['halfOpenAttempts'] ?? 0;

                // Honour the logical timeout encoded in the data even if the cache
                // entry outlived it: an OPEN circuit whose timeout has elapsed should
                // be eligible for a HALF_OPEN probe rather than staying OPEN forever.
                return;
            }

            // Cache miss. On first boot (never loaded before) seed CLOSED so a fresh
            // deployment is permissive. Otherwise the entry was evicted while we had
            // prior state: fail safe to OPEN to avoid a herd against a degraded API.
            if (!$this->everLoaded) {
                $this->state = self::STATE_CLOSED;
            } else {
                $this->state = self::STATE_OPEN;
                $this->openedAt = time();
                $this->halfOpenAttempts = 0;
            }
        } catch (\Throwable) {
            // Cache failure should never break the application; stay/seed CLOSED.
            if (!$this->everLoaded) {
                $this->state = self::STATE_CLOSED;
            }
        } finally {
            $this->everLoaded = true;
        }
    }

    /**
     * Save state to cache.
     */
    private function saveState(): void
    {
        $this->loadedAt = time();

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set([
                'state' => $this->state,
                'failureCount' => $this->failureCount,
                'openedAt' => $this->openedAt,
                'halfOpenAttempts' => $this->halfOpenAttempts,
            ]);
            // Use a large, fixed lower-bounded TTL independent of the (now capped)
            // logical timeout so the entry is far less likely to be LRU-evicted mid
            // outage. The logical timeout itself is enforced via openedAt in
            // shouldAttemptReset(), so a longer cache TTL is safe.
            $item->expiresAfter(max(3600, $this->timeout * 4));
            $this->cache->save($item);
        } catch (\Throwable) {
            // Cache failure should never break the application
            // Circuit breaker will still work in-memory for this request
        }
    }
}
