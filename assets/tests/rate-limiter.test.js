/**
 * Unit tests for RateLimiter
 *
 * Tests the token bucket rate limiting implementation:
 * - Token management
 * - Rate limiting (allow/deny)
 * - Token refill over time
 * - Configuration options
 */
import { RateLimiter } from '../src/rate-limiter.js';

describe('RateLimiter', () => {
    let rateLimiter;

    beforeEach(() => {
        rateLimiter = new RateLimiter({
            maxTokens: 3,
            refillRate: 2, // 2 tokens per second
        });
    });

    describe('Initial state', () => {
        test('starts with full tokens', () => {
            expect(rateLimiter.getTokens()).toBe(3);
        });

        test('isAllowed returns true initially', () => {
            expect(rateLimiter.isAllowed()).toBe(true);
        });
    });

    describe('consume', () => {
        test('decrements token count', () => {
            rateLimiter.consume();
            expect(rateLimiter.getTokens()).toBe(2);
        });

        test('returns true when tokens available', () => {
            expect(rateLimiter.consume()).toBe(true);
        });

        test('returns false when no tokens available', () => {
            // Consume all tokens
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();

            expect(rateLimiter.consume()).toBe(false);
        });

        test('does not go below zero tokens', () => {
            // Try to consume more than available
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume(); // Should fail

            expect(rateLimiter.getTokens()).toBe(0);
        });
    });

    describe('isAllowed', () => {
        test('returns true when tokens available', () => {
            expect(rateLimiter.isAllowed()).toBe(true);
        });

        test('returns false when tokens exhausted', () => {
            // Consume all tokens
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();

            expect(rateLimiter.isAllowed()).toBe(false);
        });

        test('does not consume tokens', () => {
            // Check multiple times
            rateLimiter.isAllowed();
            rateLimiter.isAllowed();
            rateLimiter.isAllowed();

            // Tokens should still be full
            expect(rateLimiter.getTokens()).toBe(3);
        });
    });

    describe('Token refill', () => {
        test('refills tokens over time', async () => {
            // Consume all tokens
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();
            expect(rateLimiter.getTokens()).toBe(0);

            // Wait for 1 second (should add 2 tokens at refillRate of 2/sec)
            await new Promise(resolve => setTimeout(resolve, 1100));

            expect(rateLimiter.getTokens()).toBe(2);
        });

        test('does not exceed maxTokens', async () => {
            // Start with full tokens
            expect(rateLimiter.getTokens()).toBe(3);

            // Wait for potential refill
            await new Promise(resolve => setTimeout(resolve, 1100));

            // Should still be capped at maxTokens
            expect(rateLimiter.getTokens()).toBe(3);
        });

        test('partial time gives partial tokens', async () => {
            // Consume all tokens
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();

            // Wait 500ms (should add 1 token at refillRate of 2/sec)
            await new Promise(resolve => setTimeout(resolve, 600));

            expect(rateLimiter.getTokens()).toBe(1);
        });
    });

    describe('reset', () => {
        test('restores full tokens', () => {
            // Consume some tokens
            rateLimiter.consume();
            rateLimiter.consume();
            expect(rateLimiter.getTokens()).toBe(1);

            rateLimiter.reset();

            expect(rateLimiter.getTokens()).toBe(3);
        });

        test('works when tokens are exhausted', () => {
            // Exhaust all tokens
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();
            expect(rateLimiter.getTokens()).toBe(0);

            rateLimiter.reset();

            expect(rateLimiter.getTokens()).toBe(3);
            expect(rateLimiter.isAllowed()).toBe(true);
        });
    });

    describe('Default configuration', () => {
        test('uses default maxTokens of 10', () => {
            const defaultLimiter = new RateLimiter();
            expect(defaultLimiter.getTokens()).toBe(10);
        });

        test('uses default refillRate of 1 per second', async () => {
            const defaultLimiter = new RateLimiter();

            // Consume all tokens
            for (let i = 0; i < 10; i++) {
                defaultLimiter.consume();
            }
            expect(defaultLimiter.getTokens()).toBe(0);

            // Wait 2 seconds (should add 2 tokens at default rate of 1/sec)
            await new Promise(resolve => setTimeout(resolve, 2100));

            expect(defaultLimiter.getTokens()).toBe(2);
        });
    });

    describe('Rate limiting scenarios', () => {
        test('prevents burst of requests', () => {
            let allowed = 0;
            let denied = 0;

            // Try to send 10 requests with maxTokens of 3
            for (let i = 0; i < 10; i++) {
                if (rateLimiter.consume()) {
                    allowed++;
                } else {
                    denied++;
                }
            }

            expect(allowed).toBe(3);
            expect(denied).toBe(7);
        });

        test('allows sustained traffic within limits', async () => {
            // Send 3 requests (exhausts initial tokens)
            rateLimiter.consume();
            rateLimiter.consume();
            rateLimiter.consume();

            // Wait for 1 token to refill (500ms at 2/sec)
            await new Promise(resolve => setTimeout(resolve, 600));

            // Should be allowed
            expect(rateLimiter.consume()).toBe(true);
        });
    });
});
