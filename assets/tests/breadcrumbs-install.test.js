/**
 * Integration-style tests for BreadcrumbCollector.install()
 *
 * Unlike breadcrumbs.test.js, these tests DO NOT stub document/window/history.
 * They run against the real jsdom environment so install() registers real
 * event handlers / wraps the real console + fetch + history APIs, exercising
 * the click, navigation, console and fetch breadcrumb paths end to end.
 */
import { BreadcrumbCollector } from '../src/breadcrumbs.js';

describe('BreadcrumbCollector.install() (real jsdom)', () => {
    let originalFetch;
    let originalConsole;
    let originalPushState;
    let originalReplaceState;

    beforeEach(() => {
        // Preserve real implementations to restore afterward (install() wraps them)
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

    test('install is idempotent', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();
        expect(breadcrumbs.installed).toBe(true);

        // Second install must be a no-op (does not re-wrap)
        const wrappedPushState = history.pushState;
        breadcrumbs.install();
        expect(history.pushState).toBe(wrappedPushState);
    });

    test('registers a real click handler that records a breadcrumb', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        const button = document.createElement('button');
        button.id = 'submit-btn';
        button.className = 'btn primary';
        document.body.appendChild(button);

        button.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        const crumbs = breadcrumbs.get();
        const click = crumbs.find(c => c.category === 'click');
        expect(click).toBeDefined();
        expect(click.message).toContain('button');
        expect(click.message).toContain('#submit-btn');
        expect(click.data.id).toBe('submit-btn');

        document.body.removeChild(button);
    });

    test('click handler falls back to first class when element has no id', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        const div = document.createElement('div');
        div.className = 'card highlighted';
        document.body.appendChild(div);

        div.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

        const click = breadcrumbs.get().find(c => c.category === 'click');
        expect(click.message).toContain('.card');

        document.body.removeChild(div);
    });

    test('wraps history.pushState / replaceState to record navigation breadcrumbs', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        history.pushState({}, '', '/dashboard');
        history.replaceState({}, '', '/settings');

        const crumbs = breadcrumbs.get();
        const navs = crumbs.filter(c => c.type === 'navigation');
        expect(navs.length).toBeGreaterThanOrEqual(2);
        expect(navs.some(n => n.message.includes('/dashboard'))).toBe(true);
        expect(navs.some(n => n.message.includes('/settings'))).toBe(true);
    });

    test('wrapped console.error with an Error triggers the errorCaptureCallback (zero-config)', () => {
        const captured = [];
        const breadcrumbs = new BreadcrumbCollector(50, (error, options) => {
            captured.push({ error, options });
        });
        breadcrumbs.install();

        const err = new Error('boom');
        console.error('Something failed:', err);

        expect(captured).toHaveLength(1);
        expect(captured[0].error).toBe(err);
        expect(captured[0].options.extra.consoleError).toBe(true);
        expect(captured[0].options.extra.consoleMessage).toContain('Something failed:');

        // A console breadcrumb is also recorded
        const consoleCrumb = breadcrumbs.get().find(c => c.category === 'console');
        expect(consoleCrumb).toBeDefined();
        expect(consoleCrumb.level).toBe('error');
    });

    test('wrapped console.error without an Error does not trigger capture', () => {
        const captured = [];
        const breadcrumbs = new BreadcrumbCollector(50, (error) => {
            captured.push(error);
        });
        breadcrumbs.install();

        console.error('just a string', { plain: 'object' });

        expect(captured).toHaveLength(0);
        const consoleCrumb = breadcrumbs.get().find(c => c.category === 'console');
        expect(consoleCrumb).toBeDefined();
    });

    test('wrapped console.log records an info-level breadcrumb', () => {
        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        console.log('hello', 123, null, undefined);

        const crumb = breadcrumbs.get().find(c => c.category === 'console');
        expect(crumb).toBeDefined();
        expect(crumb.level).toBe('info'); // 'log' is normalized to 'info'
        expect(crumb.message).toContain('hello');
    });

    test('wrapped fetch records a successful HTTP breadcrumb', async () => {
        // jsdom has no fetch; provide a stub so install() can wrap it
        window.fetch = async () => ({ ok: true, status: 200 });

        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        const response = await window.fetch('https://api.example.com/data', { method: 'GET' });
        expect(response.status).toBe(200);

        const httpCrumb = breadcrumbs.get().find(c => c.category === 'fetch');
        expect(httpCrumb).toBeDefined();
        expect(httpCrumb.message).toBe('GET https://api.example.com/data');
        expect(httpCrumb.data.status_code).toBe(200);
        expect(httpCrumb.level).toBe('info');
    });

    test('wrapped fetch records a failed HTTP breadcrumb and rethrows', async () => {
        window.fetch = async () => {
            throw new Error('Network failure');
        };

        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        await expect(
            window.fetch('https://api.example.com/fail', { method: 'POST' }),
        ).rejects.toThrow('Network failure');

        const httpCrumb = breadcrumbs.get().find(c => c.category === 'fetch');
        expect(httpCrumb).toBeDefined();
        expect(httpCrumb.message).toContain('failed');
        expect(httpCrumb.level).toBe('error');
        expect(httpCrumb.data.error).toBe('Network failure');
    });

    test('wrapped fetch records a non-ok response as a warning', async () => {
        window.fetch = async () => ({ ok: false, status: 404 });

        const breadcrumbs = new BreadcrumbCollector(50);
        breadcrumbs.install();

        await window.fetch('https://api.example.com/missing');

        const httpCrumb = breadcrumbs.get().find(c => c.category === 'fetch');
        expect(httpCrumb.data.status_code).toBe(404);
        expect(httpCrumb.level).toBe('warning');
    });
});
