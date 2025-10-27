/**
 * Rate Limiter
 *
 * Prevents error storms by limiting the number of errors sent per minute.
 * Uses token bucket algorithm for smooth rate limiting.
 *
 * This is critical for resilience - prevents overwhelming the API
 * and consuming excessive bandwidth during error cascades.
 */
export class RateLimiter {
    constructor(config = {}) {
        this.maxTokens = config.maxTokens || 10; // Max errors per window
        this.refillRate = config.refillRate || 1; // Tokens per second
        this.tokens = this.maxTokens;
        this.lastRefill = Date.now();
    }

    /**
     * Check if request is allowed
     */
    isAllowed() {
        this.refillTokens();
        return this.tokens > 0;
    }

    /**
     * Consume a token (record an error sent)
     */
    consume() {
        if (!this.isAllowed()) {
            return false;
        }

        this.tokens--;
        return true;
    }

    /**
     * Refill tokens based on time elapsed
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
     * Get current token count (for debugging)
     */
    getTokens() {
        this.refillTokens();
        return this.tokens;
    }

    /**
     * Reset rate limiter
     */
    reset() {
        this.tokens = this.maxTokens;
        this.lastRefill = Date.now();
    }
}
