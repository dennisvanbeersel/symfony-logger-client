import { PayloadBuilder } from './payload-builder.js';
import { parseStackTrace, parseStackLine } from './stack-parser.js';
import { SessionManager } from './session-manager.js';
import { hashHex64 } from './util/hash.js';
import { countUserInteractions } from './util/interaction.js';
import { scrubUrlQueryValues } from './scrub-fields.js';
import { createLogger } from './util/logger.js';

const NUCLEAR_KEY = '_appLogger_nuclear';
const RESURRECTION_ATTEMPTS_KEY = '_appLogger_resurrection_attempts';
const MAX_RESURRECTION_ATTEMPTS = 5;
const NUCLEAR_MAX_AGE_MS = 24 * 60 * 60 * 1000; // 24 hours
const SESSION_ID_KEY = '_app_logger_session_id';

/**
 * Lifecycle coordinator for error capture and error-triggered session replay.
 *
 * Responsibilities kept here: installing global handlers, draining the early
 * error buffers (nuclear + pre-SDK), orchestrating the two-phase replay flow,
 * and the page-unload beacon flush. Payload shaping is delegated to
 * {@link PayloadBuilder} and session-hash crypto to {@link SessionManager}.
 *
 * RESILIENCE: every handler is wrapped in try/catch - the SDK must never crash
 * the host application.
 */
export class Client {
    /**
     * @param {Object} config - Configuration options
     * @param {Transport} transport - Transport layer for API communication
     * @param {BreadcrumbCollector} breadcrumbs - Breadcrumb tracking
     * @param {ErrorDetector|null} errorDetector - Error detector for replay capture (optional)
     * @param {SessionManager|null} sessionManager - Session manager for session ID tracking (optional)
     */
    constructor(config, transport, breadcrumbs, errorDetector = null, sessionManager = null) {
        this.config = config;
        // Debug-gated internal logger (no-op in production) - JSSDK-03/04.
        this.logger = createLogger(config);
        this.transport = transport;
        this.breadcrumbs = breadcrumbs;
        this.errorDetector = errorDetector;
        this.sessionManager = sessionManager;
        this.userContext = null;
        this.tags = {};
        this.extra = {};
        this.pendingBeaconErrors = [];
        this.cachedSessionHash = null; // Pre-computed SHA-256 hash (async init)
        this.installed = false; // Guard against double-registration (RML-02).

        this.payloadBuilder = new PayloadBuilder(
            config,
            breadcrumbs,
            sessionManager,
            () => this.getSessionHash(),
        );

        // Bound handlers, stored so EVERY listener registered in install() can be
        // removed again in teardown() (RML-02). Anonymous handlers would be
        // unremovable and leak on each re-init cycle.
        this.boundFlushBeacon = () => this.flushBeaconErrors();
        this.boundVisibilityFlush = () => {
            if (document.visibilityState === 'hidden') {
                this.flushBeaconErrors();
            }
        };
        this.boundErrorHandler = (event) => {
            try {
                this.captureException(event.error || new Error(event.message), {
                    extra: {
                        filename: event.filename,
                        lineno: event.lineno,
                        colno: event.colno,
                    },
                });
            } catch (error) {
                if (this.shouldLog()) {
                    this.logger.error('ApplicationLogger: Failed to capture error', error);
                }
            }
        };
        this.boundRejectionHandler = (event) => {
            try {
                this.captureException(event.reason, {
                    extra: { type: 'unhandledrejection' },
                });
            } catch (error) {
                if (this.shouldLog()) {
                    this.logger.error('ApplicationLogger: Failed to capture rejection', error);
                }
            }
        };
    }

    /**
     * Whether debug logging is enabled.
     * @returns {boolean}
     */
    shouldLog() {
        return !!(this.config && this.config.debug);
    }

    /**
     * Install global error handlers.
     *
     * Order matters: resurrect prior-load nuclear errors, drain the pre-SDK
     * buffer, then install live handlers for future errors.
     */
    install() {
        // Guard against double-registration: a re-init without teardown would
        // otherwise stack a second set of error/rejection/unload listeners (RML-02).
        if (this.installed) {
            return;
        }

        try {
            // Session hash is computed asynchronously; early errors may ship null.
            this.initSessionHash().catch(() => {
                // Silently fail - session hash is optional.
            });

            this.processResurrectedErrors();
            this.processBufferedErrors();

            // All four listeners use bound references so teardown() can remove them.
            window.addEventListener('error', this.boundErrorHandler);
            window.addEventListener('unhandledrejection', this.boundRejectionHandler);

            // Beacon-flush critical errors as the page goes away (desktop + mobile).
            window.addEventListener('beforeunload', this.boundFlushBeacon);
            document.addEventListener('visibilitychange', this.boundVisibilityFlush);

            this.breadcrumbs.install();

            this.installed = true;
        } catch (error) {
            if (this.shouldLog()) {
                this.logger.error('ApplicationLogger: Failed to install', error);
            }
        }
    }

