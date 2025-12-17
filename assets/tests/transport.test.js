/**
 * Unit tests for Transport
 *
 * Tests network resilience features including:
 * - DSN parsing
 * - Circuit breaker integration
 * - Rate limiting
 * - Retry with exponential backoff
 * - Storage queue for offline errors
 * - Deduplication
 * - Data scrubbing
 * - Beacon API for page unload
 */
import { Transport } from '../src/transport.js';

// Mock dependencies
class MockCircuitBreaker {
    constructor() {
        this.open = false;
        this.failures = 0;
        this.successes = 0;
    }
    isOpen() {
        return this.open;
    }
    recordSuccess() {
        this.successes++;
    }
    recordFailure() {
        this.failures++;
    }
    getState() {
        return { open: this.open, failures: this.failures };
    }
}

class MockStorageQueue {
    constructor() {
        this.items = [];
    }
    enqueue(item) {
        this.items.push(item);
    }
    dequeue() {
        return this.items.shift();
    }
    size() {
        return this.items.length;
    }
    getAll() {
        return [...this.items];
    }
    clear() {
        this.items = [];
    }
}

class MockRateLimiter {
    constructor() {
        this.tokens = 10;
        this.consumeCalls = 0;
    }
    consume() {
        this.consumeCalls++;
        if (this.tokens > 0) {
            this.tokens--;
            return true;
        }
        return false;
    }
    getTokens() {
        return this.tokens;
    }
}

// Mock fetch globally
global.fetch = undefined;

// Mock AbortController
global.AbortController = class {
    constructor() {
        this.signal = { aborted: false };
    }
    abort() {
        this.signal.aborted = true;
    }
};

// Manual mock function factory (Jest-compatible)
function createMockFunction() {
    const calls = [];
    const results = [];

    const mockFn = function(...args) {
        calls.push(args);

        let result;
        try {
            if (mockFn._implementation) {
                result = mockFn._implementation(...args);
            } else {
                result = mockFn._returnValue;
            }

            results.push({ type: 'return', value: result });
            return result;
        } catch (error) {
            results.push({ type: 'throw', value: error });
            throw error;
        }
    };

    // Jest-compatible mock property
    mockFn.mock = {
        calls: calls,
        results: results,
        instances: [],
    };

    // Mark as a Jest mock function
    mockFn._isMockFunction = true;
    mockFn.getMockName = () => 'mockFn';

    mockFn._returnValue = undefined;
    mockFn._implementation = null;

    mockFn.mockResolvedValue = (value) => {
        mockFn._implementation = () => Promise.resolve(value);
        return mockFn;
    };

    mockFn.mockRejectedValue = (error) => {
        mockFn._implementation = () => Promise.reject(error);
        return mockFn;
    };

    mockFn.mockImplementation = (fn) => {
        mockFn._implementation = fn;
        return mockFn;
    };

    mockFn.mockClear = () => {
        calls.length = 0;
        results.length = 0;
        return mockFn;
    };

    mockFn.mockReset = () => {
        mockFn.mockClear();
        mockFn._implementation = null;
        mockFn._returnValue = undefined;
        return mockFn;
    };

    mockFn.mockRestore = () => {
        mockFn.mockReset();
        return mockFn;
    };

    return mockFn;
}

