/**
 * Regression test for SEC-JS-01 (console.error auto-capture leaked unscrubbed args).
 *
 * The zero-config error-capture path builds context.extra.consoleMessage by
 * joining the non-Error console arguments. It used to do `String(arg)` with NO
 * scrubbing, so a URL string argument carrying a query secret (?token=...) was
 * shipped verbatim — transport.scrubObject does not key-scrub 'consoleMessage'.
 * These tests prove each captured arg now passes through scrubUrlQueryValues.
 */
import { BreadcrumbCollector } from '../src/breadcrumbs.js';

describe('console.error auto-capture scrubs consoleMessage (SEC-JS-01)', () => {
    let originalConsole;
    let originalFetch;
    let originalPushState;
    let originalReplaceState;

    beforeEach(() => {
        originalConsole = {
            log: console.log,
            info: console.info,
            warn: console.warn,
            error: console.error,
            debug: console.debug,
        };
        originalFetch = window.fetch;
        originalPushState = history.pushState;
        originalReplaceState = history.replaceState;
    });

    afterEach(() => {
        console.log = originalConsole.log;
        console.info = originalConsole.info;
        console.warn = originalConsole.warn;
        console.error = originalConsole.error;
        console.debug = originalConsole.debug;
        window.fetch = originalFetch;
        history.pushState = originalPushState;
        history.replaceState = originalReplaceState;
    });

    test('captured consoleMessage redacts sensitive query values in URL args', () => {
        const captured = [];
        const breadcrumbs = new BreadcrumbCollector(50, (error, options) => {
            captured.push({ error, options });
        });
        breadcrumbs.install();

        const err = new Error('boom');
        console.error('failed loading', 'https://api.example.com/cb?token=topsecret&page=2', err);

        expect(captured).toHaveLength(1);
        const { extra } = captured[0].options;
        expect(extra.consoleError).toBe(true);

        // Secret must NOT be present anywhere in consoleMessage.
        expect(extra.consoleMessage).not.toContain('topsecret');
        expect(extra.consoleMessage).toContain('token=[REDACTED]');

        // Non-sensitive structure preserved.
        expect(extra.consoleMessage).toContain('failed loading');
        expect(extra.consoleMessage).toContain('https://api.example.com/cb');
        expect(extra.consoleMessage).toContain('page=2');
    });

    test('plain (non-URL) console args are unchanged in consoleMessage', () => {
        const captured = [];
        const breadcrumbs = new BreadcrumbCollector(50, (error, options) => {
            captured.push({ error, options });
        });
        breadcrumbs.install();

        console.error('something broke', 42, new Error('x'));

        expect(captured).toHaveLength(1);
        expect(captured[0].options.extra.consoleMessage).toBe('something broke 42');
    });
});