    /**
     * Remove ALL global handlers registered by {@link install}: the window
     * 'error'/'unhandledrejection' pair plus the beforeunload/visibilitychange
     * flush listeners, and the breadcrumb monkeypatches. Idempotent and never
     * throws (RML-02).
     */
    teardown() {
        try {
            window.removeEventListener('error', this.boundErrorHandler);
            window.removeEventListener('unhandledrejection', this.boundRejectionHandler);
            window.removeEventListener('beforeunload', this.boundFlushBeacon);
            document.removeEventListener('visibilitychange', this.boundVisibilityFlush);
            if (this.breadcrumbs && typeof this.breadcrumbs.uninstall === 'function') {
                this.breadcrumbs.uninstall();
            }
        } catch {
            // Never crash on teardown.
        } finally {
            this.installed = false;
        }
    }

    /**
     * Resurrect "nuclear" errors persisted by the inline trap on a prior load.
     *
     * These are catastrophic errors that broke JS execution before the SDK
     * could send them. On the next (normalised) page load we re-send them with
     * a `resurrected` flag, then clear localStorage once all succeed.
     *
     * Async + awaited per-error: the `failed` counter must reflect the REAL send
     * outcome. `captureException()` never throws, so a fire-and-forget call could
     * not surface a network failure and `failed` would always stay 0 — the
     * retry/attempt-bookkeeping below would then wrongly clear storage even when
     * delivery failed. We therefore await each delivery and treat a rejected send
     * as a failure. The whole method still never throws into the host app.
     *
     * @returns {Promise<void>}
     */
    async processResurrectedErrors() {
        try {
            const attempts = parseInt(localStorage.getItem(RESURRECTION_ATTEMPTS_KEY) || '0', 10);
            if (attempts >= MAX_RESURRECTION_ATTEMPTS) {
                if (this.shouldLog()) {
                    this.logger.warn('ApplicationLogger: Max resurrection attempts reached, clearing nuclear errors');
                }
                localStorage.removeItem(NUCLEAR_KEY);
                localStorage.removeItem(RESURRECTION_ATTEMPTS_KEY);
                return;
            }

            const stored = localStorage.getItem(NUCLEAR_KEY);
            if (!stored) {
                return;
            }

            let errors;
            try {
                errors = JSON.parse(stored);
            } catch (parseError) {
                if (this.shouldLog()) {
                    this.logger.error('ApplicationLogger: Failed to parse nuclear errors, clearing', parseError);
                }
                localStorage.removeItem(NUCLEAR_KEY);
                return;
            }

            if (!Array.isArray(errors) || errors.length === 0) {
                localStorage.removeItem(NUCLEAR_KEY);
                return;
            }

            const now = Date.now();
            const validErrors = errors.filter(err => (now - (err.t || 0)) < NUCLEAR_MAX_AGE_MS);

            if (validErrors.length === 0) {
                if (this.shouldLog()) {
                    this.logger.warn('ApplicationLogger: All nuclear errors expired, clearing');
                }
                localStorage.removeItem(NUCLEAR_KEY);
                return;
            }

            if (this.shouldLog()) {
                this.logger.warn(`ApplicationLogger: Resurrecting ${validErrors.length} nuclear error(s) from previous session`);
            }

            let failed = 0;
            for (const err of validErrors) {
                try {
                    const message = err.m
                        ? String(err.m)
                        : 'Nuclear error (catastrophic JavaScript failure)';
                    const error = new Error(message);
                    error.name = 'NuclearError';

                    // Build the payload here and AWAIT the transport directly so a
                    // network failure rejects and is counted. captureException()
                    // swallows all errors, so awaiting it would never surface a
                    // failed send (the bug this guards). Nuclear errors carry no
                    // session replay, so the direct transport.send is equivalent.
                    const payload = this.buildPayload(error, 'error', {
                        extra: {
                            resurrected: true,
                            nuclear: true,
                            resurrectTimestamp: now,
                            originalTimestamp: err.t || 0,
                            errorAge: Math.floor((now - (err.t || now)) / 1000),
                            filename: err.f || 'unknown',
                            lineno: err.l || 0,
                            colno: err.c || 0,
                            // The nuclear trap persists the raw page URL (location.href)
                            // on every captured error. A catastrophic error on e.g.
                            // /reset?token=... would otherwise resurrect that secret
                            // into extra.originalUrl, which transport's URL scrubbing
                            // did not cover by key name. Scrub at the source so the
                            // sensitive query VALUE never leaves this method (SEC-JS-02).
                            originalUrl: err.u ? scrubUrlQueryValues(String(err.u)) : 'unknown',
                            sessionGap: true,
                        },
                    });

                    await this.transport.send(payload);
                } catch (sendError) {
                    failed++;
                    if (this.shouldLog()) {
                        this.logger.error('ApplicationLogger: Failed to resurrect nuclear error', sendError);
                    }
                }
            }

            if (failed === 0) {
                localStorage.removeItem(NUCLEAR_KEY);
                localStorage.removeItem(RESURRECTION_ATTEMPTS_KEY);
                if (this.shouldLog()) {
                    this.logger.warn(`ApplicationLogger: Successfully resurrected ${validErrors.length} nuclear error(s)`);
                }
            } else {
                localStorage.setItem(RESURRECTION_ATTEMPTS_KEY, String(attempts + 1));
                if (this.shouldLog()) {
                    this.logger.warn(`ApplicationLogger: Resurrection partial success (${validErrors.length - failed} succeeded, ${failed} failed), will retry on next load`);
                }
            }
        } catch (error) {
            this.logger.error('ApplicationLogger: Failed to process resurrected errors', error);
        }
    }

