/**
 * Tests for the debug-gated internal logger (JSSDK-03/04).
 *
 * The logger is the SDK's single console gateway: it must be a NO-OP unless
 * config.debug is true, must never throw, and must honour runtime toggles of
 * the debug flag.
 */
import { jest } from '@jest/globals';
import { createLogger, logger, setSharedLoggerConfig } from '../src/util/logger.js';

describe('createLogger (debug-gated internal logger)', () => {
    let warnSpy;
    let errorSpy;
    let logSpy;
    let infoSpy;

    beforeEach(() => {
        warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
        errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        logSpy = jest.spyOn(console, 'log').mockImplementation(() => {});
        infoSpy = jest.spyOn(console, 'info').mockImplementation(() => {});
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('is a no-op when debug is false (production default)', () => {
        const log = createLogger({ debug: false });

        log.warn('w');
        log.error('e');
        log.log('l');
        log.info('i');

        expect(warnSpy).not.toHaveBeenCalled();
        expect(errorSpy).not.toHaveBeenCalled();
        expect(logSpy).not.toHaveBeenCalled();
        expect(infoSpy).not.toHaveBeenCalled();
        expect(log.isEnabled()).toBe(false);
    });

    test('is a no-op when no config is supplied', () => {
        const log = createLogger();

        log.warn('w');
        log.error('e');

        expect(warnSpy).not.toHaveBeenCalled();
        expect(errorSpy).not.toHaveBeenCalled();
    });

    test('routes to the matching console method (with prefix) when debug is true', () => {
        const log = createLogger({ debug: true });

        log.warn('hello', { a: 1 });
        log.error('boom', new Error('x'));

        expect(log.isEnabled()).toBe(true);
        expect(warnSpy).toHaveBeenCalledWith('ApplicationLogger:', 'hello', { a: 1 });
        expect(errorSpy).toHaveBeenCalledWith('ApplicationLogger:', 'boom', expect.any(Error));
    });

    test('reads config.debug lazily so runtime toggles take effect', () => {
        const config = { debug: false };
        const log = createLogger(config);

        log.warn('first');
        expect(warnSpy).not.toHaveBeenCalled();

        config.debug = true;
        log.warn('second');
        expect(warnSpy).toHaveBeenCalledWith('ApplicationLogger:', 'second');

        config.debug = false;
        log.warn('third');
        expect(warnSpy).toHaveBeenCalledTimes(1);
    });

    test('never throws even if the underlying console method throws', () => {
        warnSpy.mockImplementation(() => {
            throw new Error('console exploded');
        });
        const log = createLogger({ debug: true });

        expect(() => log.warn('safe')).not.toThrow();
    });
});

describe('shared logger (config-less modules)', () => {
    let warnSpy;

    beforeEach(() => {
        warnSpy = jest.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        // Reset shared config to the safe default for other suites.
        setSharedLoggerConfig({ debug: false });
        jest.restoreAllMocks();
    });

    test('is silent by default and respects setSharedLoggerConfig', () => {
        setSharedLoggerConfig({ debug: false });
        logger.warn('quiet');
        expect(warnSpy).not.toHaveBeenCalled();

        setSharedLoggerConfig({ debug: true });
        logger.warn('loud');
        expect(warnSpy).toHaveBeenCalledWith('ApplicationLogger:', 'loud');
    });
});
