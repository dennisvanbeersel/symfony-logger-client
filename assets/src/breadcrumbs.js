/**
 * Breadcrumb collector for tracking user actions
 */
export class BreadcrumbCollector {
    constructor(maxBreadcrumbs = 50) {
        this.breadcrumbs = [];
        this.maxBreadcrumbs = maxBreadcrumbs;
    }

    /**
   * Install automatic breadcrumb tracking
   */
    install() {
    // Track clicks
        document.addEventListener('click', (event) => {
            const target = event.target;
            const tagName = target.tagName.toLowerCase();
            let message = `Clicked ${tagName}`;

            if (target.id) {
                message += `#${target.id}`;
            } else if (target.className) {
                message += `.${target.className.split(' ')[0]}`;
            }

            this.add({
                type: 'ui',
                category: 'click',
                message,
                data: {
                    tag: tagName,
                    id: target.id,
                    class: target.className,
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
   */
    wrapConsole() {
        const levels = ['log', 'info', 'warn', 'error', 'debug'];

        levels.forEach(level => {
            // eslint-disable-next-line no-console
            const original = console[level];
            // eslint-disable-next-line no-console
            console[level] = (...args) => {
                this.add({
                    type: 'console',
                    category: 'console',
                    message: args.join(' '),
                    level: level === 'log' ? 'info' : level,
                    data: { arguments: args },
                });
                return original.apply(console, args);
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