    /**
     * Drain errors buffered by the early trap (window._appLoggerBuffer) before
     * the full SDK loaded, re-sending each with a `buffered` flag.
     */
    processBufferedErrors() {
        try {
            if (!window._appLoggerBuffer || !Array.isArray(window._appLoggerBuffer.errors)) {
                return;
            }

            const buffered = window._appLoggerBuffer.errors;
            if (buffered.length === 0) {
                return;
            }

            if (this.shouldLog()) {
                this.logger.warn(`ApplicationLogger: Processing ${buffered.length} buffered error(s)`);
            }

            // Clear immediately to prevent reprocessing.
            window._appLoggerBuffer.errors = [];

            let processed = 0;
            let failed = 0;

            for (const item of buffered) {
                try {
                    if (!item || typeof item !== 'object') {
                        failed++;
                        continue;
                    }

                    if (item.type === 'error') {
                        const message = item.error && item.error.message
                            ? String(item.error.message)
                            : (item.message ? String(item.message) : 'Unknown buffered error');
                        const error = new Error(message);

                        if (item.error && typeof item.error === 'object') {
                            if (item.error.name) {
                                error.name = String(item.error.name);
                            }
                            if (item.error.stack) {
                                error.stack = String(item.error.stack);
                            }
                        }

                        this.captureException(error, {
                            extra: {
                                buffered: true,
                                bufferedAt: item.timestamp || Date.now(),
                                filename: item.filename || 'unknown',
                                lineno: typeof item.lineno === 'number' ? item.lineno : 0,
                                colno: typeof item.colno === 'number' ? item.colno : 0,
                            },
                        });
                        processed++;
                    } else if (item.type === 'rejection') {
                        const reason = item.reason;
                        let error;

                        if (reason && typeof reason === 'object') {
                            error = new Error(reason.message ? String(reason.message) : 'Unhandled promise rejection');
                            error.name = reason.name ? String(reason.name) : 'UnhandledRejection';
                            if (reason.stack) {
                                error.stack = String(reason.stack);
                            }
                        } else {
                            const message = (reason !== null && reason !== undefined)
                                ? String(reason)
                                : 'Unhandled promise rejection (undefined)';
                            error = new Error(message);
                            error.name = 'UnhandledRejection';
                        }

                        this.captureException(error, {
                            extra: {
                                buffered: true,
                                bufferedAt: item.timestamp || Date.now(),
                                type: 'unhandledrejection',
                            },
                        });
                        processed++;
                    } else {
                        if (this.shouldLog()) {
                            this.logger.warn('ApplicationLogger: Unknown buffered item type:', item.type);
                        }
                        failed++;
                    }
                } catch (itemError) {
                    failed++;
                    if (this.shouldLog()) {
                        this.logger.error('ApplicationLogger: Failed to process buffered item', itemError);
                    }
                }
            }

            if (this.shouldLog()) {
                this.logger.warn(`ApplicationLogger: Buffered errors processed (${processed} succeeded, ${failed} failed)`);
            }
        } catch (error) {
            this.logger.error('ApplicationLogger: Failed to process buffered errors', error);
        }
    }

