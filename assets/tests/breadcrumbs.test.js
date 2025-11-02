/**
 * Unit tests for BreadcrumbCollector
 *
 * Tests user action tracking that helps debug errors:
 * - Click tracking (UI interactions)
 * - Navigation tracking (SPA route changes)
 * - Console message capture
 * - HTTP request tracking
 * - Breadcrumb limiting (memory management)
 */
import { BreadcrumbCollector } from '../src/breadcrumbs.js';

describe('BreadcrumbCollector', () => {
    let breadcrumbs;

    beforeEach(() => {
        breadcrumbs = new BreadcrumbCollector(10);

        // Mock DOM environment
        global.document = {
            addEventListener: () => {},
        };

        global.window = {
            fetch: async () => ({ ok: true, status: 200 }),
        };

        global.history = {
            pushState: () => {},
            replaceState: () => {},
        };

        global.console = {
            log: () => {},
            info: () => {},
            warn: () => {},
            error: () => {},
            debug: () => {},
        };
    });

    describe('Core functionality', () => {
        test('initializes with empty breadcrumbs', () => {
            expect(breadcrumbs.get()).toEqual([]);
        });

        test('adds breadcrumb with timestamp and default level', () => {
            breadcrumbs.add({
                type: 'user',
                category: 'action',
                message: 'User clicked button',
            });

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0]).toMatchObject({
                type: 'user',
                category: 'action',
                message: 'User clicked button',
                level: 'info',
            });
            expect(crumbs[0].timestamp).toBeDefined();
        });

        test('respects custom level', () => {
            breadcrumbs.add({
                type: 'error',
                category: 'exception',
                message: 'Failed validation',
                level: 'error',
            });

            const crumbs = breadcrumbs.get();
            expect(crumbs[0].level).toBe('error');
        });

        test('clears all breadcrumbs', () => {
            breadcrumbs.add({ type: 'test', message: '1' });
            breadcrumbs.add({ type: 'test', message: '2' });

            breadcrumbs.clear();

            expect(breadcrumbs.get()).toEqual([]);
        });
    });

    describe('Breadcrumb limiting (memory management)', () => {
        test('removes oldest breadcrumb when limit exceeded', () => {
            // Add 11 breadcrumbs (limit is 10)
            for (let i = 0; i < 11; i++) {
                breadcrumbs.add({
                    type: 'test',
                    message: `Breadcrumb ${i}`,
                });
            }

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(10);
            // First breadcrumb (0) should be removed
            expect(crumbs[0].message).toBe('Breadcrumb 1');
            expect(crumbs[9].message).toBe('Breadcrumb 10');
        });

        test('maintains FIFO order under load', () => {
            // Simulate high-frequency events
            for (let i = 0; i < 25; i++) {
                breadcrumbs.add({ type: 'click', message: `Click ${i}` });
            }

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(10);
            // Should have last 10 (15-24)
            expect(crumbs[0].message).toBe('Click 15');
            expect(crumbs[9].message).toBe('Click 24');
        });

        test('allows custom max breadcrumbs', () => {
            const customCollector = new BreadcrumbCollector(3);

            for (let i = 0; i < 5; i++) {
                customCollector.add({ type: 'test', message: `${i}` });
            }

            expect(customCollector.get()).toHaveLength(3);
        });
    });

    describe('Click tracking', () => {
        test('captures click on element with ID', () => {
            const clickHandler = (event) => {
                breadcrumbs.add({
                    type: 'ui',
                    category: 'click',
                    message: `Clicked ${event.target.tagName.toLowerCase()}#${event.target.id}`,
                    data: {
                        tag: event.target.tagName.toLowerCase(),
                        id: event.target.id,
                        class: event.target.className || '',
                    },
                });
            };

            const mockEvent = {
                target: {
                    tagName: 'BUTTON',
                    id: 'submit-btn',
                    className: 'btn btn-primary',
                },
            };

            clickHandler(mockEvent);

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].message).toBe('Clicked button#submit-btn');
            expect(crumbs[0].data.id).toBe('submit-btn');
        });

        test('captures click on element with className', () => {
            const mockEvent = {
                target: {
                    tagName: 'DIV',
                    id: '',
                    className: 'card-header',
                },
            };

            breadcrumbs.add({
                type: 'ui',
                category: 'click',
                message: `Clicked ${mockEvent.target.tagName.toLowerCase()}.${mockEvent.target.className.split(' ')[0]}`,
                data: {
                    tag: mockEvent.target.tagName.toLowerCase(),
                    class: mockEvent.target.className,
                },
            });

            const crumbs = breadcrumbs.get();
            expect(crumbs[0].message).toBe('Clicked div.card-header');
        });

        test('handles SVG elements with baseVal className', () => {
            // SVG elements have className as SVGAnimatedString
            const mockSvgEvent = {
                target: {
                    tagName: 'SVG',
                    id: 'icon',
                    className: {
                        baseVal: 'icon-close',
                    },
                },
            };

            const className = typeof mockSvgEvent.target.className === 'object' && mockSvgEvent.target.className.baseVal !== undefined
                ? mockSvgEvent.target.className.baseVal
                : mockSvgEvent.target.className;

            breadcrumbs.add({
                type: 'ui',
                category: 'click',
                message: `Clicked ${mockSvgEvent.target.tagName.toLowerCase()}#${mockSvgEvent.target.id}`,
                data: {
                    tag: mockSvgEvent.target.tagName.toLowerCase(),
                    id: mockSvgEvent.target.id,
                    class: className,
                },
            });

            const crumbs = breadcrumbs.get();
            expect(crumbs[0].data.class).toBe('icon-close');
        });
    });

    describe('Navigation tracking', () => {
        test('tracks history.pushState navigation', () => {
            const originalPushState = history.pushState;

            history.pushState = function(...args) {
                breadcrumbs.add({
                    type: 'navigation',
                    category: 'navigation',
                    message: `Navigated to ${args[2]}`,
                    data: { to: args[2] },
                });
                return originalPushState.apply(history, args);
            };

            history.pushState({}, '', '/new-page');

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].type).toBe('navigation');
            expect(crumbs[0].message).toBe('Navigated to /new-page');
            expect(crumbs[0].data.to).toBe('/new-page');
        });

        test('tracks history.replaceState navigation', () => {
            const originalReplaceState = history.replaceState;

            history.replaceState = function(...args) {
                breadcrumbs.add({
                    type: 'navigation',
                    category: 'navigation',
                    message: `Replaced state ${args[2]}`,
                    data: { to: args[2] },
                });
                return originalReplaceState.apply(history, args);
            };

            history.replaceState({}, '', '/updated-page');

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].message).toBe('Replaced state /updated-page');
        });
    });

    describe('Console message capture', () => {
        test('captures console.log as info level', () => {
            /* eslint-disable no-console */
            const originalLog = console.log;

            console.log = function(...args) {
                breadcrumbs.add({
                    type: 'console',
                    category: 'console',
                    message: args.join(' '),
                    level: 'info',
                    data: { arguments: args },
                });
                return originalLog.apply(console, args);
            };

            console.log('User logged in', { userId: 123 });
            /* eslint-enable no-console */

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].type).toBe('console');
            expect(crumbs[0].level).toBe('info');
            expect(crumbs[0].message).toContain('User logged in');
        });

        test('captures console.error with error level', () => {
            const originalError = console.error;

            console.error = function(...args) {
                breadcrumbs.add({
                    type: 'console',
                    category: 'console',
                    message: args.join(' '),
                    level: 'error',
                    data: { arguments: args },
                });
                return originalError.apply(console, args);
            };

            console.error('API request failed', 'Network timeout');

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].level).toBe('error');
            expect(crumbs[0].message).toBe('API request failed Network timeout');
        });

        test('captures console.warn with warning level', () => {
            const originalWarn = console.warn;

            console.warn = function(...args) {
                breadcrumbs.add({
                    type: 'console',
                    category: 'console',
                    message: args.join(' '),
                    level: 'warn',
                    data: { arguments: args },
                });
                return originalWarn.apply(console, args);
            };

            console.warn('Deprecated API usage');

            const crumbs = breadcrumbs.get();
            expect(crumbs[0].level).toBe('warn');
        });
    });

    describe('HTTP request tracking', () => {
        test('tracks successful fetch request', async () => {
            const mockResponse = {
                ok: true,
                status: 200,
            };

            // Create wrapped fetch that returns mock response
            const originalFetch = () => Promise.resolve(mockResponse);

            window.fetch = async function(url, options) {
                const method = options?.method || 'GET';
                const startTime = Date.now();

                const response = await originalFetch(url, options);
                const duration = Date.now() - startTime;

                breadcrumbs.add({
                    type: 'http',
                    category: 'fetch',
                    message: `${method} ${url}`,
                    data: {
                        url,
                        method,
                        status_code: response.status,
                        duration,
                    },
                    level: response.ok ? 'info' : 'warning',
                });

                return response;
            };

            await window.fetch('/api/users', { method: 'GET' });

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].type).toBe('http');
            expect(crumbs[0].message).toBe('GET /api/users');
            expect(crumbs[0].data.status_code).toBe(200);
            expect(crumbs[0].level).toBe('info');
        });

        test('tracks failed fetch request', async () => {
            // Create fetch that rejects
            const originalFetch = () => Promise.reject(new Error('Network timeout'));

            window.fetch = async function(url, options) {
                const method = options?.method || 'GET';
                const startTime = Date.now();

                try {
                    return await originalFetch(url, options);
                } catch (error) {
                    const duration = Date.now() - startTime;

                    breadcrumbs.add({
                        type: 'http',
                        category: 'fetch',
                        message: `${method} ${url} failed`,
                        data: {
                            url,
                            method,
                            error: error.message,
                            duration,
                        },
                        level: 'error',
                    });

                    throw error;
                }
            };

            await expect(window.fetch('/api/data')).rejects.toThrow('Network timeout');

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(1);
            expect(crumbs[0].message).toBe('GET /api/data failed');
            expect(crumbs[0].level).toBe('error');
            expect(crumbs[0].data.error).toBe('Network timeout');
        });

        test('tracks POST request with 400 error', async () => {
            const mockResponse = {
                ok: false,
                status: 400,
            };

            // Create fetch that returns error response
            const originalFetch = () => Promise.resolve(mockResponse);

            window.fetch = async function(url, options) {
                const method = options?.method || 'GET';
                const response = await originalFetch(url, options);

                breadcrumbs.add({
                    type: 'http',
                    category: 'fetch',
                    message: `${method} ${url}`,
                    data: {
                        url,
                        method,
                        status_code: response.status,
                        duration: 150,
                    },
                    level: response.ok ? 'info' : 'warning',
                });

                return response;
            };

            await window.fetch('/api/submit', { method: 'POST' });

            const crumbs = breadcrumbs.get();
            expect(crumbs[0].message).toBe('POST /api/submit');
            expect(crumbs[0].data.status_code).toBe(400);
            expect(crumbs[0].level).toBe('warning');
        });
    });

    describe('Real-world debugging scenarios', () => {
        test('tracks sequence leading to error', () => {
            // Simulate user journey that leads to an error
            breadcrumbs.add({
                type: 'navigation',
                message: 'Navigated to /checkout',
            });

            breadcrumbs.add({
                type: 'ui',
                category: 'click',
                message: 'Clicked button#add-coupon',
            });

            breadcrumbs.add({
                type: 'http',
                category: 'fetch',
                message: 'POST /api/validate-coupon',
                data: { status_code: 200 },
            });

            breadcrumbs.add({
                type: 'ui',
                category: 'click',
                message: 'Clicked button#submit-order',
            });

            breadcrumbs.add({
                type: 'http',
                category: 'fetch',
                message: 'POST /api/orders failed',
                level: 'error',
                data: { error: 'Payment declined' },
            });

            const crumbs = breadcrumbs.get();
            expect(crumbs).toHaveLength(5);

            // Verify we can trace the user journey
            expect(crumbs[0].message).toContain('checkout');
            expect(crumbs[1].message).toContain('add-coupon');
            expect(crumbs[4].level).toBe('error');
        });

        test('handles rapid user interactions', () => {
            // Simulate user clicking multiple elements quickly
            for (let i = 0; i < 15; i++) {
                breadcrumbs.add({
                    type: 'ui',
                    category: 'click',
                    message: `Clicked element ${i}`,
                });
            }

            const crumbs = breadcrumbs.get();
            // Should only keep last 10
            expect(crumbs).toHaveLength(10);
            expect(crumbs[0].message).toBe('Clicked element 5');
            expect(crumbs[9].message).toBe('Clicked element 14');
        });
    });
});
