/**
 * Internal debug-gated logger for the JS SDK.
 *
 * RULE #1: the SDK must never impact or surprise the host application. Writing
 * to the host page's console (even console.warn/error) leaks SDK internals to
 * users and pollutes their console, violating the project's "no console in
 * production" standard. Every SDK module routes its diagnostics through this
 * logger instead of calling `console.*` directly.
 *
 * Behaviour:
 * - All methods are NO-OPS unless `config.debug === true`.
 * - When enabled, output is prefixed and routed to the matching console method.
 * - Never throws: a missing/odd `console` (or a console method that throws) is
 *   swallowed so logging can never crash the host page.
 *
 * Usage:
 *   import { createLogger } from './util/logger.js';
 *   this.logger = createLogger(config);
 *   this.logger.warn('Something happened', details);
 *   this.logger.error('Send failed', err);
 *
 * The logger reads `config.debug` lazily on every call, so toggling
 * `config.debug` at runtime (e.g. via the exposed control API) takes effect
 * immediately without re-creating the logger.
 */

const PREFIX = 'ApplicationLogger:';

/**
 * Safely invoke a console method, swallowing any error and tolerating a
 * missing console implementation. Never throws.
 *
 * @param {string} method - console method name ('warn', 'error', 'log', 'info')
 * @param {unknown[]} args - arguments to forward
 * @returns {void}
 */
function safeConsole(method, args) {
    try {
        // This helper is the SINGLE, centralised gateway to the host console for
        // the entire SDK. Every other module routes through createLogger()/logger,
        // which only reaches here when debug is enabled. Allowing all four methods
        // here (vs the repo's warn/error allowlist) is intentional and contained.
        // eslint-disable-next-line no-console
        if (typeof console !== 'undefined' && typeof console[method] === 'function') {
            // eslint-disable-next-line no-console
            console[method](PREFIX, ...args);
        }
    } catch {
        // Logging must never crash the host application.
    }
}

/**
 * Create a debug-gated logger bound to an SDK config object.
 *
 * @param {{debug?: boolean}} [config={}] - SDK config; `debug` gates all output.
 * @returns {{warn: Function, error: Function, log: Function, info: Function, isEnabled: Function}}
 */
export function createLogger(config = {}) {
    const enabled = () => Boolean(config && config.debug);

    return {
        /**
         * @param {...unknown} args
         * @returns {void}
         */
        warn(...args) {
            if (enabled()) {
                safeConsole('warn', args);
            }
        },
        /**
         * @param {...unknown} args
         * @returns {void}
         */
        error(...args) {
            if (enabled()) {
                safeConsole('error', args);
            }
        },
        /**
         * @param {...unknown} args
         * @returns {void}
         */
        log(...args) {
            if (enabled()) {
                safeConsole('log', args);
            }
        },
        /**
         * @param {...unknown} args
         * @returns {void}
         */
        info(...args) {
            if (enabled()) {
                safeConsole('info', args);
            }
        },
        /**
         * @returns {boolean} Whether debug logging is currently enabled.
         */
        isEnabled() {
            return enabled();
        },
    };
}

/**
 * Shared no-op-by-default logger for modules that don't carry a config object.
 * Reads from a module-local config that can be pointed at the real config via
 * {@link setSharedLoggerConfig}. Most modules should prefer createLogger(config).
 */
const sharedConfig = { debug: false };

/**
 * Point the shared logger at a config object (so its `debug` flag is honoured).
 *
 * @param {{debug?: boolean}} config
 * @returns {void}
 */
export function setSharedLoggerConfig(config) {
    sharedConfig.debug = Boolean(config && config.debug);
}

/** Shared logger instance (no-op unless setSharedLoggerConfig enables debug). */
export const logger = createLogger(sharedConfig);
