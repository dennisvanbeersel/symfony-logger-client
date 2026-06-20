/**
 * Breadcrumb collector for tracking user actions
 *
 * ZERO-CONFIG ERROR CAPTURE:
 * When console.error() is called with an Error object, automatically
 * captures it and sends to API. This provides zero-config tracking for
 * the common pattern: .catch(err => console.error('Failed:', err))
 */
import { scrubUrlQueryValues } from './scrub-fields.js';

export class BreadcrumbCollector {
    constructor(maxBreadcrumbs = 50, errorCaptureCallback = null) {
        this.breadcrumbs = [];
        this.maxBreadcrumbs = maxBreadcrumbs;
        this.installed = false; // Track installation state
        this.errorCaptureCallback = errorCaptureCallback; // Callback to capture errors automatically

        // Bound capture-phase click handler, stored so uninstall() can
        // removeEventListener it (matching the capture flag). An anonymous
        // handler would be unremovable, so uninstall()->install() would stack a
        // second document 'click' listener and pin the stale instance (JS-3).
        this.boundClickHandler = (event) => this.handleClick(event);
    }

    /**
   * Record a click breadcrumb (capture-phase document handler).
   * @param {Event} event - DOM click event
   */
    handleClick(event) {
        const target = event.target;
        const tagName = target.tagName.toLowerCase();
        let message = `Clicked ${tagName}`;

        const className = this.getClassName(target);

        if (target.id) {
            message += `#${target.id}`;
        } else if (className) {
            const firstClass = className.split(' ')[0];
            if (firstClass) {
                message += `.${firstClass}`;
            }
        }

        this.add({
            type: 'ui',
            category: 'click',
            message,
            data: {
                tag: tagName,
                id: target.id,
                class: className,
            },
        });
    }

    /**
   * Get className as string (handles both HTML and SVG elements)
   * @param {Element} element - DOM element
   * @returns {string} - className as string
   */
    getClassName(element) {
        if (!element.className) return '';
        // For SVG elements, className is an SVGAnimatedString
        if (typeof element.className === 'object' && element.className.baseVal !== undefined) {
            return element.className.baseVal;
        }
        // For HTML elements, className is a string
        return element.className;
    }

    /**
   * Install automatic breadcrumb tracking (idempotent)
   */
    install() {
        // Guard against multiple installations
        if (this.installed) {
            return;
        }

        this.installed = true;

        // Track clicks (capture phase). Uses the stored bound handler so
        // uninstall() can remove it with the matching capture flag (JS-3).
        document.addEventListener('click', this.boundClickHandler, true);

        // Track navigation. Guard against double-wrapping by THIS module: a
        // re-init / HMR cycle must not stack a second breadcrumb wrapper on top
        // of our own (JS-2). A per-module sentinel is used (not a shared one) so
        // SessionManager's distinct navigation wrapper can still layer over ours
        // without either module losing its behavior. The sentinel also carries
        // the original ref so it can be unwrapped on teardown.
        if (!history.pushState._appLoggerBreadcrumbWrapped) {
            const originalPushState = history.pushState;
            this._originalPushState = originalPushState;

            const wrappedPushState = (...args) => {
                // GDPR: the destination URL (args[2]) can carry secrets/tokens in its
                // query string (?token=...). Scrub VALUES before they land in the
                // breadcrumb message/data — an unscrubbed URL here ships verbatim in
                // the breadcrumb trail (mirrors the fetch/console fixes, SEC-JS-01).
                const to = scrubUrlQueryValues(String(args[2] ?? ''));
                this.add({
                    type: 'navigation',
                    category: 'navigation',
                    message: `Navigated to ${to}`,
                    data: { to },
                });
                return originalPushState.apply(history, args);
            };
            wrappedPushState._appLoggerBreadcrumbWrapped = true;
            wrappedPushState._appLoggerOriginal = originalPushState;
            history.pushState = wrappedPushState;
        }

        if (!history.replaceState._appLoggerBreadcrumbWrapped) {
            const originalReplaceState = history.replaceState;
            this._originalReplaceState = originalReplaceState;

            const wrappedReplaceState = (...args) => {
                // GDPR: scrub sensitive query VALUES from the destination URL before
                // it enters the breadcrumb trail (see pushState note, SEC-JS-01).
                const to = scrubUrlQueryValues(String(args[2] ?? ''));
                this.add({
                    type: 'navigation',
                    category: 'navigation',
                    message: `Replaced state ${to}`,
                    data: { to },
                });
                return originalReplaceState.apply(history, args);
            };
            wrappedReplaceState._appLoggerBreadcrumbWrapped = true;
            wrappedReplaceState._appLoggerOriginal = originalReplaceState;
            history.replaceState = wrappedReplaceState;
        }

        // Track console messages
        this.wrapConsole();

        // Track fetch requests
        this.wrapFetch();
    }

