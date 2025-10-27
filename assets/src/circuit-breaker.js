/**
 * Circuit Breaker for JavaScript
 *
 * Implements the circuit breaker pattern to prevent repeated calls to a failing service.
 * Uses sessionStorage to persist state across page reloads within the same session.
 *
 * States:
 * - CLOSED: Normal operation, requests go through
 * - OPEN: Service is down, requests are blocked immediately
 * - HALF_OPEN: Testing if service has recovered
 */
export class CircuitBreaker {
    static STATE_CLOSED = 'closed';
    static STATE_OPEN = 'open';
    static STATE_HALF_OPEN = 'half_open';

    constructor(config = {}) {
        this.failureThreshold = config.failureThreshold || 5;
        this.timeout = config.timeout || 60000; // 60 seconds in milliseconds
        this.storageKey = 'app_logger_circuit_breaker';

        this.loadState();
    }

    /**
     * Check if circuit is open (service down, reject requests)
     */
    isOpen() {
        // Check if we should transition from OPEN to HALF_OPEN
        if (this.state === CircuitBreaker.STATE_OPEN && this.shouldAttemptReset()) {
            this.halfOpen();
        }

        return this.state === CircuitBreaker.STATE_OPEN;
    }

    /**
     * Check if circuit is in half-open state
     */
    isHalfOpen() {
        return this.state === CircuitBreaker.STATE_HALF_OPEN;
    }

    /**
     * Record a successful request
     */
    recordSuccess() {
        if (this.state === CircuitBreaker.STATE_HALF_OPEN) {
            // Success in half-open = circuit closes (service recovered)
            this.close();
        } else if (this.state === CircuitBreaker.STATE_CLOSED) {
            // Reset failure count on success
            this.failureCount = 0;
            this.saveState();
        }
    }

    /**
     * Record a failed request
     */
    recordFailure() {
        if (this.state === CircuitBreaker.STATE_HALF_OPEN) {
            // Failure in half-open = circuit opens again
            this.open();
        } else if (this.state === CircuitBreaker.STATE_CLOSED) {
            this.failureCount++;

            if (this.failureCount >= this.failureThreshold) {
                this.open();
            } else {
                this.saveState();
            }
        }
    }

    /**
     * Get current state for monitoring/debugging
     */
    getState() {
        return {
            state: this.state,
            failureCount: this.failureCount,
            openedAt: this.openedAt,
        };
    }

    /**
     * Manually reset circuit breaker
     */
    reset() {
        this.close();
    }

    /**
     * Transition to CLOSED state
     */
    close() {
        this.state = CircuitBreaker.STATE_CLOSED;
        this.failureCount = 0;
        this.openedAt = null;
        this.saveState();
    }

    /**
     * Transition to OPEN state
     */
    open() {
        this.state = CircuitBreaker.STATE_OPEN;
        this.openedAt = Date.now();
        this.saveState();
    }

    /**
     * Transition to HALF_OPEN state
     */
    halfOpen() {
        this.state = CircuitBreaker.STATE_HALF_OPEN;
        this.saveState();
    }

    /**
     * Check if enough time has passed to attempt reset
     */
    shouldAttemptReset() {
        if (!this.openedAt) {
            return false;
        }

        return (Date.now() - this.openedAt) >= this.timeout;
    }

    /**
     * Load state from sessionStorage
     */
    loadState() {
        try {
            const stored = sessionStorage.getItem(this.storageKey);

            if (stored) {
                const state = JSON.parse(stored);
                this.state = state.state || CircuitBreaker.STATE_CLOSED;
                this.failureCount = state.failureCount || 0;
                this.openedAt = state.openedAt || null;
            } else {
                this.state = CircuitBreaker.STATE_CLOSED;
                this.failureCount = 0;
                this.openedAt = null;
            }
        } catch {
            // If storage fails, default to closed state
            this.state = CircuitBreaker.STATE_CLOSED;
            this.failureCount = 0;
            this.openedAt = null;
        }
    }

    /**
     * Save state to sessionStorage
     */
    saveState() {
        try {
            const state = {
                state: this.state,
                failureCount: this.failureCount,
                openedAt: this.openedAt,
            };

            sessionStorage.setItem(this.storageKey, JSON.stringify(state));
        } catch {
            // Storage failure should never crash the app
            // Circuit breaker still works in-memory for this page
        }
    }
}
