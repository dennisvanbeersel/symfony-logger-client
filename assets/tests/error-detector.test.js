/**
 * Unit tests for ErrorDetector
 *
 * Tests error detection, filtering, deduplication, replay triggering,
 * and integration with ReplayBuffer and SessionManager.
 */

import { ErrorDetector } from '../src/error-detector.js';
import { ReplayBuffer } from '../src/replay-buffer.js';
import { SessionManager } from '../src/session-manager.js';

// Mock localStorage
const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (key) => store[key] || null,
        setItem: (key, value) => { store[key] = value.toString(); },
        removeItem: (key) => { delete store[key]; },
        clear: () => { store = {}; },
    };
})();

global.localStorage = localStorageMock;

describe('ErrorDetector', () => {
    let errorDetector;
    let replayBuffer;
    let sessionManager;
    let onErrorDetectedCallback;
    let callbackResults;

    beforeEach(() => {
        localStorage.clear();
        callbackResults = [];

        // Create dependencies
        replayBuffer = new ReplayBuffer({
            bufferBeforeErrorSeconds: 30,
            bufferBeforeErrorClicks: 10,
            bufferAfterErrorSeconds: 30,
            bufferAfterErrorClicks: 10,
            maxBufferSizeMB: 5,
            debug: false,
        });

        sessionManager = new SessionManager({
            sessionTimeoutMinutes: 30,
            debug: false,
        });

        // Callback to capture error detection results
        onErrorDetectedCallback = async (errorContext, events, errorPayload) => {
            callbackResults.push({ errorContext, events, errorPayload });
        };

        errorDetector = new ErrorDetector(
            replayBuffer,
            sessionManager,
            onErrorDetectedCallback,
            {
                debug: false,
                ignoreErrors: [],
            },
        );
    });

    afterEach(() => {
        if (errorDetector.isInstalled) {
            errorDetector.uninstall();
        }
    });

    describe('Constructor', () => {
        test('initializes with correct state', () => {
            expect(errorDetector.isInstalled).toBe(false);
            expect(errorDetector.stats.errorsDetected).toBe(0);
            expect(errorDetector.stats.errorsIgnored).toBe(0);
            expect(errorDetector.stats.replaysCaptured).toBe(0);
            expect(errorDetector.stats.duplicatesPrevented).toBe(0);
        });

        test('accepts configuration options', () => {
            const customDetector = new ErrorDetector(
                replayBuffer,
                sessionManager,
                onErrorDetectedCallback,
                {
                    debug: true,
                    ignoreErrors: ['NetworkError', 'AbortError'],
                },
            );

            expect(customDetector.config.debug).toBe(true);
            expect(customDetector.config.ignoreErrors).toEqual(['NetworkError', 'AbortError']);
        });
    });

    describe('install and uninstall', () => {
        test('install marks detector as installed', () => {
            errorDetector.install();

            expect(errorDetector.isInstalled).toBe(true);
            // Note: ErrorDetector does NOT set window.onerror
            // That's handled by the Client class
            // ErrorDetector is called FROM Client when errors occur
            expect(errorDetector.recentErrorsCleanupInterval).not.toBeNull();
        });

        test('install is idempotent', () => {
            errorDetector.install();
            const handler1 = window.onerror;

            errorDetector.install(); // Install again
            const handler2 = window.onerror;

            expect(handler1).toBe(handler2);
            expect(errorDetector.isInstalled).toBe(true);
        });

        test('uninstall restores original handlers', () => {
            const originalOnError = window.onerror;
            const originalOnUnhandledRejection = window.onunhandledrejection;

            errorDetector.install();
            errorDetector.uninstall();

            expect(errorDetector.isInstalled).toBe(false);
            expect(window.onerror).toBe(originalOnError);
            expect(window.onunhandledrejection).toBe(originalOnUnhandledRejection);
        });
    });

    describe('handleError', () => {
        test('captures error and triggers replay', async () => {
            const testError = new Error('Test error message');
            const errorPayload = { type: 'Error', message: 'Test error message' };

            const result = await errorDetector.handleError(testError, errorPayload);

            expect(result).not.toBeNull();
            expect(result.errorContext).toBeDefined();
            expect(result.errorContext.message).toBe('Test error message');
            expect(result.events).toBeDefined();
            expect(result.sessionId).toBeDefined();
            expect(result.sessionId).toBe(sessionManager.getSessionId());
            expect(errorDetector.stats.errorsDetected).toBe(1);
            expect(errorDetector.stats.replaysCaptured).toBe(1);
        });

        test('calls callback with error context and events', async () => {
            const testError = new Error('Callback test');
            const errorPayload = { type: 'Error', message: 'Callback test' };

            await errorDetector.handleError(testError, errorPayload);

            expect(callbackResults).toHaveLength(1);
            expect(callbackResults[0].errorContext.message).toBe('Callback test');
            expect(callbackResults[0].events).toBeDefined();
            expect(callbackResults[0].errorPayload).toBe(errorPayload);
        });

        test('starts post-error recording in replay buffer', async () => {
            const testError = new Error('Recording test');

            expect(replayBuffer.isRecording()).toBe(false);

            await errorDetector.handleError(testError, {});

            expect(replayBuffer.isRecording()).toBe(true);
        });

        test('returns error events from buffer', async () => {
            // Add some events to buffer before error
            replayBuffer.addEvent({ type: 'click', timestamp: Date.now() });
            replayBuffer.addEvent({ type: 'click', timestamp: Date.now() });

            const testError = new Error('Buffer test');
            const result = await errorDetector.handleError(testError, {});

            expect(result.events.length).toBeGreaterThanOrEqual(3); // 2 clicks + error marker
        });
    });

    describe('Error filtering', () => {
        test('ignores errors matching ignore patterns', async () => {
            const scriptError = new Error('Script error');
            scriptError.message = 'Script error';

            const result = await errorDetector.handleError(scriptError, {});

            expect(result).toBeNull();
            expect(errorDetector.stats.errorsIgnored).toBe(1);
        });

        test('ignores network errors', async () => {
            const networkError = new Error('Network error occurred');

            const result = await errorDetector.handleError(networkError, {});

            expect(result).toBeNull();
            expect(errorDetector.stats.errorsIgnored).toBe(1);
        });

        test('ignores webpack chunk loading errors', async () => {
            const chunkError = new Error('Loading chunk 5 failed');

            const result = await errorDetector.handleError(chunkError, {});

            expect(result).toBeNull();
            expect(errorDetector.stats.errorsIgnored).toBe(1);
        });

        test('processes errors that do not match ignore patterns', async () => {
            const validError = new Error('Legitimate application error');

            const result = await errorDetector.handleError(validError, {});

            expect(result).not.toBeNull();
            expect(errorDetector.stats.errorsIgnored).toBe(0);
            expect(errorDetector.stats.errorsDetected).toBe(1);
        });
    });

    describe('Error deduplication', () => {
        test('prevents duplicate error captures', async () => {
            const testError = new Error('Duplicate test');

            const result1 = await errorDetector.handleError(testError, {});
            const result2 = await errorDetector.handleError(testError, {});

            expect(result1).not.toBeNull();
            expect(result2).toBeNull();
            expect(errorDetector.stats.errorsDetected).toBe(2); // Both attempts detected
            expect(errorDetector.stats.replaysCaptured).toBe(1); // Only first captured
            expect(errorDetector.stats.duplicatesPrevented).toBe(1);
        });

        test('allows same error after deduplication window', async () => {
            const testError = new Error('Window test');

            const result1 = await errorDetector.handleError(testError, {});

            // Clear deduplication cache
            errorDetector.recentErrors.clear();

            const result2 = await errorDetector.handleError(testError, {});

            expect(result1).not.toBeNull();
            expect(result2).not.toBeNull();
            expect(errorDetector.stats.replaysCaptured).toBe(2);
        });

        test('treats different errors as unique', async () => {
            const error1 = new Error('First error');
            const error2 = new Error('Second error');

            const result1 = await errorDetector.handleError(error1, {});
            const result2 = await errorDetector.handleError(error2, {});

            expect(result1).not.toBeNull();
            expect(result2).not.toBeNull();
            expect(errorDetector.stats.replaysCaptured).toBe(2);
            expect(errorDetector.stats.duplicatesPrevented).toBe(0);
        });
    });

    describe('Error fingerprinting', () => {
        test('generates fingerprint from error message and stack', () => {
            const error = new Error('Fingerprint test');
            error.stack = 'Error: Fingerprint test\n    at Object.<anonymous> (test.js:10:5)';

            const fingerprint = errorDetector.generateErrorFingerprint(error);

            expect(fingerprint).toBeDefined();
            expect(typeof fingerprint).toBe('string');
        });

        test('generates same fingerprint for identical errors', () => {
            const error1 = new Error('Same error');
            error1.stack = 'Error: Same error\n    at test.js:10:5';

            const error2 = new Error('Same error');
            error2.stack = 'Error: Same error\n    at test.js:10:5';

            const fingerprint1 = errorDetector.generateErrorFingerprint(error1);
            const fingerprint2 = errorDetector.generateErrorFingerprint(error2);

            expect(fingerprint1).toBe(fingerprint2);
        });

        test('generates different fingerprints for different errors', () => {
            const error1 = new Error('First error');
            const error2 = new Error('Second error');

            const fingerprint1 = errorDetector.generateErrorFingerprint(error1);
            const fingerprint2 = errorDetector.generateErrorFingerprint(error2);

            expect(fingerprint1).not.toBe(fingerprint2);
        });

        test('handles errors without stack traces', () => {
            const error = new Error('No stack');
            delete error.stack;

            const fingerprint = errorDetector.generateErrorFingerprint(error);

            expect(fingerprint).toBeDefined();
            expect(typeof fingerprint).toBe('string');
        });
    });

    describe('Statistics', () => {
        test('tracks errors detected', async () => {
            await errorDetector.handleError(new Error('Test 1'), {});
            await errorDetector.handleError(new Error('Test 2'), {});

            const stats = errorDetector.getStats();

            expect(stats.errorsDetected).toBe(2);
        });

        test('tracks errors ignored', async () => {
            await errorDetector.handleError(new Error('Script error'), {});
            await errorDetector.handleError(new Error('Network error'), {});

            const stats = errorDetector.getStats();

            expect(stats.errorsIgnored).toBe(2);
        });

        test('tracks replays captured', async () => {
            await errorDetector.handleError(new Error('Replay 1'), {});
            await errorDetector.handleError(new Error('Replay 2'), {});

            const stats = errorDetector.getStats();

            expect(stats.replaysCaptured).toBe(2);
        });

        test('tracks duplicates prevented', async () => {
            const error = new Error('Duplicate');
            await errorDetector.handleError(error, {});
            await errorDetector.handleError(error, {});
            await errorDetector.handleError(error, {});

            const stats = errorDetector.getStats();

            expect(stats.duplicatesPrevented).toBe(2);
        });
    });

    describe('Integration with ReplayBuffer', () => {
        test('triggers buffer recording on error', async () => {
            expect(replayBuffer.isRecording()).toBe(false);

            await errorDetector.handleError(new Error('Trigger test'), {});

            expect(replayBuffer.isRecording()).toBe(true);
        });

        test('returns buffered events including error marker', async () => {
            // Add events before error
            replayBuffer.addEvent({ type: 'click', timestamp: Date.now() });

            const result = await errorDetector.handleError(new Error('Buffer integration'), {});

            const events = result.events;
            const errorEvent = events.find(e => e.phase === 'error');
            const beforeEvents = events.filter(e => e.phase === 'before_error');

            expect(errorEvent).toBeDefined();
            expect(beforeEvents.length).toBeGreaterThan(0);
        });
    });

    describe('Integration with SessionManager', () => {
        test('includes session ID in response', async () => {
            const expectedSessionId = sessionManager.getSessionId();

            const result = await errorDetector.handleError(new Error('Session test'), {});

            expect(result.sessionId).toBe(expectedSessionId);
        });

        test('links error to active session', async () => {
            const sessionId = sessionManager.getSessionId();

            const result = await errorDetector.handleError(new Error('Link test'), {});

            expect(result.sessionId).toBe(sessionId);
            expect(sessionManager.metadata.pageCount).toBeGreaterThan(0);
        });
    });

    describe('Error handling', () => {
        test('handles errors in error handler gracefully', async () => {
            // Callback that throws error
            const failingCallback = async () => {
                throw new Error('Callback failed');
            };

            const faultyDetector = new ErrorDetector(
                replayBuffer,
                sessionManager,
                failingCallback,
                { debug: false },
            );

            const result = await faultyDetector.handleError(new Error('Test'), {});

            // Should return null when callback fails (error caught and logged)
            expect(result).toBeNull();
            expect(faultyDetector.stats.errorsDetected).toBe(1);
        });

        test('continues operation after error', async () => {
            // Simulate error during handling
            const result1 = await errorDetector.handleError(new Error('Test 1'), {});

            // Should still work
            const result2 = await errorDetector.handleError(new Error('Test 2'), {});

            expect(result1).not.toBeNull();
            expect(result2).not.toBeNull();
        });
    });

    describe('Cleanup', () => {
        test('starts cleanup interval on install', () => {
            errorDetector.install();

            expect(errorDetector.recentErrorsCleanupInterval).not.toBeNull();
        });

        test('clears interval on uninstall', () => {
            errorDetector.install();
            const intervalId = errorDetector.recentErrorsCleanupInterval;

            errorDetector.uninstall();

            expect(errorDetector.recentErrorsCleanupInterval).toBeNull();
        });
    });
});