    /**
     * Count user-interaction (click) events in a replay event array.
     *
     * @param {Array} events - Replay events
     * @returns {number} Count of interaction events
     */
    countClickEvents(events) {
        return countUserInteractions(events);
    }

    /**
     * Capture an exception and run the two-phase session replay flow.
     *
     * Phase 1: send error + pre-error replay immediately.
     * Phase 2: continue recording, send the recovery session separately.
     *
     * @param {Error} error - Error to capture
     * @param {Object} [options] - Extra context/tags
     */
    async captureException(error, options = {}) {
        try {
            const payload = this.buildPayload(error, 'error', options);

            // Defense in depth against the opt-out leak (BUNDLE-REPLAY-OPTOUT):
            // `config.sessionReplayEnabled` is the single source of truth. Even
            // if a stale errorDetector/sessionManager pointer survived a
            // disable(), no replay must be attached once replay is opted out.
            const replayEnabled = this.config.sessionReplayEnabled !== false
                && this.errorDetector
                && this.errorDetector.replayBuffer
                && this.errorDetector.sessionManager;

            if (!replayEnabled) {
                if (this.shouldLog() && !this.errorDetector) {
                    this.logger.warn('ApplicationLogger: Session replay disabled (no error detector)');
                }
                await this.transport.send(payload);
                return;
            }

            try {
                // handleError() marks the buffer, returns pre-error events and
                // triggers the onErrorDetected callback.
                const replayContext = await this.errorDetector.handleError(error, payload);
                const replayData = this.buildReplayData(replayContext);

                await this.transport.send(payload, replayData);

                // Phase 2: record the recovery session in the background.
                if (typeof this.errorDetector.startRecoveryRecording === 'function') {
                    this.errorDetector.startRecoveryRecording(error).catch(recoveryError => {
                        if (this.shouldLog()) {
                            this.logger.error('ApplicationLogger: Recovery recording failed', recoveryError);
                        }
                    });
                }
            } catch (replayError) {
                if (this.shouldLog()) {
                    this.logger.error('ApplicationLogger: Session replay failed, sending error without replay', replayError);
                }
                await this.transport.send(payload);
            }
        } catch (captureError) {
            this.logger.error('Client: Failed to capture exception', captureError);
        }
    }

    /**
     * Build phase-1 replay data from an error-detector context, or null when
     * there is nothing worth sending (no pre-error events / no user interaction).
     *
     * @param {Object|null} replayContext - Context returned by ErrorDetector.handleError
     * @returns {Object|null} Replay data for the error payload, or null
     */
    buildReplayData(replayContext) {
        if (!replayContext || !replayContext.events || replayContext.events.length === 0) {
            return null;
        }

        const preErrorEvents = replayContext.events.filter(event =>
            event.phase === 'before_error' || event.phase === 'error',
        );

        if (preErrorEvents.length === 0 || this.countClickEvents(preErrorEvents) === 0) {
            return null;
        }

        return {
            sessionId: replayContext.sessionId,
            events: preErrorEvents,
            phase: 'pre-error',
        };
    }

    /**
     * Capture a message at the given level.
     *
     * @param {string} message - Message text
     * @param {string} [level] - Log level
     * @param {Object} [options] - Extra context/tags
     */
    captureMessage(message, level = 'info', options = {}) {
        const payload = this.buildPayload(new Error(message), level, options);
        this.transport.send(payload);
    }

    /**
     * Build an API error payload (delegates to {@link PayloadBuilder}).
     *
     * @param {Error} error - Error to serialize
     * @param {string} level - Log level
     * @param {Object} [options] - Extra context/tags
     * @returns {Object} API-ready payload
     */
    buildPayload(error, level, options = {}) {
        return this.payloadBuilder.build(error, level, options, this.extra, this.tags);
    }

    /**
     * Parse an error's stack trace (delegates to the stack-parser module).
     *
     * @param {Error} error - Error to parse
     * @returns {Array<Object>} Stack frames
     */
    parseStackTrace(error) {
        return parseStackTrace(error);
    }

