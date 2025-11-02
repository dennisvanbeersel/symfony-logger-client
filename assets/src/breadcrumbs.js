/**
 * Breadcrumb collector for tracking user actions
 *
 * ZERO-CONFIG ERROR CAPTURE:
 * When console.error() is called with an Error object, automatically
 * captures it and sends to API. This provides zero-config tracking for
 * the common pattern: .catch(err => console.error('Failed:', err))
 */
export class BreadcrumbCollector {
    constructor(maxBreadcrumbs = 50, errorCaptureCallback = null) {
        this.breadcrumbs = [];
        this.maxBreadcrumbs = maxBreadcrumbs;
        this.installed = false; // Track installation state
        this.errorCaptureCallback = errorCaptureCallback; // Callback to capture errors automatically
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

        // Track clicks
        document.addEventListener('click', (event) => {
            const target = event.target;
            const tagName = target.tagName.toLowerCase();
            let message = `Clicked ${tagName}`;

            // Get className as string (handle SVG elements)
            const getClassName = (element) => {
                if (!element.className) return '';
                // For SVG elements, className is an SVGAnimatedString
                if (typeof element.className === 'object' && element.className.baseVal !== undefined) {
                    return element.className.baseVal;
                }
                // For HTML elements, className is a string
                return element.className;
            };

            const className = getClassName(target);

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
        }, true);

        // Track navigation
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;

        history.pushState = (...args) => {
            this.add({
                type: 'navigation',
                category: 'navigation',
                message: `Navigated to ${args[2]}`,
                data: { to: args[2] },
            });
            return originalPushState.apply(history, args);
        };

        history.replaceState = (...args) => {
            this.add({
                type: 'navigation',
                category: 'navigation',
                message: `Replaced state ${args[2]}`,
                data: { to: args[2] },
            });
            return originalReplaceState.apply(history, args);
        };

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

            // eslint-disable-next-line no-console
            console[level] = (...args) => {
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
                                        consoleMessage: args.filter(arg => !(arg instanceof Error))
                                            .map(arg => String(arg))
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
                                return JSON.stringify(arg);
                            } catch {
                                // Circular reference or non-serializable
                                return Object.prototype.toString.call(arg);
                            }
                        }

                        // Primitives
                        return String(arg);
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
        });
    }

    /**
   * Wrap fetch for HTTP request breadcrumbs
   */
    wrapFetch() {
        const originalFetch = window.fetch;

        window.fetch = async (...args) => {
            const url = typeof args[0] === 'string' ? args[0] : args[0].url;
            const method = args[1]?.method || 'GET';
            const startTime = Date.now();

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
    }
}
