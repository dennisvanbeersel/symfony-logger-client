/**
 * Unit tests for CircuitBreaker
 *
 * Tests the circuit breaker pattern implementation:
 * - State transitions (CLOSED -> OPEN -> HALF_OPEN -> CLOSED)
 * - Failure counting and threshold
 * - Timeout-based recovery
 * - State persistence via sessionStorage
 */
import { CircuitBreaker } from '../src/circuit-breaker.js';

describe('CircuitBreaker', () => {
    const STORAGE_KEY = 'app_logger_circuit_breaker';
    let circuitBreaker;

    beforeEach(() => {
        // Clear jsdom sessionStorage before each test
        sessionStorage.clear();

        circuitBreaker = new CircuitBreaker({
            failureThreshold: 3,
            timeout: 100, // Use short timeout for tests (100ms)
        });
    });

    describe('Initial state', () => {
        test('starts in CLOSED state', () => {
            expect(circuitBreaker.getState().state).toBe('closed');
        });

        test('starts with zero failure count', () => {
            expect(circuitBreaker.getState().failureCount).toBe(0);
        });

        test('isOpen returns false initially', () => {
            expect(circuitBreaker.isOpen()).toBe(false);
        });

        test('isHalfOpen returns false initially', () => {
            expect(circuitBreaker.isHalfOpen()).toBe(false);
        });
    });

    describe('Failure counting', () => {
        test('increments failure count on recordFailure', () => {
            circuitBreaker.recordFailure();
            expect(circuitBreaker.getState().failureCount).toBe(1);
        });

        test('opens circuit after reaching failure threshold', () => {
            circuitBreaker.recordFailure();
            circuitBreaker.recordFailure();
            circuitBreaker.recordFailure();

            expect(circuitBreaker.isOpen()).toBe(true);
            expect(circuitBreaker.getState().state).toBe('open');
        });

        test('does not open circuit before threshold', () => {
            circuitBreaker.recordFailure();
            circuitBreaker.recordFailure();

            expect(circuitBreaker.isOpen()).toBe(false);
            expect(circuitBreaker.getState().failureCount).toBe(2);
        });

        test('resets failure count on success', () => {
            circuitBreaker.recordFailure();
            circuitBreaker.recordFailure();
            circuitBreaker.recordSuccess();

            expect(circuitBreaker.getState().failureCount).toBe(0);
        });
    });

    describe('State transitions', () => {
        test('transitions to OPEN when threshold reached', () => {
            for (let i = 0; i < 3; i++) {
                circuitBreaker.recordFailure();
            }

            expect(circuitBreaker.getState().state).toBe('open');
            expect(circuitBreaker.getState().openedAt).toBeDefined();
        });

        test('transitions to HALF_OPEN after timeout', async () => {
            // Open the circuit
            for (let i = 0; i < 3; i++) {
                circuitBreaker.recordFailure();
            }
            expect(circuitBreaker.isOpen()).toBe(true);

            // Wait for timeout (100ms + buffer)
            await new Promise(resolve => setTimeout(resolve, 150));

            // isOpen() should trigger transition to half-open
            expect(circuitBreaker.isOpen()).toBe(false);
            expect(circuitBreaker.isHalfOpen()).toBe(true);
        });

        test('transitions from HALF_OPEN to CLOSED on success', async () => {
            // Open the circuit
            for (let i = 0; i < 3; i++) {
                circuitBreaker.recordFailure();
            }

            // Wait for timeout to trigger half-open
            await new Promise(resolve => setTimeout(resolve, 150));
            circuitBreaker.isOpen(); // Trigger transition

            expect(circuitBreaker.isHalfOpen()).toBe(true);

            // Record success in half-open state
            circuitBreaker.recordSuccess();

            expect(circuitBreaker.getState().state).toBe('closed');
            expect(circuitBreaker.getState().failureCount).toBe(0);
        });

        test('transitions from HALF_OPEN to OPEN on failure', async () => {
            // Open the circuit
            for (let i = 0; i < 3; i++) {
                circuitBreaker.recordFailure();
            }

            // Wait for timeout to trigger half-open
            await new Promise(resolve => setTimeout(resolve, 150));
            circuitBreaker.isOpen(); // Trigger transition

            expect(circuitBreaker.isHalfOpen()).toBe(true);

            // Record failure in half-open state
            circuitBreaker.recordFailure();

            expect(circuitBreaker.getState().state).toBe('open');
        });
    });

    describe('Manual reset', () => {
        test('reset() closes the circuit', () => {
            // Open the circuit
            for (let i = 0; i < 3; i++) {
                circuitBreaker.recordFailure();
            }
            expect(circuitBreaker.isOpen()).toBe(true);

            // Reset
            circuitBreaker.reset();

            expect(circuitBreaker.isOpen()).toBe(false);
            expect(circuitBreaker.getState().state).toBe('closed');
            expect(circuitBreaker.getState().failureCount).toBe(0);
        });
    });

    describe('shouldAttemptReset edge cases', () => {
        test('returns false when openedAt is null', () => {
            // Manually set state to OPEN without openedAt (edge case)
            sessionStorage.clear();
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                state: 'open',
                failureCount: 3,
                openedAt: null, // Edge case: open state without timestamp
            }));

            const cb = new CircuitBreaker({ failureThreshold: 3, timeout: 100 });

            // isOpen() calls shouldAttemptReset() which should return false
            // since openedAt is null, circuit stays open
            expect(cb.isOpen()).toBe(true);
        });
    });

    describe('State persistence', () => {
        test('saves state to sessionStorage', () => {
            circuitBreaker.recordFailure();

            const stored = JSON.parse(sessionStorage.getItem(STORAGE_KEY));
            expect(stored.state).toBe('closed');
            expect(stored.failureCount).toBe(1);
        });

        test('loads state from sessionStorage', () => {
            // Clear storage and pre-populate with test state
            sessionStorage.clear();
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
                state: 'open',
                failureCount: 5,
                openedAt: Date.now() - 10000, // 10 seconds ago
            }));

            // Create new instance that should load from storage
            const newCircuitBreaker = new CircuitBreaker({ failureThreshold: 3, timeout: 100 });

            expect(newCircuitBreaker.getState().state).toBe('open');
            expect(newCircuitBreaker.getState().failureCount).toBe(5);
        });

        test('handles missing sessionStorage gracefully', () => {
            // Store original and remove sessionStorage
            const originalSessionStorage = global.sessionStorage;
            delete global.sessionStorage;

            // Should not throw
            const cb = new CircuitBreaker({ failureThreshold: 3 });
            expect(cb.getState().state).toBe('closed');

            // Restore sessionStorage
            global.sessionStorage = originalSessionStorage;
        });

        test('handles corrupted sessionStorage data', () => {
            sessionStorage.setItem(STORAGE_KEY, 'invalid json');

            const newCircuitBreaker = new CircuitBreaker({ failureThreshold: 3 });
            expect(newCircuitBreaker.getState().state).toBe('closed');
        });
    });

    describe('Configuration', () => {
        test('uses default failureThreshold of 5', () => {
            const cb = new CircuitBreaker({});

            // Need 5 failures to open
            for (let i = 0; i < 4; i++) {
                cb.recordFailure();
            }
            expect(cb.isOpen()).toBe(false);

            cb.recordFailure();
            expect(cb.isOpen()).toBe(true);
        });

        test('respects custom failureThreshold', () => {
            const cb = new CircuitBreaker({
                failureThreshold: 2,
                timeout: 100,
            });

            cb.recordFailure();
            expect(cb.isOpen()).toBe(false);

            cb.recordFailure();
            expect(cb.isOpen()).toBe(true);
        });
    });

    describe('Static constants', () => {
        test('exposes state constants', () => {
            expect(CircuitBreaker.STATE_CLOSED).toBe('closed');
            expect(CircuitBreaker.STATE_OPEN).toBe('open');
            expect(CircuitBreaker.STATE_HALF_OPEN).toBe('half_open');
        });
    });
});
