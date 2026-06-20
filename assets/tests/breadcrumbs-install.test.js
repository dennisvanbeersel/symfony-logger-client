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

    // RML-01: a second collector (SPA re-init / Turbo full reinit) must NOT
    // stack a second fetch/console wrapper. Without the global sentinel each
    // re-wrap captures the previous wrapper as "original" and pins the old
    // instance in memory unbounded.
    describe('global wrapper idempotency + unwrap (RML-01)', () => {
        test('a second collector does not re-wrap fetch or console', () => {
            window.fetch = async () => ({ ok: true, status: 200 });

            const first = new BreadcrumbCollector(50);
            first.install();

            const wrappedFetch = window.fetch;
            const wrappedConsoleError = console.error;
            expect(wrappedFetch._appLoggerFetchWrapped).toBe(true);
            expect(console.error._appLoggerConsoleWrapped).toBe(true);

            // Re-instantiate + install (simulating an SDK re-init).
            const second = new BreadcrumbCollector(50);
            second.install();

            // Same wrapper references — no second layer stacked.
            expect(window.fetch).toBe(wrappedFetch);
            expect(console.error).toBe(wrappedConsoleError);
        });

        test('uninstall restores the original fetch, console and history methods', () => {
            const pristineFetch = async () => ({ ok: true, status: 200 });
            window.fetch = pristineFetch;
            const pristineConsoleError = console.error;
            const pristinePushState = history.pushState;

            const breadcrumbs = new BreadcrumbCollector(50);
            breadcrumbs.install();

            // Confirm they were wrapped.
            expect(window.fetch).not.toBe(pristineFetch);
            expect(console.error).not.toBe(pristineConsoleError);
            expect(history.pushState).not.toBe(pristinePushState);

            breadcrumbs.uninstall();

            // Restored to the pristine originals; sentinels gone.
            expect(window.fetch).toBe(pristineFetch);
            expect(console.error).toBe(pristineConsoleError);
            expect(history.pushState).toBe(pristinePushState);
            expect(window.fetch._appLoggerFetchWrapped).toBeUndefined();
            expect(console.error._appLoggerConsoleWrapped).toBeUndefined();
            expect(breadcrumbs.installed).toBe(false);

            // After uninstall a fresh install() re-arms cleanly.
            breadcrumbs.install();
            expect(window.fetch._appLoggerFetchWrapped).toBe(true);
            expect(console.error._appLoggerConsoleWrapped).toBe(true);
        });

        test('uninstall is idempotent and never throws', () => {
            const breadcrumbs = new BreadcrumbCollector(50);
            breadcrumbs.install();
            expect(() => {
                breadcrumbs.uninstall();
                breadcrumbs.uninstall();
            }).not.toThrow();
        });

        // JS-MULTI-01: the console/fetch wrappers now carry an OWNER stamp. When
        // two SDK instances coexist, the FIRST owns the live wrapper (the second
        // install() bails). The non-owner's uninstall() must NOT restore the
        // original — otherwise it strips capture from the still-active owner.
        test('a non-owner uninstall does not unwrap fetch/console owned by another instance', () => {
            window.fetch = async () => ({ ok: true, status: 200 });

            const first = new BreadcrumbCollector(50);
            first.install();
            const ownedFetch = window.fetch;
            const ownedConsoleError = console.error;
            expect(ownedFetch._appLoggerFetchOwner).toBe(first);
            expect(ownedConsoleError._appLoggerConsoleOwner).toBe(first);

            // Second instance installs (bails: wrappers already present) then
            // tears itself down. It does not own the wrappers, so they must stay.
            const second = new BreadcrumbCollector(50);
            second.install();
            second.uninstall();

            expect(window.fetch).toBe(ownedFetch);
            expect(console.error).toBe(ownedConsoleError);
            expect(window.fetch._appLoggerFetchWrapped).toBe(true);
            expect(console.error._appLoggerConsoleWrapped).toBe(true);

            // The owner can still tear down cleanly.
            first.uninstall();
            expect(window.fetch._appLoggerFetchWrapped).toBeUndefined();
            expect(console.error._appLoggerConsoleWrapped).toBeUndefined();
        });
    });

    // JS-3: uninstall() restored the monkeypatches but never removed the
    // capture-phase document 'click' listener. So uninstall()->install() stacked
    // a duplicate handler and a click recorded TWO breadcrumbs (and pinned the
    // stale instance). These tests prove the click listener is now removed.
    describe('uninstall removes the capture-phase click listener (JS-3)', () => {
        test('a click after uninstall records no breadcrumb', () => {
            const breadcrumbs = new BreadcrumbCollector(50);
            breadcrumbs.install();
            breadcrumbs.uninstall();

            const button = document.createElement('button');
            button.id = 'after-uninstall';
            document.body.appendChild(button);

            button.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

            expect(breadcrumbs.get().find(c => c.category === 'click')).toBeUndefined();

            document.body.removeChild(button);
        });

        test('install -> uninstall -> install records exactly ONE click breadcrumb', () => {
            const breadcrumbs = new BreadcrumbCollector(50);
            breadcrumbs.install();
            breadcrumbs.uninstall();
            breadcrumbs.install();

            const button = document.createElement('button');
            button.id = 're-armed';
            document.body.appendChild(button);

            button.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));

            const clicks = breadcrumbs.get().filter(c => c.category === 'click');
            expect(clicks).toHaveLength(1);
            expect(clicks[0].message).toContain('#re-armed');

            document.body.removeChild(button);
        });
    });
});
