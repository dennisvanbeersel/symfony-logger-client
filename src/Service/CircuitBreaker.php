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

    private string $state;
    private int $failureCount = 0;
    private int $halfOpenAttempts = 0;
    private ?int $openedAt = null;

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
     * Check if circuit is open (service is down, reject requests).
     */
    public function isOpen(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        // Check if we should transition from OPEN to HALF_OPEN
        if (self::STATE_OPEN === $this->state && $this->shouldAttemptReset()) {
            $this->halfOpen();
        }

        // In half-open state, track test attempts
        if (self::STATE_HALF_OPEN === $this->state) {
            ++$this->halfOpenAttempts;
            $this->saveState();
        }

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
     * Load state from cache.
     */
    private function loadState(): void
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);

            if ($item->isHit()) {
                $state = $item->get();
                $this->state = $state['state'] ?? self::STATE_CLOSED;
                $this->failureCount = $state['failureCount'] ?? 0;
                $this->openedAt = $state['openedAt'] ?? null;
                $this->halfOpenAttempts = $state['halfOpenAttempts'] ?? 0;
            } else {
                $this->state = self::STATE_CLOSED;
            }
        } catch (\Throwable) {
            // Cache failure should never break the application
            $this->state = self::STATE_CLOSED;
        }
    }

    /**
     * Save state to cache.
     */
    private function saveState(): void
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set([
                'state' => $this->state,
                'failureCount' => $this->failureCount,
                'openedAt' => $this->openedAt,
                'halfOpenAttempts' => $this->halfOpenAttempts,
            ]);
            // Cache for longer than the timeout to persist across requests
            $item->expiresAfter($this->timeout * 2);
            $this->cache->save($item);
        } catch (\Throwable) {
            // Cache failure should never break the application
            // Circuit breaker will still work in-memory for this request
        }
    }
}
