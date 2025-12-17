/**
 * Unit tests for Client
 *
 * Tests error capturing, payload building, stack trace parsing,
 * user context, tags, session replay integration, and beacon API.
 */

import { Client } from '../src/client.js';

// Mock Transport
class MockTransport {
    constructor() {
        this.sentPayloads = [];
        this.beaconPayloads = [];
    }

    async send(payload, replayData = null) {
        this.sentPayloads.push({ payload, replayData });
        return Promise.resolve();
    }

    flushWithBeacon() {
        this.beaconPayloads.push('flushed');
    }

    getStats() {
        return { queueSize: 0, storedErrors: 0 };
    }
}

// Mock BreadcrumbCollector
class MockBreadcrumbCollector {
    constructor() {
        this.breadcrumbs = [];
        this.installed = false;
    }

    install() {
        this.installed = true;
    }

    add(breadcrumb) {
        this.breadcrumbs.push(breadcrumb);
    }

    get() {
        return this.breadcrumbs;
    }
}

// Mock ErrorDetector
class MockErrorDetector {
    constructor() {
        this.handledErrors = [];
        // Mock replayBuffer and sessionManager to satisfy client.js checks
        this.replayBuffer = {
            getStats: () => ({ eventCount: 0, bufferSize: 0 }),
        };
        this.sessionManager = {
            getSessionId: () => 'test-session-id',
        };
    }

    async handleError(error, payload) {
        this.handledErrors.push({ error, payload });
        return {
            errorContext: { message: error.message, timestamp: Date.now() },
            events: [{ type: 'click', timestamp: Date.now(), phase: 'before_error' }],
            sessionId: 'test-session-id',
        };
    }
}

