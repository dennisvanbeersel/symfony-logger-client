/**
 * Unit tests for the ApplicationLogger entry point (index.js)
 *
 * Covers:
 * - Constructor validation (dsn / apiKey required)
 * - Default config merge (scrubFields defaults, environment)
 * - sessionReplay API gating via exposeApi
 * - disable() clearing the buffer save interval (SPA memory-leak guard)
 * - window.ApplicationLogger global assignment
 */

import { jest } from '@jest/globals';
import ApplicationLogger from '../src/index.js';

const VALID_CONFIG = {
    dsn: 'https://localhost:8111/test-project-id',
    apiKey: 'test-api-key',
};

describe('ApplicationLogger (index.js)', () => {
    describe('Constructor validation', () => {
        test('throws when config is missing entirely', () => {
            expect(() => new ApplicationLogger()).toThrow(/DSN is required/);
        });

        test('throws when dsn is missing', () => {
            expect(() => new ApplicationLogger({ apiKey: 'key' })).toThrow(/DSN is required/);
        });

        test('throws when apiKey is missing', () => {
            expect(() => new ApplicationLogger({ dsn: VALID_CONFIG.dsn })).toThrow(/API Key is required/);
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
            expect(logger.config.sessionReplayEnabled).toBe(true);
            expect(logger.config.exposeApi).toBe(true);
            expect(logger.config.circuitBreakerFailureThreshold).toBe(5);
            expect(logger.config.rateLimiterMaxTokens).toBe(10);
            expect(logger.config.deduplicationWindowMs).toBe(5000);
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
