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
 *
 * @example
 * const breaker = new CircuitBreaker({ failureThreshold: 5, timeout: 60000 });
 * if (!breaker.isOpen()) {
 *   try {
 *     await sendRequest();
 *     breaker.recordSuccess();
 *   } catch (error) {
 *     breaker.recordFailure();
 *   }
 * }
 */
export class CircuitBreaker {
    /** @type {string} Circuit is functioning normally */
    static STATE_CLOSED = 'closed';
    /** @type {string} Circuit is open, blocking requests */
    static STATE_OPEN = 'open';
    /** @type {string} Circuit is testing if service recovered */
    static STATE_HALF_OPEN = 'half_open';

    /**
     * Create a new CircuitBreaker instance
     *
     * @param {Object} [config={}] - Configuration options
     * @param {number} [config.failureThreshold=5] - Number of failures before opening circuit
     * @param {number} [config.timeout=60000] - Milliseconds before attempting reset (default 60s)
     */
    constructor(config = {}) {
        /** @type {number} */
        this.failureThreshold = config.failureThreshold || 5;
        /** @type {number} */
        this.timeout = config.timeout || 60000;
        /** @type {string} */
        this.storageKey = 'app_logger_circuit_breaker';

        this.loadState();
    }

    /**
     * Check if circuit is open (service down, reject requests)
     *
     * Also triggers transition from OPEN to HALF_OPEN if timeout has elapsed.
     *
     * @returns {boolean} True if circuit is open and requests should be blocked
     */
    isOpen() {
        // Check if we should transition from OPEN to HALF_OPEN
        if (this.state === CircuitBreaker.STATE_OPEN && this.shouldAttemptReset()) {
            this.halfOpen();
        }

        return this.state === CircuitBreaker.STATE_OPEN;
    }

    /**
     * Check if circuit is in half-open state (testing recovery)
     *
     * @returns {boolean} True if circuit is half-open
     */
    isHalfOpen() {
        return this.state === CircuitBreaker.STATE_HALF_OPEN;
    }

    /**
     * Record a successful request
     *
     * In HALF_OPEN state, closes the circuit (service recovered).
     * In CLOSED state, resets failure count.
     *
     * @returns {void}
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
     *
     * In HALF_OPEN state, reopens the circuit.
     * In CLOSED state, increments failure count and opens circuit if threshold reached.
     *
     * @returns {void}
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
     *
     * @returns {{state: string, failureCount: number, openedAt: number|null}} Current circuit state
     */
    getState() {
        return {
            state: this.state,
            failureCount: this.failureCount,
            openedAt: this.openedAt,
        };
    }

    /**
     * Manually reset circuit breaker to CLOSED state
     *
     * @returns {void}
     */
    reset() {
        this.close();
    }

    /**
     * Transition to CLOSED state (internal)
     *
     * @private
     * @returns {void}
     */
    close() {
        this.state = CircuitBreaker.STATE_CLOSED;
        this.failureCount = 0;
        this.openedAt = null;
        this.saveState();
    }

    /**
     * Transition to OPEN state (internal)
     *
     * @private
     * @returns {void}
     */
    open() {
        this.state = CircuitBreaker.STATE_OPEN;
        this.openedAt = Date.now();
        this.saveState();
    }

    /**
     * Transition to HALF_OPEN state (internal)
     *
     * @private
     * @returns {void}
     */
    halfOpen() {
        this.state = CircuitBreaker.STATE_HALF_OPEN;
        this.saveState();
    }

    /**
     * Check if enough time has passed to attempt reset (internal)
     *
     * @private
     * @returns {boolean} True if timeout has elapsed since circuit opened
     */
    shouldAttemptReset() {
        if (!this.openedAt) {
            return false;
        }

        return (Date.now() - this.openedAt) >= this.timeout;
    }

    /**
     * Load state from sessionStorage (internal)
     *
     * @private
     * @returns {void}
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
     * Save state to sessionStorage (internal)
     *
     * @private
     * @returns {void}
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