    /**
     * Parse a single stack line (delegates to the stack-parser module).
     *
     * @param {string} line - Stack line
     * @returns {Object|null} Parsed frame
     */
    parseStackLine(line) {
        return parseStackLine(line);
    }

    /**
     * Truncate a string to a max length (delegates to {@link PayloadBuilder}).
     * @param {string} value
     * @param {number} maxLength
     * @returns {string}
     */
    truncate(value, maxLength) {
        return this.payloadBuilder.truncate(value, maxLength);
    }

    /**
     * Detect the HTTP method for this page load (delegates to {@link PayloadBuilder}).
     * @returns {string}
     */
    detectHttpMethod() {
        return this.payloadBuilder.detectHttpMethod();
    }

    /**
     * Extract an HTTP status code (delegates to {@link PayloadBuilder}).
     * @param {Error} error
     * @param {Object} [options]
     * @returns {number|null}
     */
    extractHttpStatusCode(error, options = {}) {
        return this.payloadBuilder.extractHttpStatusCode(error, options);
    }

    /**
     * Derive the browser name (delegates to {@link PayloadBuilder}).
     * @returns {string}
     */
    getBrowserInfo() {
        return this.payloadBuilder.getBrowserInfo();
    }

    /**
     * Remove null/undefined values (delegates to {@link PayloadBuilder}).
     * @param {Object} obj
     * @returns {Object}
     */
    removeNullValues(obj) {
        return this.payloadBuilder.removeNullValues(obj);
    }

    /**
     * Get the session hash for GDPR-compliant session tracking.
     *
     * Priority: server-provided config.sessionHash, then the async-computed
     * cached hash, then a synchronous djb2 fallback. Returns null on failure.
     *
     * @returns {string|null} 64-char hex hash, or null
     */
    getSessionHash() {
        try {
            if (this.config.sessionHash) {
                return this.config.sessionHash;
            }
            if (this.cachedSessionHash) {
                return this.cachedSessionHash;
            }
            return hashHex64(this.getOrCreateSessionId());
        } catch {
            return null;
        }
    }

    /**
     * Generate a UUID-style session ID (crypto.randomUUID when available).
     * @returns {string}
     */
    generateSessionId() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    /**
     * Pre-compute and cache the session hash (real SHA-256 when available).
     *
     * Crypto lives in {@link SessionManager.computeSessionHash}; this caches
     * the result for synchronous reads in {@link getSessionHash}.
     *
     * @returns {Promise<void>}
     */
    async initSessionHash() {
        try {
            const sessionId = this.getOrCreateSessionId();
            this.cachedSessionHash = await SessionManager.computeSessionHash(sessionId);
            if (this.shouldLog()) {
                this.logger.warn('ApplicationLogger: Session hash initialized');
            }
        } catch (error) {
            try {
                this.cachedSessionHash = SessionManager.computeSessionHashSync(this.getOrCreateSessionId());
            } catch {
                if (this.shouldLog()) {
                    this.logger.error('ApplicationLogger: Failed to initialize session hash', error);
                }
            }
        }
    }

    /**
     * Get (or lazily create) the per-tab session ID used for hashing.
     * @returns {string}
     */
    getOrCreateSessionId() {
        if (typeof sessionStorage !== 'undefined') {
            let sessionId = sessionStorage.getItem(SESSION_ID_KEY);
            if (!sessionId) {
                sessionId = this.generateSessionId();
                sessionStorage.setItem(SESSION_ID_KEY, sessionId);
            }
            return sessionId;
        }
        return this.generateSessionId();
    }

    /**
     * Non-cryptographic 64-char-hex session hash fallback.
     *
     * Retained as a thin wrapper for backward compatibility; the implementation
     * lives in the shared hash util.
     *
     * @param {string} str - String to hash
     * @returns {string} 64-character hexadecimal hash
     */
    djb2Hash(str) {
        return hashHex64(str);
    }

    setUser(user) {
        this.userContext = user;
    }

    setTags(tags) {
        this.tags = { ...this.tags, ...tags };
    }

    setExtra(extra) {
        this.extra = { ...this.extra, ...extra };
    }

    /**
     * Flush pending errors via the Beacon API on page unload.
     */
    flushBeaconErrors() {
        try {
            if (!navigator.sendBeacon) {
                return;
            }

            const stats = this.transport.getStats();
            if (stats.storedErrors === 0 && stats.queueSize === 0) {
                return;
            }

            this.transport.flushWithBeacon();
        } catch {
            // Last-chance send; never crash on flush.
        }
    }
}