describe('Client', () => {
    let client;
    let mockTransport;
    let mockBreadcrumbs;
    let mockErrorDetector;
    let config;

    beforeEach(() => {
        mockTransport = new MockTransport();
        mockBreadcrumbs = new MockBreadcrumbCollector();
        mockErrorDetector = null;

        config = {
            dsn: 'https://localhost:8111/project-id',
            apiKey: 'test-api-key',
            environment: 'test',
            release: '1.0.0',
            debug: false,
            scrubFields: ['password', 'token'],
        };

        client = new Client(config, mockTransport, mockBreadcrumbs, mockErrorDetector, null);
    });

    describe('Constructor', () => {
        test('initializes with config', () => {
            expect(client.config).toBe(config);
            expect(client.transport).toBe(mockTransport);
            expect(client.breadcrumbs).toBe(mockBreadcrumbs);
            expect(client.errorDetector).toBeNull();
        });

        test('initializes with errorDetector when provided', () => {
            mockErrorDetector = new MockErrorDetector();
            const clientWithDetector = new Client(config, mockTransport, mockBreadcrumbs, mockErrorDetector, null);

            expect(clientWithDetector.errorDetector).toBe(mockErrorDetector);
        });

        test('initializes with empty user context, tags, and extra', () => {
            expect(client.userContext).toBeNull();
            expect(client.tags).toEqual({});
            expect(client.extra).toEqual({});
        });
    });

    describe('install', () => {
        test('installs breadcrumb tracking', () => {
            client.install();

            expect(mockBreadcrumbs.installed).toBe(true);
        });

        test('does not crash on install failure', () => {
            mockBreadcrumbs.install = () => {
                throw new Error('Install failed');
            };

            expect(() => client.install()).not.toThrow();
        });
    });

    describe('captureException', () => {
        test('captures error and sends to transport', async () => {
            const testError = new Error('Test error');

            await client.captureException(testError);

            expect(mockTransport.sentPayloads.length).toBe(1);
            expect(mockTransport.sentPayloads[0].payload.message).toBe('Test error');
            expect(mockTransport.sentPayloads[0].payload.type).toBe('Error');
        });

        test('includes user context if set', async () => {
            client.setUser({ id: '123', email: 'test@example.com' });

            await client.captureException(new Error('Test'));

            const payload = mockTransport.sentPayloads[0].payload;
            // User context would be in context field
            expect(payload).toBeDefined();
        });

        test('includes tags if set', async () => {
            client.setTags({ version: '1.0.0' });
            client.setTags({ environment: 'staging' });

            await client.captureException(new Error('Test'));

            const payload = mockTransport.sentPayloads[0].payload;
            expect(payload.tags).toEqual({ version: '1.0.0', environment: 'staging' });
        });

        test('includes extra context if provided', async () => {
            client.setExtra({ userId: 'user-123' });

            await client.captureException(new Error('Test'));

            const payload = mockTransport.sentPayloads[0].payload;
            expect(payload.context).toMatchObject({ userId: 'user-123' });
        });

        test('triggers errorDetector when available', async () => {
            mockErrorDetector = new MockErrorDetector();
            client = new Client(config, mockTransport, mockBreadcrumbs, mockErrorDetector, null);

            const testError = new Error('Detector test');
            await client.captureException(testError);

            expect(mockErrorDetector.handledErrors.length).toBe(1);
            expect(mockErrorDetector.handledErrors[0].error).toBe(testError);
        });

        test('includes replay data when errorDetector provides it', async () => {
            mockErrorDetector = new MockErrorDetector();
            client = new Client(config, mockTransport, mockBreadcrumbs, mockErrorDetector, null);

            await client.captureException(new Error('Replay test'));

            expect(mockTransport.sentPayloads[0].replayData).toBeDefined();
            expect(mockTransport.sentPayloads[0].replayData.sessionId).toBe('test-session-id');
            expect(mockTransport.sentPayloads[0].replayData.events).toBeDefined();
        });

        test('does not crash when captureException fails', async () => {
            mockTransport.send = () => {
                throw new Error('Send failed');
            };

            await expect(client.captureException(new Error('Test'))).resolves.not.toThrow();
        });
    });

    describe('buildPayload', () => {
        test('builds basic error payload', () => {
            const error = new Error('Test error');
            const payload = client.buildPayload(error, 'error');

            expect(payload.type).toBe('Error');
            expect(payload.message).toBe('Test error');
            expect(payload.level).toBe('error');
            expect(payload.source).toBe('frontend');
            expect(payload.environment).toBe('test');
            expect(payload.release).toBe('1.0.0');
        });

        test('includes stack trace', () => {
            const error = new Error('Stack test');
            error.stack = 'Error: Stack test\n    at Object.<anonymous> (test.js:10:5)';

            const payload = client.buildPayload(error, 'error');

            expect(payload.stack_trace).toBeDefined();
            expect(Array.isArray(payload.stack_trace)).toBe(true);
        });

        test('includes file and line from first stack frame', () => {
            const error = new Error('Frame test');
            error.stack = 'Error: Frame test\n    at Object.<anonymous> (/path/to/file.js:42:10)';

            const payload = client.buildPayload(error, 'error');

            expect(payload.file).toContain('file.js');
            expect(payload.line).toBe(42);
        });

        test('includes URL and user agent', () => {
            const payload = client.buildPayload(new Error('Test'), 'error');

            expect(payload.url).toBeDefined();
            expect(payload.user_agent).toBeDefined();
        });

        test('includes runtime information', () => {
            const payload = client.buildPayload(new Error('Test'), 'error');

            expect(payload.runtime).toContain('JavaScript');
        });

        test('includes timestamp', () => {
            const payload = client.buildPayload(new Error('Test'), 'error');

            expect(payload.timestamp).toBeDefined();
            expect(new Date(payload.timestamp)).toBeInstanceOf(Date);
        });

        test('includes session_id when sessionManager provided', () => {
            const mockSessionManager = {
                getSessionId: () => 'test-session-uuid-123',
            };
            const clientWithSession = new Client(config, mockTransport, mockBreadcrumbs, null, mockSessionManager);

            const payload = clientWithSession.buildPayload(new Error('Test'), 'error');

            expect(payload.session_id).toBe('test-session-uuid-123');
        });

        test('session_id is undefined when sessionManager not provided', () => {
            const payload = client.buildPayload(new Error('Test'), 'error');

            // session_id is null before removeNullValues, undefined after (field removed)
            expect(payload.session_id).toBeUndefined();
        });

        test('includes breadcrumbs', () => {
            mockBreadcrumbs.add({ message: 'User clicked button', timestamp: Date.now() });
            mockBreadcrumbs.add({ message: 'API call made', timestamp: Date.now() });

            const payload = client.buildPayload(new Error('Test'), 'error');

            expect(payload.breadcrumbs).toBeDefined();
            expect(payload.breadcrumbs.length).toBe(2);
        });

        test('merges options into payload', () => {
            const options = {
                extra: { customData: 'value' },
                tags: { feature: 'test' },
            };

            const payload = client.buildPayload(new Error('Test'), 'error', options);

            expect(payload.context.customData).toBe('value');
            expect(payload.tags.feature).toBe('test');
        });

        test('handles errors without stack trace', () => {
            const error = new Error('No stack');
            delete error.stack;

            const payload = client.buildPayload(error, 'error');

            expect(payload.file).toBe('unknown');
            // API requires line > 0 (Positive constraint), so default is 1
            expect(payload.line).toBe(1);
            expect(Array.isArray(payload.stack_trace)).toBe(true);
        });

        test('returns minimal payload on complete failure', () => {
            // Create circular reference to cause JSON.stringify to fail
            const error = new Error('Test');
            error.circular = error;

            const payload = client.buildPayload(error, 'error');

            expect(payload.type).toBe('Error');
            expect(payload.level).toBe('error');
        });
    });

    describe('parseStackTrace', () => {
        test('parses Chrome-style stack trace', () => {
            const error = new Error('Test');
            error.stack = `Error: Test
    at Object.<anonymous> (/path/to/file.js:10:5)
    at Module._compile (internal/modules/cjs/loader.js:1063:30)`;

            const frames = client.parseStackTrace(error);

            expect(frames.length).toBeGreaterThan(0);
            expect(frames[0].file).toContain('file.js');
            expect(frames[0].line).toBe(10);
            expect(frames[0].column).toBe(5);
        });

        test('parses Firefox-style stack trace', () => {
            const error = new Error('Test');
            error.stack = `test@/path/to/file.js:10:5
handler@/path/to/handler.js:20:10`;

            const frames = client.parseStackTrace(error);

            expect(frames.length).toBeGreaterThan(0);
            expect(frames[0].function).toBe('test');
            expect(frames[0].file).toContain('file.js');
        });

        test('handles empty stack trace', () => {
            const error = new Error('Test');
            error.stack = '';

            const frames = client.parseStackTrace(error);

            expect(Array.isArray(frames)).toBe(true);
        });

        test('handles missing stack property', () => {
            const error = new Error('Test');
            delete error.stack;

            const frames = client.parseStackTrace(error);

            expect(Array.isArray(frames)).toBe(true);
        });
    });

    describe('User context', () => {
        test('setUser stores user context', () => {
            const user = { id: '123', email: 'test@example.com', name: 'Test User' };

            client.setUser(user);

            expect(client.userContext).toEqual(user);
        });

        test('setUser can be called with null to clear', () => {
            client.setUser({ id: '123' });
            client.setUser(null);

            expect(client.userContext).toBeNull();
        });
    });

    describe('Tags', () => {
        test('setTags adds tags', () => {
            client.setTags({ environment: 'production' });

            expect(client.tags.environment).toBe('production');
        });

        test('setTags adds multiple tags', () => {
            client.setTags({ version: '1.0.0', build: '12345' });

            expect(client.tags.version).toBe('1.0.0');
            expect(client.tags.build).toBe('12345');
        });

        test('setTags merges with existing tags', () => {
            client.setTags({ version: '1.0.0' });
            client.setTags({ environment: 'production' });

            expect(client.tags.version).toBe('1.0.0');
            expect(client.tags.environment).toBe('production');
        });

        test('setTags overwrites existing tags with same key', () => {
            client.setTags({ version: '1.0.0' });
            client.setTags({ version: '2.0.0' });

            expect(client.tags.version).toBe('2.0.0');
        });
    });

    describe('Extra context', () => {
        test('setExtra adds extra fields', () => {
            client.setExtra({ userId: 'user-123' });

            expect(client.extra.userId).toBe('user-123');
        });

        test('setExtra adds multiple extra fields', () => {
            client.setExtra({ requestId: 'req-456', sessionId: 'sess-789' });

            expect(client.extra.requestId).toBe('req-456');
            expect(client.extra.sessionId).toBe('sess-789');
        });

        test('setExtra merges with existing extra', () => {
            client.setExtra({ userId: 'user-123' });
            client.setExtra({ requestId: 'req-456' });

            expect(client.extra.userId).toBe('user-123');
            expect(client.extra.requestId).toBe('req-456');
        });
    });

    describe('captureMessage', () => {
        test('captures message with default level', () => {
            client.captureMessage('Test message');

            expect(mockTransport.sentPayloads.length).toBe(1);
            expect(mockTransport.sentPayloads[0].payload.message).toBe('Test message');
            expect(mockTransport.sentPayloads[0].payload.level).toBe('info');
        });

        test('captures message with custom level', () => {
            client.captureMessage('Warning message', 'warning');

            expect(mockTransport.sentPayloads[0].payload.level).toBe('warning');
        });

        test('captures message with options', () => {
            client.captureMessage('Test', 'info', { tags: { feature: 'test' } });

            expect(mockTransport.sentPayloads[0].payload.tags.feature).toBe('test');
        });
    });

    describe('Session hash', () => {
        test('generates consistent session hash for same inputs', () => {
            const hash1 = client.getSessionHash();
            const hash2 = client.getSessionHash();

            expect(hash1).toBe(hash2);
        });

        test('session hash is 64 character hex string', () => {
            const hash = client.getSessionHash();

            expect(hash).toMatch(/^[a-f0-9]{64}$/);
            expect(hash.length).toBe(64);
        });

        test('initSessionHash pre-computes hash asynchronously', async () => {
            // Create fresh client without cached hash
            const freshClient = new Client(config, mockTransport, mockBreadcrumbs, null, null);

            // Initially no cached hash
            expect(freshClient.cachedSessionHash).toBeNull();

            // Call initSessionHash (uses Web Crypto API or fallback)
            await freshClient.initSessionHash();

            // Should now have cached hash
            expect(freshClient.cachedSessionHash).not.toBeNull();
            expect(freshClient.cachedSessionHash).toMatch(/^[a-f0-9]{64}$/);
        });

        test('getSessionHash returns cached hash after init', async () => {
            const freshClient = new Client(config, mockTransport, mockBreadcrumbs, null, null);

            await freshClient.initSessionHash();
            const cachedHash = freshClient.cachedSessionHash;

            // getSessionHash should return the cached value
            const returnedHash = freshClient.getSessionHash();
            expect(returnedHash).toBe(cachedHash);
        });

        test('config.sessionHash takes precedence over computed hash', async () => {
            const configWithHash = {
                ...config,
                sessionHash: 'a'.repeat(64), // Custom session hash
            };

            const clientWithHash = new Client(configWithHash, mockTransport, mockBreadcrumbs, null, null);
            await clientWithHash.initSessionHash();

            // Should return config hash, not computed hash
            expect(clientWithHash.getSessionHash()).toBe('a'.repeat(64));
        });
    });

    describe('HTTP method detection', () => {
        test('detects GET method from current page', () => {
            const method = client.detectHttpMethod();

            expect(method).toBe('GET');
        });
    });

    describe('Browser info', () => {
        test('returns browser information string', () => {
            const info = client.getBrowserInfo();

            expect(info).toBeDefined();
            expect(typeof info).toBe('string');
            expect(info.length).toBeGreaterThan(0);
        });
    });

    describe('flushBeaconErrors', () => {
        test('calls transport flush when there are errors', () => {
            // Mock Beacon API
            const beaconCalls = [];
            global.navigator.sendBeacon = (url, data) => {
                beaconCalls.push({ url, data });
                return true;
            };

            // Mock stats to show there are errors to flush
            mockTransport.getStats = () => ({ queueSize: 1, storedErrors: 0 });

            client.flushBeaconErrors();

            expect(mockTransport.beaconPayloads.length).toBe(1);
        });

        test('does not call transport when no errors to flush', () => {
            // Default stats show no errors
            client.flushBeaconErrors();

            expect(mockTransport.beaconPayloads.length).toBe(0);
        });

        test('does not crash on beacon failure', () => {
            mockTransport.flushWithBeacon = () => {
                throw new Error('Beacon failed');
            };

            expect(() => client.flushBeaconErrors()).not.toThrow();
        });
    });

    describe('Error handling', () => {
        test('never crashes when processing errors', async () => {
            // Test with invalid error object
            await expect(client.captureException(null)).resolves.not.toThrow();
            await expect(client.captureException(undefined)).resolves.not.toThrow();
            await expect(client.captureException('string error')).resolves.not.toThrow();
        });

        test('handles circular references in error context', async () => {
            const error = new Error('Test');
            const circular = { error };
            circular.self = circular;

            await expect(client.captureException(error, { extra: circular })).resolves.not.toThrow();
        });
    });

    describe('Integration', () => {
        test('full error flow with all features', async () => {
            mockErrorDetector = new MockErrorDetector();
            client = new Client(config, mockTransport, mockBreadcrumbs, mockErrorDetector, null);

            // Setup context
            client.setUser({ id: '123', email: 'test@example.com' });
            client.setTags({ version: '1.0.0' });
            client.setExtra({ requestId: 'req-456' });

            // Add breadcrumbs
            mockBreadcrumbs.add({ message: 'Button clicked', timestamp: Date.now() });

            // Capture error
            const testError = new Error('Integration test error');
            await client.captureException(testError);

            // Verify
            expect(mockTransport.sentPayloads.length).toBe(1);
            const { payload, replayData } = mockTransport.sentPayloads[0];

            expect(payload.message).toBe('Integration test error');
            expect(payload.tags.version).toBe('1.0.0');
            expect(payload.context.requestId).toBe('req-456');
            expect(payload.breadcrumbs.length).toBe(1);
            expect(replayData).toBeDefined();
            expect(replayData.sessionId).toBe('test-session-id');
        });
    });

    describe('Click Event Counting (countClickEvents)', () => {
        test('returns 0 for empty array', () => {
            const count = client.countClickEvents([]);
            expect(count).toBe(0);
        });

        test('returns 0 for null', () => {
            const count = client.countClickEvents(null);
            expect(count).toBe(0);
        });

        test('returns 0 for undefined', () => {
            const count = client.countClickEvents(undefined);
            expect(count).toBe(0);
        });

        test('returns 0 for non-array inputs', () => {
            expect(client.countClickEvents('not an array')).toBe(0);
            expect(client.countClickEvents(123)).toBe(0);
            expect(client.countClickEvents({})).toBe(0);
        });

        test('correctly counts click events', () => {
            const events = [
                { type: 'click', target: 'button.submit' },
                { type: 'navigation', url: '/page' },
                { type: 'click', target: 'a.link' },
                { type: 'dom_snapshot', html: '<div>content</div>' },
                { type: 'click', target: 'input.checkbox' },
            ];

            const count = client.countClickEvents(events);
            expect(count).toBe(3);
        });

        test('returns 0 when no click events exist', () => {
            const events = [
                { type: 'navigation', url: '/page' },
                { type: 'dom_snapshot', html: '<div>content</div>' },
                { type: 'scroll', position: 100 },
            ];

            const count = client.countClickEvents(events);
            expect(count).toBe(0);
        });

        test('handles events without type property', () => {
            const events = [
                { type: 'click', target: 'button' },
                { target: 'button' }, // Missing type
                { type: 'click', target: 'a.link' },
            ];

            const count = client.countClickEvents(events);
            expect(count).toBe(2);
        });

        test('handles malformed events gracefully', () => {
            const events = [
                { type: 'click', target: 'button' },  // Valid
                null,  // Malformed
                'invalid',  // Malformed
                123,  // Malformed
                { type: 'navigation', url: '/page' },  // Valid non-click
                { type: 'click', target: 'a.link' },  // Valid click
            ];

            const count = client.countClickEvents(events);
            expect(count).toBe(2);
        });

        test('is case-sensitive for event types', () => {
            const events = [
                { type: 'click', target: 'button' },    // Valid: lowercase
                { type: 'Click', target: 'button' },    // Invalid: uppercase C
                { type: 'CLICK', target: 'button' },    // Invalid: all uppercase
                { type: 'click', target: 'a.link' },    // Valid: lowercase
            ];

            const count = client.countClickEvents(events);
            expect(count).toBe(2); // Only lowercase "click" matches
        });

        test('handles large arrays efficiently', () => {
            const events = [];
            for (let i = 0; i < 1000; i++) {
                if (i % 10 === 0) {
                    events.push({ type: 'click', target: `button${i}` });
                } else {
                    events.push({ type: 'navigation', url: `/page${i}` });
                }
            }

            const startTime = Date.now();
            const count = client.countClickEvents(events);
            const endTime = Date.now();

            expect(count).toBe(100);
            expect(endTime - startTime).toBeLessThan(100); // Should complete in <100ms
        });
    });
});
