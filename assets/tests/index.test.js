/**
 * Unit tests for the ApplicationLogger entry point (index.js)
 *
 * Covers:
 * - Constructor validation (dsn + publishableKey required; apiKey is no longer accepted)
 * - Default config merge (scrubFields defaults, environment)
 * - sessionReplay API gating via exposeApi
 * - disable() clearing the buffer save interval (SPA memory-leak guard)
 * - window.ApplicationLogger global assignment
 */

import { jest } from '@jest/globals';
import ApplicationLogger from '../src/index.js';

const VALID_CONFIG = {
    dsn: 'https://localhost:8111/test-project-id',
    publishableKey: 'pk_test_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6',
};

describe('ApplicationLogger (index.js)', () => {
    describe('Constructor validation', () => {
        test('throws when config is missing entirely', () => {
            expect(() => new ApplicationLogger()).toThrow(/DSN is required/);
        });

        test('throws when dsn is missing', () => {
            expect(() => new ApplicationLogger({ publishableKey: 'pk_test_key' })).toThrow(/DSN is required/);
        });

        test('throws when publishableKey is missing', () => {
            expect(() => new ApplicationLogger({ dsn: VALID_CONFIG.dsn }))
                .toThrow(/Publishable Key is required/);
        });

        test('constructs with publishableKey + dsn and exposes the transport', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });
            expect(logger).toBeInstanceOf(ApplicationLogger);
            expect(logger.transport.publishableKey).toBe(VALID_CONFIG.publishableKey);
            expect(logger.transport.apiKey).toBeUndefined();
        });

        test('constructs successfully with valid config', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });
            expect(logger).toBeInstanceOf(ApplicationLogger);
            expect(logger.transport).toBeDefined();
            expect(logger.breadcrumbs).toBeDefined();
            expect(logger.client).toBeDefined();
            expect(logger.initialized).toBe(false);
        });
    });

    describe('Default config merge', () => {
        test('applies default scrubFields including financial PII fields', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });

            expect(logger.config.scrubFields).toEqual(
                expect.arrayContaining([
                    'password', 'token', 'api_key', 'secret',
                    'authorization', 'credit_card', 'creditcard',
                    'card_number', 'cvv', 'ssn', 'iban',
                ]),
            );
        });

        test('applies resilience defaults', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });

            expect(logger.config.debug).toBe(false);
            // JSSDK-02: session replay is OPT-IN (default off) so the default
            // install stays lean and adds no replay listeners/timers.
            expect(logger.config.sessionReplayEnabled).toBe(false);
            expect(logger.config.exposeApi).toBe(true);
            expect(logger.config.circuitBreakerFailureThreshold).toBe(5);
            expect(logger.config.rateLimiterMaxTokens).toBe(10);
            expect(logger.config.deduplicationWindowMs).toBe(5000);
        });

        test('JSSDK-02: default install does NOT initialize session replay components', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });

            // Opt-in default: no replay buffer/session/click-tracker is created,
            // so the lean default install activates none of the replay code paths.
            expect(logger.config.sessionReplayEnabled).toBe(false);
            expect(logger.sessionManager).toBeNull();
            expect(logger.replayBuffer).toBeNull();
            expect(logger.storageManager).toBeNull();
            expect(logger.errorDetector).toBeNull();
            expect(logger.heatmap).toBeNull();
        });

        test('JSSDK-02: default install adds no replay periodic-save timer on init', () => {
            const setIntervalSpy = jest.spyOn(global, 'setInterval');

            const logger = new ApplicationLogger({ ...VALID_CONFIG });
            logger.init();

            // The replay lifecycle's periodic buffer-save interval (the only
            // replay-specific timer; beforeunload/visibilitychange are also used
            // by the core beacon-flush path) must NOT be installed when replay is
            // off. installReplayLifecycle() is the sole setInterval caller in
            // index.js, so it must never run for the lean default install.
            expect(logger.bufferSaveInterval).toBeUndefined();
            expect(setIntervalSpy).not.toHaveBeenCalled();

            setIntervalSpy.mockRestore();
        });

        test('JSSDK-02: opt-in via config enables session replay', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });

            expect(logger.config.sessionReplayEnabled).toBe(true);
            expect(logger.sessionManager).not.toBeNull();
            expect(logger.replayBuffer).not.toBeNull();
            expect(logger.heatmap).not.toBeNull();
        });

        test('user config overrides defaults', () => {
            const logger = new ApplicationLogger({
                ...VALID_CONFIG,
                environment: 'staging',
                debug: true,
                circuitBreakerFailureThreshold: 99,
            });

            expect(logger.config.environment).toBe('staging');
            expect(logger.config.debug).toBe(true);
            expect(logger.config.circuitBreakerFailureThreshold).toBe(99);
        });

        test('initializes session replay components when enabled', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });

            expect(logger.sessionManager).not.toBeNull();
            expect(logger.replayBuffer).not.toBeNull();
            expect(logger.storageManager).not.toBeNull();
            expect(logger.errorDetector).not.toBeNull();
            expect(logger.heatmap).not.toBeNull();
        });

        test('does not initialize session replay when disabled', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: false });

            expect(logger.sessionManager).toBeNull();
            expect(logger.replayBuffer).toBeNull();
            expect(logger.errorDetector).toBeNull();
            expect(logger.heatmap).toBeNull();
        });
    });

    describe('sessionReplay API (exposeApi gating)', () => {
        test('returns null when exposeApi is false', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, exposeApi: false });
            expect(logger.sessionReplay).toBeNull();
        });

        test('returns an API object when exposeApi is true', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, exposeApi: true });
            const api = logger.sessionReplay;

            expect(api).not.toBeNull();
            expect(typeof api.enable).toBe('function');
            expect(typeof api.disable).toBe('function');
            expect(typeof api.isEnabled).toBe('function');
            expect(typeof api.getStats).toBe('function');
        });

        test('isEnabled reflects current config', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            expect(logger.sessionReplay.isEnabled()).toBe(true);
        });

        test('getStats returns disabled marker when replay is off', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: false });
            expect(logger.sessionReplay.getStats()).toEqual({ enabled: false });
        });

        test('getStats returns rich stats when replay is on', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            const stats = logger.sessionReplay.getStats();

            expect(stats.enabled).toBe(true);
            expect(stats.sessionId).toBeDefined();
            expect(stats.bufferStats).toBeDefined();
        });
    });

    describe('disable() clears the buffer save interval (SPA memory-leak guard)', () => {
        test('clearInterval is called and interval reference is nulled', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            logger.init();

            // init() must have set up the periodic save interval
            expect(logger.bufferSaveInterval).toBeTruthy();

            const clearSpy = jest.spyOn(global, 'clearInterval');

            logger.sessionReplay.disable();

            expect(clearSpy).toHaveBeenCalled();
            expect(logger.bufferSaveInterval).toBeNull();
            expect(logger.config.sessionReplayEnabled).toBe(false);

            clearSpy.mockRestore();
        });
    });

    describe('disable() then enable() re-arms session replay (RML-04)', () => {
        test('disable nulls replay components', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            logger.init();

            logger.sessionReplay.disable();

            // Components must be nulled so the `!this.heatmap` re-init path runs
            // on a later enable().
            expect(logger.heatmap).toBeNull();
            expect(logger.errorDetector).toBeNull();
            expect(logger.replayBuffer).toBeNull();
            expect(logger.sessionManager).toBeNull();
            expect(logger.config.sessionReplayEnabled).toBe(false);
        });

        test('a later enable() re-installs the lifecycle (interval + components)', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            logger.init();

            logger.sessionReplay.disable();
            expect(logger.bufferSaveInterval).toBeNull();
            expect(logger.heatmap).toBeNull();

            logger.sessionReplay.enable();

            // Re-armed: components recreated and the periodic-save interval back.
            expect(logger.config.sessionReplayEnabled).toBe(true);
            expect(logger.heatmap).not.toBeNull();
            expect(logger.errorDetector).not.toBeNull();
            expect(logger.sessionManager).not.toBeNull();
            expect(logger.bufferSaveInterval).toBeTruthy();

            // The client must point at the fresh replay components.
            expect(logger.client.errorDetector).toBe(logger.errorDetector);
            expect(logger.client.sessionManager).toBe(logger.sessionManager);

            // Clean up the timer.
            logger.sessionReplay.disable();
        });
    });

    describe('disable() prevents replay opt-out leak (BUNDLE-REPLAY-OPTOUT)', () => {
        test('a manual captureException after disable() carries NO replay data', async () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            logger.init();

            // Intercept transport so we can inspect the replayData argument.
            const sendSpy = jest.spyOn(logger.client.transport, 'send')
                .mockResolvedValue(undefined);

            // Sanity: while enabled, the client is wired to the replay components.
            expect(logger.client.errorDetector).not.toBeNull();
            expect(logger.client.sessionManager).not.toBeNull();

            logger.sessionReplay.disable();

            // The client's OWN pointers must be cleared, not just the index-level
            // ones — otherwise a stale pointer would re-attach replay.
            expect(logger.client.errorDetector).toBeNull();
            expect(logger.client.sessionManager).toBeNull();

            await logger.captureException(new Error('Manual after opt-out'));

            // Error is delivered, but with NO replay payload.
            expect(sendSpy).toHaveBeenCalledTimes(1);
            const replayArg = sendSpy.mock.calls[0][1];
            expect(replayArg).toBeUndefined();

            sendSpy.mockRestore();
        });

        test('a torn-down errorDetector.handleError returns null (no capture)', async () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });
            logger.init();

            const detector = logger.errorDetector;
            expect(detector).not.toBeNull();

            logger.sessionReplay.disable();

            // Even a direct call to the (now torn-down) detector must not capture.
            const result = await detector.handleError(new Error('Direct after disable'), {});
            expect(result).toBeNull();
        });
    });

    describe('init()', () => {
        test('installs components and sets initialized flag', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: true });

            logger.init();
            expect(logger.initialized).toBe(true);

            // Second init() is a no-op (guarded)
            logger.init();
            expect(logger.initialized).toBe(true);

            // Clean up the periodic save interval to avoid leaking timers
            logger.sessionReplay.disable();
        });

        test('works when session replay is disabled', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG, sessionReplayEnabled: false });
            expect(() => logger.init()).not.toThrow();
            expect(logger.initialized).toBe(true);
            expect(logger.bufferSaveInterval).toBeUndefined();
        });
    });

    describe('Delegating public API', () => {
        test('addBreadcrumb / setUser / setTags / setExtra delegate without throwing', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });

            expect(() => logger.addBreadcrumb({ type: 'ui', category: 'click', message: 'x' })).not.toThrow();
            expect(() => logger.setUser({ id: '1', email: 'a@b.com' })).not.toThrow();
            expect(() => logger.setTags({ env: 'test' })).not.toThrow();
            expect(() => logger.setExtra({ foo: 'bar' })).not.toThrow();
        });

        test('captureMessage delegates without throwing', () => {
            const logger = new ApplicationLogger({ ...VALID_CONFIG });
            expect(() => logger.captureMessage('hello', 'info')).not.toThrow();
        });
    });

    describe('window.ApplicationLogger global assignment', () => {
        test('the class is assigned to window.ApplicationLogger', () => {
            expect(window.ApplicationLogger).toBe(ApplicationLogger);
        });
    });
});
