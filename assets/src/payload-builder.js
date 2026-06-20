import { parseStackTrace } from './stack-parser.js';
import { createLogger } from './util/logger.js';

/**
 * Builds error payloads in the flat, snake_case shape the ingestion API
 * expects. Extracted from client.js so the Client can focus on lifecycle and
 * delegate all payload shaping/limits here.
 *
 * API expects:
 * {type, message, file, line, stack_trace, level, environment, ...}
 */
export class PayloadBuilder {
    /**
     * @param {Object} config - SDK configuration
     * @param {BreadcrumbCollector} breadcrumbs - Breadcrumb source
     * @param {SessionManager|null} sessionManager - Session id source (optional)
     * @param {function(): (string|null)} sessionHashProvider - Returns the current session hash
     */
    constructor(config, breadcrumbs, sessionManager, sessionHashProvider) {
        this.config = config;
        // Debug-gated internal logger (no-op in production) - JSSDK-03/04.
        this.logger = createLogger(config);
        this.breadcrumbs = breadcrumbs;
        this.sessionManager = sessionManager;
        this.sessionHashProvider = sessionHashProvider;
        // userAgent is immutable for the page lifetime; cache the derived browser
        // name lazily so build() doesn't re-scan the UA string on every error
        // (JSPERF-06).
        this._browserInfo = null;
    }

    /**
     * Build an error payload matching the API contract.
     *
     * @param {Error} error - Error to serialize
     * @param {string} level - Log level
     * @param {Object} [options] - Per-call extra context/tags
     * @param {Object} [baseContext] - Accumulated extra context (Client.setExtra)
     * @param {Object} [baseTags] - Accumulated tags (Client.setTags)
     * @returns {Object} API-ready payload (null values removed)
     */
    build(error, level, options = {}, baseContext = {}, baseTags = {}) {
        try {
            const stackTrace = parseStackTrace(error);
            const firstFrame = stackTrace.length > 0 ? stackTrace[0] : null;

            // API length constraints: type (255), message (1000), file (500).
            // API requires line > 0 (Positive constraint), default to 1.
            const payload = {
                type: this.truncate(error.name || 'Error', 255),
                message: this.truncate(error.message || 'Unknown error', 1000),
                file: this.truncate(firstFrame?.file || options.extra?.filename || 'unknown', 500),
                line: firstFrame?.line || options.extra?.lineno || 1,
                stack_trace: stackTrace,

                level: level || 'error',
                source: 'frontend',
                environment: this.config.environment || 'production',
                release: this.config.release || null,
                url: window.location.href,
                http_method: this.detectHttpMethod(),
                http_status_code: this.extractHttpStatusCode(error, options),
                session_hash: this.sessionHashProvider(),
                session_id: this.sessionManager ? this.sessionManager.getSessionId() : null,
                timestamp: new Date().toISOString(),
                runtime: `JavaScript ${this.getBrowserInfo()}`,
                user_agent: navigator.userAgent,
                breadcrumbs: this.breadcrumbs.get(),
                context: { ...baseContext, ...options.extra },
                tags: { ...baseTags, ...options.tags },
            };

            return this.removeNullValues(payload);
        } catch (err) {
            this.logger.error('ApplicationLogger: Failed to build payload', err);
            return {
                type: 'Error',
                message: this.truncate('Failed to build error payload', 1000),
                file: 'unknown',
                line: 1,
                stack_trace: [],
                level: 'error',
            };
        }
    }

    /**
     * Truncate a string to a maximum length (adds an ellipsis when cut).
     *
     * @param {string} value - String to truncate
     * @param {number} maxLength - Maximum allowed length
     * @returns {string} Truncated string (non-strings returned unchanged)
     */
    truncate(value, maxLength) {
        if (!value || typeof value !== 'string') {
            return value;
        }
        if (value.length <= maxLength) {
            return value;
        }
        return value.substring(0, maxLength - 3) + '...';
    }

    /**
     * Detect the HTTP method for the current page load (always GET for navigations).
     *
     * @returns {string}
     */
    detectHttpMethod() {
        // Page loads / SPA navigations are always GET from the browser's POV.
        return 'GET';
    }

    /**
     * Extract an HTTP status code from the error or its context.
     *
     * Checks the error's status property, explicit options, extra context,
     * then parses the error message (e.g. "HTTP 404 Not Found").
     *
     * @param {Error} error - The error object
     * @param {Object} [options] - Options passed to captureException
     * @returns {number|null} Status code, or null when none is available
     */
    extractHttpStatusCode(error, options = {}) {
        try {
            if (error.status && typeof error.status === 'number') {
                return error.status;
            }
            if (options.httpStatusCode && typeof options.httpStatusCode === 'number') {
                return options.httpStatusCode;
            }
            if (options.extra?.http_status_code && typeof options.extra.http_status_code === 'number') {
                return options.extra.http_status_code;
            }
            if (options.extra?.httpStatusCode && typeof options.extra.httpStatusCode === 'number') {
                return options.extra.httpStatusCode;
            }
            if (error.message) {
                const match = error.message.match(/HTTP\s+(\d{3})/i);
                if (match) {
                    const status = parseInt(match[1], 10);
                    if (status >= 100 && status < 600) {
                        return status;
                    }
                }
            }
            return null;
        } catch {
            return null;
        }
    }

    /**
     * Derive a coarse browser name from the user agent.
     *
     * @returns {string}
     */
    getBrowserInfo() {
        if (this._browserInfo !== null) {
            return this._browserInfo;
        }

        this._browserInfo = this.detectBrowserInfo();
        return this._browserInfo;
    }

    /**
     * Derive a coarse browser name from the user agent (uncached).
     *
     * @private
     * @returns {string}
     */
    detectBrowserInfo() {
        const ua = navigator.userAgent;

        if (ua.includes('Chrome') && !ua.includes('Edge')) {
            return 'Chrome';
        }
        if (ua.includes('Firefox')) {
            return 'Firefox';
        }
        if (ua.includes('Safari') && !ua.includes('Chrome')) {
            return 'Safari';
        }
        if (ua.includes('Edge') || ua.includes('Edg/')) {
            return 'Edge';
        }
        if (ua.includes('MSIE') || ua.includes('Trident/')) {
            return 'IE';
        }
        return 'Unknown';
    }

    /**
     * Drop null/undefined top-level values to reduce payload size.
     *
     * @param {Object} obj - Payload object
     * @returns {Object} New object without null/undefined values
     */
    removeNullValues(obj) {
        const cleaned = {};
        for (const [key, value] of Object.entries(obj)) {
            if (value !== null && value !== undefined) {
                cleaned[key] = value;
            }
        }
        return cleaned;
    }
}