    /**
   * Add a breadcrumb
   */
    add(breadcrumb) {
        this.breadcrumbs.push({
            timestamp: new Date().toISOString(),
            level: breadcrumb.level || 'info',
            ...breadcrumb,
        });

        // Limit breadcrumbs
        if (this.breadcrumbs.length > this.maxBreadcrumbs) {
            this.breadcrumbs.shift();
        }
    }

    /**
   * Get all breadcrumbs
   */
    get() {
        return this.breadcrumbs;
    }

    /**
   * Clear breadcrumbs
   */
    clear() {
        this.breadcrumbs = [];
    }

    /**
   * Wrap console methods for breadcrumb tracking
   *
   * CRITICAL: Original console method is called FIRST to ensure console works
   * even if breadcrumb tracking fails. Breadcrumb logic is wrapped in try-catch
   * to prevent any failures from breaking console functionality.
   *
   * ZERO-CONFIG ERROR CAPTURE:
   * When console.error() is called with an Error object, automatically captures
   * it via errorCaptureCallback. This enables zero-config error tracking for the
   * common pattern: .catch(err => console.error('message', err))
   */
    wrapConsole() {
        const levels = ['log', 'info', 'warn', 'error', 'debug'];

        levels.forEach(level => {
            // eslint-disable-next-line no-console
            const original = console[level];

            // Safety check - ensure original is a function
            if (typeof original !== 'function') {
                return; // Skip this level if not a function
            }

            // Idempotency: a re-init / HMR / Turbo full-reinit cycle must not
            // stack a second wrapper on top of ours. Without a global sentinel
            // each new BreadcrumbCollector re-wraps console[level], capturing the
            // PREVIOUS wrapper as "original" and pinning the old instance in
            // memory forever (RML-01). Bail if already wrapped by this module.
            if (original._appLoggerConsoleWrapped) {
                return;
            }

            const wrapped = (...args) => {
                // ZERO-CONFIG ERROR CAPTURE (BEFORE console output)
                // Must happen BEFORE original.apply to avoid recursion issues
                if (level === 'error' && this.errorCaptureCallback) {
                    try {
                        // Look for Error objects in arguments
                        const errorObj = args.find(arg => arg instanceof Error);
                        if (errorObj) {
                            // Automatically capture this error
                            if (typeof this.errorCaptureCallback === 'function') {
                                this.errorCaptureCallback(errorObj, {
                                    extra: {
                                        consoleError: true,
                                        // Redact sensitive URL query VALUES (?token=...) from
                                        // each non-Error arg before it lands in the error
                                        // payload's extra.consoleMessage. transport.scrubObject
                                        // only URL-scrubs known URL keys, and 'consoleMessage'
                                        // isn't one — so an unscrubbed URL string here would
                                        // ship verbatim (SEC-JS-01). Mirrors the breadcrumb fix.
                                        consoleMessage: args.filter(arg => !(arg instanceof Error))
                                            .map(arg => scrubUrlQueryValues(String(arg)))
                                            .join(' '),
                                    },
                                });
                            }
                        }
                    } catch (captureError) {
                        // Log the actual error instead of silently failing
                        // Use native console to avoid recursion
                        if (typeof original === 'function') {
                            try {
                                original.call(console, 'ApplicationLogger: Failed to auto-capture error:', captureError);
                            } catch {
                                // Absolute last resort - do nothing
                            }
                        }
                    }
                }

                let result;

                // Call original console method
                try {
                    result = original.apply(console, args);
                } catch {
                    // Native console threw (very rare) - fail silently
                    // Don't rethrow - would break all console calls
                }

                // Then try to add breadcrumb (wrapped in try-catch)
                try {
                    // Safely serialize arguments to prevent:
                    // - toString() errors
                    // - Circular reference errors
                    // - Non-serializable objects (DOM nodes, functions)
                    const safeArgs = args.map(arg => {
                        if (arg === null) return 'null';
                        if (arg === undefined) return 'undefined';

                        // Special handling for Error objects
                        if (arg instanceof Error) {
                            return `${arg.name}: ${arg.message}`;
                        }

                        // Handle objects (try JSON serialization, fallback to string)
                        if (typeof arg === 'object') {
                            try {
                                // Redact sensitive URL query VALUES embedded in the
                                // serialized object (e.g. {url: '/api?token=abc'}).
                                return scrubUrlQueryValues(JSON.stringify(arg));
                            } catch {
                                // Circular reference or non-serializable
                                return Object.prototype.toString.call(arg);
                            }
                        }

                        // Primitives: a string arg may itself be a URL carrying a
                        // secret query value (?token=...), so scrub it too. Mirrors
                        // the fetch breadcrumb fix (SEC-01/JS-10).
                        return scrubUrlQueryValues(String(arg));
                    });

                    this.add({
                        type: 'console',
                        category: 'console',
                        message: safeArgs.join(' '),
                        level: level === 'log' ? 'info' : level,
                        data: { arguments: safeArgs },
                    });
                } catch {
                    // Never crash breadcrumb tracking
                    // Don't use console.error here to avoid infinite recursion
                    // Silently fail - breadcrumb loss is better than breaking console
                }

                return result;
            };

            // Stamp the wrapper with a global sentinel + the original ref so a
            // future install() bails (idempotency) and teardown() can unwrap.
            // JS-MULTI-01: also tag the wrapper with THIS instance as owner so a
            // second SDK's uninstall() cannot restore the original and silently
            // leave the still-active first instance without console capture
            // (mirrors the session-manager history-wrapper owner fix, JS-4).
            wrapped._appLoggerConsoleWrapped = true;
            wrapped._appLoggerOriginal = original;
            wrapped._appLoggerConsoleOwner = this;
            // eslint-disable-next-line no-console
            console[level] = wrapped;
        });
    }

