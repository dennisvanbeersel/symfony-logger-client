/**
 * Rate Limiter for JavaScript
 *
 * Prevents error storms by limiting the number of errors sent per minute.
 * Uses token bucket algorithm for smooth rate limiting.
 *
 * This is critical for resilience - prevents overwhelming the API
 * and consuming excessive bandwidth during error cascades.
 *
 * @example
 * const limiter = new RateLimiter({ maxTokens: 10, refillRate: 1 });
 * if (limiter.consume()) {
 *   await sendError(error);
 * } else {
 *   queue.enqueue(error); // Rate limited, queue for later
 * }
 */
export class RateLimiter {
    /**
     * Create a new RateLimiter instance
     *
     * @param {Object} [config={}] - Configuration options
     * @param {number} [config.maxTokens=10] - Maximum tokens (burst capacity)
     * @param {number} [config.refillRate=1] - Tokens added per second
     */
    constructor(config = {}) {
        /** @type {number} Maximum tokens (burst capacity) */
        this.maxTokens = config.maxTokens || 10;
        /** @type {number} Tokens added per second */
        this.refillRate = config.refillRate || 1;
        /** @type {number} Current available tokens */
        this.tokens = this.maxTokens;
        /** @type {number} Timestamp of last refill */
        this.lastRefill = Date.now();
    }

    /**
     * Check if request is allowed (without consuming a token)
     *
     * @returns {boolean} True if tokens are available
     */
    isAllowed() {
        this.refillTokens();
        return this.tokens > 0;
    }

    /**
     * Consume a token (record an error sent)
     *
     * Call this when successfully sending an error to track rate limits.
     *
     * @returns {boolean} True if token was consumed, false if rate limited
     */
    consume() {
        if (!this.isAllowed()) {
            return false;
        }

        this.tokens--;
        return true;
    }

    /**
     * Refill tokens based on time elapsed (internal)
     *
     * Called automatically by isAllowed() and getTokens().
     * Adds tokens based on elapsed time since last refill.
     *
     * @private
     * @returns {void}
     */
    refillTokens() {
        const now = Date.now();
        const elapsed = (now - this.lastRefill) / 1000; // Convert to seconds
        const tokensToAdd = Math.floor(elapsed * this.refillRate);

        if (tokensToAdd > 0) {
            this.tokens = Math.min(this.maxTokens, this.tokens + tokensToAdd);
            this.lastRefill = now;
        }
    }

    /**
     * Get current token count (for debugging/monitoring)
     *
     * @returns {number} Current available tokens
     */
    getTokens() {
        this.refillTokens();
        return this.tokens;
    }

    /**
     * Reset rate limiter to full capacity
     *
     * Restores all tokens and resets the refill timer.
     *
     * @returns {void}
     */
    reset() {
        this.tokens = this.maxTokens;
        this.lastRefill = Date.now();
    }
}
