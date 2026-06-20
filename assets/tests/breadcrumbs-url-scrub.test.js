/**
 * Regression tests for SEC-01 / JS-10 (credential leak in breadcrumbs).
 *
 * wrapFetch() and wrapConsole() used to copy raw request URLs / console args
 * into the breadcrumb `message` and `data` verbatim. Because transport's
 * scrubObject only URL-scrubs payload keys in URL_VALUE_KEYS (e.g. `url`), a
 * URL embedded in a breadcrumb `message` or `data.arguments` shipped its query
 * secrets (?token=...&password=...) unredacted. These tests prove the SDK now
 * redacts sensitive query VALUES at breadcrumb-composition time.
 */
import { BreadcrumbCollector } from '../src/breadcrumbs.js';

describe('BreadcrumbCollector URL credential scrubbing (SEC-01/JS-10)', () => {
    let originalFetch;
    let originalConsole;
    let originalPushState;
    let originalReplaceState;

    beforeEach(() => {
        originalFetch = window.fetch;
        originalConsole = {
            log: console.log,
            info: console.info,
            warn: console.warn,
            error: console.error,
            debug: console.debug,
        };
        originalPushState = history.pushState;
        originalReplaceState = history.replaceState;
    });

    afterEach(() => {
        window.fetch = originalFetch;
        console.log = originalConsole.log;
        console.info = originalConsole.info;
        console.warn = originalConsole.warn;
        console.error = originalConsole.error;
        console.debug = originalConsole.debug;
        history.pushState = originalPushState;
        history.replaceState = originalReplaceState;
    });

    test('fetch breadcrumb redacts ?token=...&password=... in message and data.url', async () => {
        window.fetch = async () => ({ ok: true, status: 200 });

        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        await window.fetch('https://api.example.com/login?token=abc123&password=hunter2&page=2', {
            method: 'GET',
        });

        const httpCrumb = breadcrumbs.get().find(c => c.category === 'fetch');
        expect(httpCrumb).toBeDefined();

        // Secrets must NOT appear anywhere in the breadcrumb.
        expect(httpCrumb.message).not.toContain('abc123');
        expect(httpCrumb.message).not.toContain('hunter2');
        expect(httpCrumb.data.url).not.toContain('abc123');
        expect(httpCrumb.data.url).not.toContain('hunter2');

        // Sensitive values are redacted...
        expect(httpCrumb.message).toContain('token=[REDACTED]');
        expect(httpCrumb.message).toContain('password=[REDACTED]');

        // ...while non-sensitive structure is preserved.
        expect(httpCrumb.message).toContain('https://api.example.com/login');
        expect(httpCrumb.message).toContain('page=2');
    });

    test('failed fetch breadcrumb also redacts secrets in the message', async () => {
        window.fetch = async () => {
            throw new Error('Network failure');
        };

        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        await expect(
            window.fetch('https://api.example.com/x?api_key=SECRETKEY', { method: 'POST' }),
        ).rejects.toThrow('Network failure');

        const httpCrumb = breadcrumbs.get().find(c => c.category === 'fetch');
        expect(httpCrumb.message).not.toContain('SECRETKEY');
        expect(httpCrumb.message).toContain('api_key=[REDACTED]');
    });

    test('console breadcrumb redacts a URL string argument carrying a secret', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        console.log('redirecting to', 'https://app.example.com/cb?token=topsecret&ref=home');

        const crumb = breadcrumbs.get().find(c => c.category === 'console');
        expect(crumb).toBeDefined();
        expect(crumb.message).not.toContain('topsecret');
        expect(crumb.message).toContain('token=[REDACTED]');
        expect(crumb.message).toContain('ref=home');
        expect(crumb.data.arguments.some(a => a.includes('topsecret'))).toBe(false);
    });

    test('console breadcrumb redacts a secret inside a serialized object argument', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        console.warn('request', { url: '/api/v1/items?token=abc&size=10' });

        const crumb = breadcrumbs.get().find(c => c.category === 'console');
        expect(crumb).toBeDefined();
        expect(crumb.message).not.toContain('=abc');
        expect(crumb.message).toContain('token=[REDACTED]');
        expect(crumb.message).toContain('size=10');
    });

    test('pushState navigation breadcrumb redacts ?token=secret in message and data.to', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        history.pushState({}, '', '/dashboard?token=secret&tab=overview');

        const navCrumb = breadcrumbs.get().find(c => c.category === 'navigation');
        expect(navCrumb).toBeDefined();
        expect(navCrumb.message).not.toContain('secret');
        expect(navCrumb.data.to).not.toContain('secret');
        expect(navCrumb.message).toContain('token=[REDACTED]');
        expect(navCrumb.data.to).toContain('token=[REDACTED]');
        // Non-sensitive structure preserved.
        expect(navCrumb.message).toContain('/dashboard');
        expect(navCrumb.message).toContain('tab=overview');
    });

    test('replaceState navigation breadcrumb redacts ?api_key=secret in message and data.to', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        history.replaceState({}, '', '/profile?api_key=topsecret&view=edit');

        const navCrumb = breadcrumbs.get().find(c => c.category === 'navigation');
        expect(navCrumb).toBeDefined();
        expect(navCrumb.message).not.toContain('topsecret');
        expect(navCrumb.data.to).not.toContain('topsecret');
        expect(navCrumb.message).toContain('api_key=[REDACTED]');
        expect(navCrumb.data.to).toContain('api_key=[REDACTED]');
        expect(navCrumb.message).toContain('/profile');
        expect(navCrumb.message).toContain('view=edit');
    });

    test('non-URL console strings pass through unchanged', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        console.log('plain message with no url', 42);

        const crumb = breadcrumbs.get().find(c => c.category === 'console');
        expect(crumb.message).toContain('plain message with no url');
        expect(crumb.message).toContain('42');
    });
});