    /**
   * Wrap fetch for HTTP request breadcrumbs
   */
    wrapFetch() {
        const originalFetch = window.fetch;

        // Idempotency: like console, an un-guarded re-wrap stacks wrappers and
        // pins old SDK instances in memory (RML-01). A global sentinel ensures
        // we wrap window.fetch at most once across re-init cycles.
        if (typeof originalFetch !== 'function' || originalFetch._appLoggerFetchWrapped) {
            return;
        }

        const wrappedFetch = async (...args) => {
            const rawUrl = typeof args[0] === 'string' ? args[0] : args[0].url;
            const method = args[1]?.method || 'GET';
            const startTime = Date.now();

            // Redact sensitive query VALUES (e.g. ?token=...) before they land in
            // the breadcrumb message/data. transport.scrubObject only URL-scrubs
            // payload keys in URL_VALUE_KEYS, so an unscrubbed URL here would ship
            // verbatim inside the breadcrumb trail (SEC-01/JS-10).
            const url = scrubUrlQueryValues(rawUrl);

            try {
                const response = await originalFetch.apply(window, args);
                const duration = Date.now() - startTime;

                this.add({
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
            } catch (error) {
                const duration = Date.now() - startTime;

                this.add({
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

        // Stamp the wrapper so a future install() bails and teardown() can unwrap.
        // JS-MULTI-01: tag the wrapper with THIS instance as owner so a second
        // SDK's uninstall() cannot restore the original and leave the still-active
        // first instance without fetch capture (mirrors the history owner fix).
        wrappedFetch._appLoggerFetchWrapped = true;
        wrappedFetch._appLoggerOriginal = originalFetch;
        wrappedFetch._appLoggerFetchOwner = this;
        window.fetch = wrappedFetch;
    }

    /**
   * Remove the breadcrumb monkeypatches and listeners installed by {@link install}.
   *
   * Restores window.fetch, each console method, and history.pushState/replaceState
   * from the stamped _appLoggerOriginal refs, and clears the per-instance flag so
   * a later install() re-arms cleanly. Idempotent and never throws (RML-01).
   */
    uninstall() {
        try {
            // Remove the capture-phase click listener (matching the capture
            // flag used in install()). Without this, uninstall()->install()
            // would stack a duplicate handler and retain the stale instance (JS-3).
            document.removeEventListener('click', this.boundClickHandler, true);

            // Restore console methods ONLY if the live wrapper belongs to THIS
            // instance. With two SDK instances the second's wrapper layers over
            // the first's; tearing down the first must NOT clobber the second's
            // wrapper (which would break the still-active instance, JS-MULTI-01).
            // Legacy wrappers (no owner stamp, e.g. an older built dist still in
            // memory) are treated as owned for BC so teardown stays functional.
            const levels = ['log', 'info', 'warn', 'error', 'debug'];
            levels.forEach(level => {
                // eslint-disable-next-line no-console
                const current = console[level];
                if (current && current._appLoggerConsoleWrapped && current._appLoggerOriginal
                    && (current._appLoggerConsoleOwner === this || current._appLoggerConsoleOwner === undefined)) {
                    // eslint-disable-next-line no-console
                    console[level] = current._appLoggerOriginal;
                }
            });

            // Restore fetch ONLY if the live wrapper belongs to THIS instance
            // (see console note above; legacy un-owned wrappers treated as owned).
            if (window.fetch && window.fetch._appLoggerFetchWrapped && window.fetch._appLoggerOriginal
                && (window.fetch._appLoggerFetchOwner === this || window.fetch._appLoggerFetchOwner === undefined)) {
                window.fetch = window.fetch._appLoggerOriginal;
            }

            // Restore history methods if WE wrapped them.
            if (history.pushState && history.pushState._appLoggerBreadcrumbWrapped
                && history.pushState._appLoggerOriginal) {
                history.pushState = history.pushState._appLoggerOriginal;
            }
            if (history.replaceState && history.replaceState._appLoggerBreadcrumbWrapped
                && history.replaceState._appLoggerOriginal) {
                history.replaceState = history.replaceState._appLoggerOriginal;
            }
        } catch {
            // Never crash on teardown.
        } finally {
            this.installed = false;
        }
    }
}