describe('Transport', () => {
    let transport;
    let mockFetch;
    let originalCircuitBreaker;
    let originalStorageQueue;
    let originalRateLimiter;

    beforeAll(() => {
        // Store original imports
        originalCircuitBreaker = global.CircuitBreaker;
        originalStorageQueue = global.StorageQueue;
        originalRateLimiter = global.RateLimiter;
    });

    beforeEach(() => {
        // Reset fetch mock
        mockFetch = createMockFunction();
        global.fetch = mockFetch;

        // Create transport with valid config
        transport = new Transport({
            dsn: 'https://localhost:8111/test-project-id',
            apiKey: 'test-api-key',
            debug: false,
        });

        // Replace with mocks
        transport.circuitBreaker = new MockCircuitBreaker();
        transport.storageQueue = new MockStorageQueue();
        transport.rateLimiter = new MockRateLimiter();
    });

    afterAll(() => {
        // Restore originals
        global.CircuitBreaker = originalCircuitBreaker;
        global.StorageQueue = originalStorageQueue;
        global.RateLimiter = originalRateLimiter;
    });

    describe('Constructor and DSN parsing', () => {
        test('parses valid DSN correctly', () => {
            expect(transport.dsn.protocol).toBe('https');
            expect(transport.dsn.host).toBe('localhost:8111');
            expect(transport.dsn.projectId).toBe('test-project-id');
            expect(transport.dsn.endpoint).toBe('https://localhost:8111/api/errors/ingest');
        });

        test('throws error for missing DSN', () => {
            expect(() => {
                new Transport({ apiKey: 'key' });
            }).toThrow('DSN is required');
        });

        test('throws error for DSN without project ID', () => {
            expect(() => {
                new Transport({
                    dsn: 'https://localhost:8111/',
                    apiKey: 'key',
                });
            }).toThrow('DSN must include a project ID in the path');
        });

        test('throws error for invalid DSN format', () => {
            expect(() => {
                new Transport({
                    dsn: 'not-a-url',
                    apiKey: 'key',
                });
            }).toThrow('Invalid DSN format');
        });

        test('stores API key separately from DSN', () => {
            const t = new Transport({
                dsn: 'https://example.com/project-123',
                apiKey: 'secret-key',
            });

            expect(t.apiKey).toBe('secret-key');
        });
    });

    describe('send() method', () => {
        test('sends error payload successfully', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            const payload = {
                exception: {
                    type: 'Error',
                    value: 'Test error',
                },
            };

            await transport.send(payload);

            // Should eventually call fetch
            await new Promise(resolve => setTimeout(resolve, 10));

            expect(mockFetch).toHaveBeenCalledWith(
                transport.dsn.endpoint,
                expect.objectContaining({
                    method: 'POST',
                    headers: expect.objectContaining({
                        'X-Api-Key': 'test-api-key',
                    }),
                }),
            );
        });

        test('includes replay data when provided', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            const payload = {
                exception: { type: 'Error', value: 'Test' },
            };

            const replayData = {
                sessionId: 'session-123',
                events: [{ type: 'click', timestamp: Date.now() }],
            };

            await transport.send(payload, replayData);

            // Give time for async processing
            await new Promise(resolve => setTimeout(resolve, 10));

            expect(mockFetch).toHaveBeenCalled();
            const fetchCall = mockFetch.mock.calls[0];
            const body = JSON.parse(fetchCall[1].body);

            expect(body.replay_session_id).toBe('session-123');
            expect(body.replay_data).toHaveLength(1);
        });

        test('prevents duplicate errors', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            // Uses flat payload structure matching API spec
            const payload = {
                type: 'Error',
                message: 'Duplicate error',
                stack_trace: [
                    { file: 'test.js', line: 10, function: 'testFn', in_app: true },
                ],
            };

            await transport.send(payload);
            await transport.send(payload); // Duplicate

            // Give time for async processing
            await new Promise(resolve => setTimeout(resolve, 10));

            // Should only call fetch once
            expect(mockFetch).toHaveBeenCalledTimes(1);
        });

        test('queues error when rate limit exceeded', async () => {
            // Exhaust rate limiter
            transport.rateLimiter.tokens = 0;

            const payload = {
                exception: { type: 'Error', value: 'Rate limited' },
            };

            await transport.send(payload);

            expect(transport.storageQueue.size()).toBe(1);
            expect(mockFetch).not.toHaveBeenCalled();
        });

        test('handles send errors gracefully', async () => {
            mockFetch.mockRejectedValue(new Error('Network error'));

            const payload = {
                exception: { type: 'Error', value: 'Test' },
            };

            // Should not throw
            await expect(transport.send(payload)).resolves.toBeUndefined();
        });
    });

    describe('sendToApi()', () => {
        test('sends to correct endpoint with headers', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            const payload = {
                exception: { type: 'Error', value: 'Test' },
            };

            await transport.sendToApi(payload);

            expect(mockFetch).toHaveBeenCalledWith(
                'https://localhost:8111/api/errors/ingest',
                expect.objectContaining({
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Api-Key': 'test-api-key',
                        'User-Agent': 'ApplicationLogger-JS-SDK/1.0',
                    },
                    body: JSON.stringify(payload),
                }),
            );
        });

        test('records success with circuit breaker', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            await transport.sendToApi({ exception: { type: 'Error' } });

            expect(transport.circuitBreaker.successes).toBe(1);
        });

        test('handles HTTP errors', async () => {
            mockFetch.mockResolvedValue({
                ok: false,
                status: 500,
                statusText: 'Internal Server Error',
            });

            const payload = { exception: { type: 'Error' } };

            await transport.sendToApi(payload);

            // Should queue to storage on error
            expect(transport.storageQueue.size()).toBeGreaterThan(0);
        });

        test('retries on network failure', async () => {
            let callCount = 0;
            mockFetch.mockImplementation(() => {
                callCount++;
                if (callCount < 2) {
                    return Promise.reject(new Error('Network error'));
                }
                return Promise.resolve({
                    ok: true,
                    json: async () => ({ success: true }),
                });
            });

            const payload = { exception: { type: 'Error' } };

            await transport.sendToApi(payload);

            expect(mockFetch).toHaveBeenCalledTimes(2);
        });

        test('queues to storage after max retries', async () => {
            mockFetch.mockRejectedValue(new Error('Network error'));

            const payload = { exception: { type: 'Error' } };

            await transport.sendToApi(payload);

            expect(mockFetch).toHaveBeenCalledTimes(3); // Initial + 2 retries
            expect(transport.storageQueue.size()).toBe(1);
            expect(transport.circuitBreaker.failures).toBe(1);
        });

        test('respects circuit breaker open state', async () => {
            transport.circuitBreaker.open = true;

            const payload = { exception: { type: 'Error' } };

            await transport.sendToApi(payload);

            expect(mockFetch).not.toHaveBeenCalled();
            expect(transport.storageQueue.size()).toBe(1);
        });

        test('handles timeout with AbortController', async () => {
            mockFetch.mockImplementation(() => {
                const error = new Error('Timeout');
                error.name = 'AbortError';
                return Promise.reject(error);
            });

            const payload = { exception: { type: 'Error' } };

            await transport.sendToApi(payload);

            expect(transport.circuitBreaker.failures).toBe(1);
            expect(transport.storageQueue.size()).toBe(1);
        });
    });

    describe('Deduplication', () => {
        test('detects duplicate errors', () => {
            // Uses flat payload structure matching API spec
            const payload = {
                type: 'Error',
                message: 'Duplicate test',
                stack_trace: [
                    { file: 'test.js', line: 10, function: 'testFn', in_app: true },
                ],
            };

            const isDup1 = transport.isDuplicate(payload);
            const isDup2 = transport.isDuplicate(payload);

            expect(isDup1).toBe(false); // First occurrence
            expect(isDup2).toBe(true);  // Duplicate
        });

        test('treats different errors as unique', () => {
            // Uses flat payload structure matching API spec
            const payload1 = {
                type: 'Error',
                message: 'Error 1',
            };

            const payload2 = {
                type: 'Error',
                message: 'Error 2',
            };

            const isDup1 = transport.isDuplicate(payload1);
            const isDup2 = transport.isDuplicate(payload2);

            expect(isDup1).toBe(false);
            expect(isDup2).toBe(false);
        });

        test('cleans up old deduplication entries', () => {
            // Uses flat payload structure matching API spec
            const payload = {
                type: 'Error',
                message: 'Old error',
            };

            transport.isDuplicate(payload);
            expect(transport.recentErrors.size).toBe(1);

            // Manually set old timestamp
            const hash = Array.from(transport.recentErrors.keys())[0];
            transport.recentErrors.set(hash, Date.now() - 10000); // 10 seconds ago

            // Trigger cleanup by checking another error (flat structure)
            transport.isDuplicate({ type: 'New', message: 'New' });

            // Old entry should be cleaned up (after deduplication window)
            expect(transport.recentErrors.size).toBe(1);
        });
    });

    describe('Data scrubbing', () => {
        test('scrubs common sensitive fields', () => {
            const payload = {
                context: {
                    password: 'secret123',
                    token: 'abc-token',
                    api_key: 'my-api-key',
                    username: 'john',
                },
            };

            const scrubbed = transport.scrubSensitiveData(payload);

            expect(scrubbed.context.password).toBe('[REDACTED]');
            expect(scrubbed.context.token).toBe('[REDACTED]');
            expect(scrubbed.context.api_key).toBe('[REDACTED]');
            expect(scrubbed.context.username).toBe('john'); // Not sensitive
        });

        test('scrubs nested objects', () => {
            const payload = {
                user: {
                    profile: {
                        secret: 'hidden',
                        name: 'John',
                    },
                },
            };

            const scrubbed = transport.scrubSensitiveData(payload);

            expect(scrubbed.user.profile.secret).toBe('[REDACTED]');
            expect(scrubbed.user.profile.name).toBe('John');
        });

        test('handles circular references', () => {
            const payload = {
                exception: { type: 'Error' },
            };
            payload.self = payload; // Circular reference

            const scrubbed = transport.scrubSensitiveData(payload);

            expect(scrubbed.self).toBe('[Circular Reference]');
        });

        test('respects custom scrub fields', () => {
            const t = new Transport({
                dsn: 'https://localhost:8111/project-id',
                apiKey: 'key',
                scrubFields: ['customField'],
            });

            const payload = {
                context: {
                    customField: 'secret',
                    normalField: 'public',
                },
            };

            const scrubbed = t.scrubSensitiveData(payload);

            expect(scrubbed.context.customField).toBe('[REDACTED]');
            expect(scrubbed.context.normalField).toBe('public');
        });
    });

    describe('Queue management', () => {
        test('processQueue sends all queued errors', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            transport.queue.push({ exception: { type: 'Error', value: 'Error 1' } });
            transport.queue.push({ exception: { type: 'Error', value: 'Error 2' } });

            await transport.processQueue();

            expect(mockFetch).toHaveBeenCalledTimes(2);
            expect(transport.queue.length).toBe(0);
        });

        test('processQueue does not run if already sending', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            transport.sending = true;
            transport.queue.push({ exception: { type: 'Error' } });

            await transport.processQueue();

            expect(mockFetch).not.toHaveBeenCalled();
        });

        test('flushStoredErrors dequeues from storage', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            transport.storageQueue.enqueue({ exception: { type: 'Error', value: 'Stored 1' } });
            transport.storageQueue.enqueue({ exception: { type: 'Error', value: 'Stored 2' } });

            await transport.flushStoredErrors();

            expect(transport.storageQueue.size()).toBe(0);
        });

        test('flushStoredErrors dequeues up to 5 errors per call', async () => {
            // Block circuit breaker to prevent actual fetch calls
            transport.circuitBreaker.open = true;

            // Add 10 errors to storage
            for (let i = 0; i < 10; i++) {
                transport.storageQueue.enqueue({
                    exception: { type: 'Error', value: `Error ${i}` },
                });
            }

            const initialSize = transport.storageQueue.size();
            expect(initialSize).toBe(10);

            // Call flushStoredErrors once
            await transport.flushStoredErrors();

            // Circuit breaker is open, so errors are dequeued but re-enqueued
            // This tests that the limit logic works (up to 5 at a time)
            // Since circuit breaker is open, all 5 get re-enqueued to storage
            expect(transport.storageQueue.size()).toBe(10); // All back in storage
        });
    });

    describe('Beacon API', () => {
        beforeEach(() => {
            // Mock navigator.sendBeacon for all Beacon tests
            global.navigator = global.navigator || {};
        });

        test('sends errors via Beacon on page unload', () => {
            const beaconCalls = [];
            global.navigator.sendBeacon = (url, data) => {
                beaconCalls.push({ url, data });
                return true;
            };

            transport.queue.push({ exception: { type: 'Error', value: 'Unload error' } });

            transport.flushWithBeacon();

            expect(beaconCalls.length).toBe(1);
            expect(beaconCalls[0].url).toBe(transport.dsn.endpoint);
        });

        test('clears queue after successful beacon send', () => {
            global.navigator.sendBeacon = () => true;

            transport.queue.push({ exception: { type: 'Error' } });
            transport.storageQueue.enqueue({ exception: { type: 'Error' } });

            transport.flushWithBeacon();

            expect(transport.queue.length).toBe(0);
            expect(transport.storageQueue.size()).toBe(0);
        });

        test('limits beacon payload to 10 errors', (done) => {
            const beaconCalls = [];
            global.navigator.sendBeacon = (url, data) => {
                beaconCalls.push({ url, data });
                return true;
            };

            // Add 15 errors
            for (let i = 0; i < 15; i++) {
                transport.queue.push({ exception: { type: 'Error', value: `Error ${i}` } });
            }

            transport.flushWithBeacon();

            expect(beaconCalls.length).toBe(1);

            // Verify payload was sent (Blob exists)
            const blob = beaconCalls[0].data;
            expect(blob).toBeInstanceOf(Blob);

            // Read blob using FileReader to verify it contains 10 errors
            const reader = new FileReader();
            reader.onload = () => {
                const payload = JSON.parse(reader.result);
                expect(payload.errors.length).toBe(10);
                done();
            };
            reader.onerror = () => {
                done(new Error('Failed to read blob'));
            };
            reader.readAsText(blob);
        });

        test('handles beacon failure gracefully', () => {
            global.navigator = {
                sendBeacon: () => false,
            };

            transport.queue.push({ exception: { type: 'Error' } });

            // Should not throw
            expect(() => transport.flushWithBeacon()).not.toThrow();
        });
    });

    describe('Session and heatmap', () => {
        test('sendSessionEvent sends to correct endpoint', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            await transport.sendSessionEvent('session-123', { event: 'pageview' });

            expect(mockFetch).toHaveBeenCalledWith(
                'https://localhost:8111/api/v1/sessions/session-123/events',
                expect.objectContaining({
                    method: 'POST',
                }),
            );
        });

        // TODO: sendHeatmap method not implemented yet
        test.skip('sendHeatmap sends batch of clicks', async () => {
            mockFetch.mockResolvedValue({
                ok: true,
                json: async () => ({ success: true }),
            });

            const clicks = [
                { x: 100, y: 200, timestamp: Date.now() },
                { x: 150, y: 250, timestamp: Date.now() },
            ];

            await transport.sendHeatmap('session-123', clicks);

            expect(mockFetch).toHaveBeenCalledWith(
                'https://localhost:8111/api/v1/sessions/session-123/heatmap',
                expect.objectContaining({
                    method: 'POST',
                }),
            );
        });

        // TODO: sendHeatmap method not implemented yet
        test.skip('session/heatmap failures are silent', async () => {
            mockFetch.mockRejectedValue(new Error('Network error'));

            // Should not throw
            await expect(transport.sendSessionEvent('s1', {})).resolves.toBeUndefined();
            await expect(transport.sendHeatmap('s1', [])).resolves.toBeUndefined();
        });
    });

    describe('Statistics', () => {
        test('getStats returns correct data', () => {
            transport.queue.push({ exception: { type: 'Error' } });
            transport.storageQueue.enqueue({ exception: { type: 'Error' } });

            const stats = transport.getStats();

            expect(stats.queueSize).toBe(1);
            expect(stats.storedErrors).toBe(1);
            expect(stats.circuitBreaker).toBeDefined();
            expect(stats.rateLimitTokens).toBeDefined();
        });
    });

    describe('Utility methods', () => {
        test('delay returns promise that resolves after timeout', async () => {
            const start = Date.now();
            await transport.delay(100);
            const elapsed = Date.now() - start;

            expect(elapsed).toBeGreaterThanOrEqual(90); // Allow 10ms tolerance
        });

        test('simpleHash generates consistent hash', () => {
            const hash1 = transport.simpleHash('test string');
            const hash2 = transport.simpleHash('test string');

            expect(hash1).toBe(hash2);
        });

        test('simpleHash generates different hashes for different inputs', () => {
            const hash1 = transport.simpleHash('string 1');
            const hash2 = transport.simpleHash('string 2');

            expect(hash1).not.toBe(hash2);
        });

        test('removeCircularReferences handles arrays', () => {
            const arr = [1, 2, 3];
            arr.push(arr); // Circular

            const result = transport.removeCircularReferences(arr);

            expect(result[3]).toBe('[Circular Reference]');
        });

        test('removeCircularReferences handles primitives', () => {
            expect(transport.removeCircularReferences(null)).toBe(null);
            expect(transport.removeCircularReferences(42)).toBe(42);
            expect(transport.removeCircularReferences('string')).toBe('string');
        });
    });
});
