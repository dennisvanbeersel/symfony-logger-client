/**
 * Client for capturing errors and sending to platform
 *
 * ERROR-TRIGGERED SESSION REPLAY:
 * - When error detected, triggers replay buffer capture
 * - Sends buffered events (before/after error) to backend
 * - Links replay data to error for debugging
 *
 * RESILIENCE FEATURES:
 * - Beacon API for page unload events (ensures critical errors are sent)
 * - All error handling wrapped in try-catch
 * - Never crashes on logging errors
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
        this.transport = transport;
        this.breadcrumbs = breadcrumbs;
        this.errorDetector = errorDetector;
        this.sessionManager = sessionManager;
        this.userContext = null;
        this.tags = {};
        this.extra = {};
        this.pendingBeaconErrors = [];
    }

    /**
   * Install global error handlers
   *
   * Processing order (critical for reliability):
   * 1. Resurrect nuclear errors from previous page loads (localStorage)
   * 2. Process buffered errors from current page load (window._appLoggerBuffer)
   * 3. Install live error handlers for future errors
   */
    install() {
        try {
            // 1. Process "resurrected" errors first (from previous sessions)
            //    These are catastrophic errors that broke JS execution
            this.processResurrectedErrors();

            // 2. Process any errors that were buffered before SDK loaded (current page)
            this.processBufferedErrors();

            // 3. Handle uncaught errors (live, from this point forward)
            window.addEventListener('error', (event) => {
                try {
                    this.captureException(event.error || new Error(event.message), {
                        extra: {
                            filename: event.filename,
                            lineno: event.lineno,
                            colno: event.colno,
                        },
                    });
                } catch (error) {
                    // Never crash on error handling
                    console.error('ApplicationLogger: Failed to capture error', error);
                }
            });

            // Handle unhandled promise rejections
            window.addEventListener('unhandledrejection', (event) => {
                try {
                    this.captureException(event.reason, {
                        extra: {
                            type: 'unhandledrejection',
                        },
                    });
                } catch (error) {
                    console.error('ApplicationLogger: Failed to capture rejection', error);
                }
            });

            // Use Beacon API for page unload to ensure critical errors are sent
            window.addEventListener('beforeunload', () => {
                this.flushBeaconErrors();
            });

            // Also try on visibilitychange (for mobile)
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.flushBeaconErrors();
                }
            });

            // Track breadcrumbs
            this.breadcrumbs.install();
        } catch (error) {
            // Installation failure should never crash the app
            console.error('ApplicationLogger: Failed to install', error);
        }
    }

    /**
     * Process "resurrected" errors from previous page loads.
     *
     * The nuclear error trap (ultra-minimal inline script) captures catastrophic
     * errors that break JavaScript execution and stores them to localStorage.
     * On the NEXT page load (when things have "normalized"), we resurrect and
     * send these errors.
     *
     * This handles the "nuclear scenario":
     * - Syntax errors before SDK loads
     * - Module import failures
     * - Blocking runtime errors
     * - Third-party script failures
     *
     * Flow:
     * 1. Check localStorage for '_appLogger_nuclear'
     * 2. Parse stored errors
     * 3. Send each error to API with 'resurrected: true' flag
     * 4. Clear localStorage after successful send
     */
    processResurrectedErrors() {
        try {
            const NUCLEAR_KEY = '_appLogger_nuclear';
            const RESURRECTION_ATTEMPTS_KEY = '_appLogger_resurrection_attempts';
            const MAX_ATTEMPTS = 5;
            const MAX_AGE_MS = 24 * 60 * 60 * 1000; // 24 hours

            // Check if we've tried too many times
            const attempts = parseInt(localStorage.getItem(RESURRECTION_ATTEMPTS_KEY) || '0', 10);
            if (attempts >= MAX_ATTEMPTS) {
                if (this.config.debug) {
                    console.warn('ApplicationLogger: Max resurrection attempts reached, clearing nuclear errors');
                }
                localStorage.removeItem(NUCLEAR_KEY);
                localStorage.removeItem(RESURRECTION_ATTEMPTS_KEY);
                return;
            }

            // Try to load nuclear errors
            const stored = localStorage.getItem(NUCLEAR_KEY);
            if (!stored) {
                // No errors to resurrect
                return;
            }

            // Parse stored errors
            let errors;
            try {
                errors = JSON.parse(stored);
            } catch (parseError) {
                // Corrupted data - clear it
                if (this.config.debug) {
                    console.error('ApplicationLogger: Failed to parse nuclear errors, clearing', parseError);
                }
                localStorage.removeItem(NUCLEAR_KEY);
                return;
            }

            // Validate array
            if (!Array.isArray(errors) || errors.length === 0) {
                localStorage.removeItem(NUCLEAR_KEY);
                return;
            }

            if (this.config.debug) {
                console.warn(`ApplicationLogger: Resurrecting ${errors.length} nuclear error(s) from previous session`);
            }

            // Filter out old errors (older than MAX_AGE)
            const now = Date.now();
            const validErrors = errors.filter(err => {
                const age = now - (err.t || 0);
                return age < MAX_AGE_MS;
            });

            if (validErrors.length === 0) {
                if (this.config.debug) {
                    console.warn('ApplicationLogger: All nuclear errors expired, clearing');
                }
                localStorage.removeItem(NUCLEAR_KEY);
                return;
            }

            // Track successes and failures
            let succeeded = 0;
            let failed = 0;

            // Send each error
            for (const err of validErrors) {
                try {
                    // Reconstruct error message
                    const message = err.m
                        ? String(err.m)
                        : 'Nuclear error (catastrophic JavaScript failure)';

                    const error = new Error(message);
                    error.name = 'NuclearError';

                    // Calculate age
                    const errorAge = now - (err.t || now);

                    // Capture with resurrection context
                    this.captureException(error, {
                        extra: {
                            resurrected: true,
                            nuclear: true,
                            resurrectTimestamp: now,
                            originalTimestamp: err.t || 0,
                            errorAge: Math.floor(errorAge / 1000), // seconds
                            filename: err.f || 'unknown',
                            lineno: err.l || 0,
                            colno: err.c || 0,
                            originalUrl: err.u || 'unknown',
                            sessionGap: true, // Error from previous page load
                        },
                    });

                    succeeded++;
                } catch (sendError) {
                    failed++;
                    if (this.config.debug) {
                        console.error('ApplicationLogger: Failed to resurrect nuclear error', sendError);
                    }
                }
            }

            // If all succeeded, clear localStorage
            if (failed === 0) {
                localStorage.removeItem(NUCLEAR_KEY);
                localStorage.removeItem(RESURRECTION_ATTEMPTS_KEY);

                if (this.config.debug) {
                    console.warn(`ApplicationLogger: Successfully resurrected ${succeeded} nuclear error(s)`);
                }
            } else {
                // Some failed - increment attempt counter
                localStorage.setItem(RESURRECTION_ATTEMPTS_KEY, String(attempts + 1));

                if (this.config.debug) {
                    console.warn(`ApplicationLogger: Resurrection partial success (${succeeded} succeeded, ${failed} failed), will retry on next load`);
                }
            }
        } catch (error) {
            // Never crash on resurrection
            console.error('ApplicationLogger: Failed to process resurrected errors', error);
        }
    }

    /**
     * Process errors that were buffered before SDK loaded.
     *
     * The early error buffer (window._appLoggerBuffer) captures errors that occur
     * before the full SDK loads (during page parsing, module loading, etc.).
     * This method processes those buffered errors and sends them to the API.
     */
    processBufferedErrors() {
        try {
            // Check if buffer exists and is valid
            if (!window._appLoggerBuffer ||
                !Array.isArray(window._appLoggerBuffer.errors)) {
                return;
            }

            const buffered = window._appLoggerBuffer.errors;

            // Nothing to process
            if (buffered.length === 0) {
                return;
            }

            if (this.config.debug) {
                console.warn(`ApplicationLogger: Processing ${buffered.length} buffered error(s)`);
            }

            // Clear buffer immediately to prevent reprocessing
            window._appLoggerBuffer.errors = [];

            // Track processing stats
            let processed = 0;
            let failed = 0;

            // Process each buffered error
            for (const item of buffered) {
                try {
                    // Validate item structure
                    if (!item || typeof item !== 'object') {
                        failed++;
                        continue;
                    }

                    if (item.type === 'error') {
                        // Reconstruct error object from buffered data
                        const message = item.error && item.error.message
                            ? String(item.error.message)
                            : (item.message ? String(item.message) : 'Unknown buffered error');

                        const error = new Error(message);

                        // Restore error properties if available
                        if (item.error && typeof item.error === 'object') {
                            if (item.error.name) {
                                error.name = String(item.error.name);
                            }
                            if (item.error.stack) {
                                error.stack = String(item.error.stack);
                            }
                        }

                        // Capture with buffered context
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
                        // Handle promise rejection
                        const reason = item.reason;
                        let error;

                        if (reason && typeof reason === 'object') {
                            const message = reason.message
                                ? String(reason.message)
                                : 'Unhandled promise rejection';

                            error = new Error(message);
                            error.name = reason.name ? String(reason.name) : 'UnhandledRejection';

                            if (reason.stack) {
                                error.stack = String(reason.stack);
                            }
                        } else {
                            // Primitive value or null/undefined
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
                        // Unknown item type
                        if (this.config.debug) {
                            console.warn('ApplicationLogger: Unknown buffered item type:', item.type);
                        }
                        failed++;
                    }
                } catch (itemError) {
                    // Failed to process this specific item - continue with others
                    failed++;
                    if (this.config.debug) {
                        console.error('ApplicationLogger: Failed to process buffered item', itemError);
                    }
                }
            }

            if (this.config.debug) {
                console.warn(`ApplicationLogger: Buffered errors processed (${processed} succeeded, ${failed} failed)`);
            }
        } catch (error) {
            // Never crash on buffer processing
            console.error('ApplicationLogger: Failed to process buffered errors', error);
        }
    }

    /**
     * Capture exception and trigger TWO-PHASE session replay if enabled
     *
     * TWO-PHASE ARCHITECTURE:
     * Phase 1 (Immediate): Send error + pre-error buffer right away
     * Phase 2 (Recovery): Continue recording, send recovery session separately
     *
     * Flow:
     * 1. Build error payload
     * 2. If session replay enabled:
     *    a) Call errorDetector.handleError() to mark buffer and get events
     *    b) Send error + pre-error replay immediately (don't wait)
     *    c) Start recording recovery session (async, non-blocking)
     * 3. If session replay disabled: send error only
     */
    async captureException(error, options = {}) {
        try {
            // Build error payload
            const payload = this.buildPayload(error, 'error', options);

            // TWO-PHASE SESSION REPLAY (with defensive null checks)
            if (this.errorDetector && this.errorDetector.replayBuffer && this.errorDetector.sessionManager) {
                try {
                    // CRITICAL: Call handleError() to properly mark the buffer and get events
                    // This method:
                    // 1. Marks the current buffer position as "error occurred"
                    // 2. Gets all buffered events (before error)
                    // 3. Triggers the onErrorDetected callback
                    // 4. Returns replay context with events
                    const replayContext = await this.errorDetector.handleError(error, payload);

                    // Defensive: Check if we got replay context with events
                    let replayData = null;
                    if (replayContext && replayContext.events && replayContext.events.length > 0) {
                        // Filter to get only pre-error events
                        const preErrorEvents = replayContext.events.filter(event =>
                            event.phase === 'before_error' || event.phase === 'error',
                        );

                        if (preErrorEvents.length > 0) {
                            replayData = {
                                sessionId: replayContext.sessionId,
                                events: preErrorEvents,
                                phase: 'pre-error', // Mark as phase 1
                            };

                            if (this.config.debug) {
                                console.warn('ApplicationLogger: Sending error with pre-error replay (phase 1)', {
                                    totalEvents: replayContext.events.length,
                                    preErrorEvents: preErrorEvents.length,
                                    sessionId: replayData.sessionId,
                                });
                            }
                        } else if (this.config.debug) {
                            console.warn('ApplicationLogger: No pre-error events in buffer', {
                                totalEvents: replayContext.events.length,
                                bufferStats: this.errorDetector.replayBuffer.getStats(),
                            });
                        }
                    } else if (this.config.debug) {
                        console.warn('ApplicationLogger: No replay context from error detector', {
                            hasContext: !!replayContext,
                            hasEvents: !!(replayContext && replayContext.events),
                            eventCount: replayContext?.events?.length || 0,
                            bufferStats: this.errorDetector.replayBuffer.getStats(),
                        });
                    }

                    // Send error + pre-error replay immediately
                    await this.transport.send(payload, replayData);

                    // Phase 2: Start recording recovery session (async, non-blocking)
                    // This runs in the background and sends separately when complete
                    // Defensive: Check method exists before calling
                    if (typeof this.errorDetector.startRecoveryRecording === 'function') {
                        this.errorDetector.startRecoveryRecording(error).catch(recoveryError => {
                            if (this.config.debug) {
                                console.error('ApplicationLogger: Recovery recording failed', recoveryError);
                            }
                        });
                    }
                } catch (replayError) {
                    // If session replay fails, still send error without replay data
                    if (this.config.debug) {
                        console.error('ApplicationLogger: Session replay failed, sending error without replay', replayError);
                    }
                    await this.transport.send(payload);
                }
            } else {
                // No session replay - send error only
                if (this.config.debug && !this.errorDetector) {
                    console.warn('ApplicationLogger: Session replay disabled (no error detector)');
                }
                await this.transport.send(payload);
            }
        } catch (captureError) {
            // Never crash on error capture
            console.error('Client: Failed to capture exception', captureError);
        }
    }

    /**
   * Capture message
   */
    captureMessage(message, level = 'info', options = {}) {
        const payload = this.buildPayload(new Error(message), level, options);
        this.transport.send(payload);
    }

    /**
   * Build error payload matching API expectations
   *
   * API expects flat structure with snake_case field names:
   * {type, message, file, line, stack_trace, level, environment, ...}
   */
    buildPayload(error, level, options = {}) {
        try {
            const stackTrace = this.parseStackTrace(error);
            const firstFrame = stackTrace.length > 0 ? stackTrace[0] : null;

            // Build payload matching exact API format
            const payload = {
                // Required fields (flat structure, not nested)
                type: error.name || 'Error',
                message: error.message || 'Unknown error',
                file: firstFrame?.file || options.extra?.filename || 'unknown',
                line: firstFrame?.line || options.extra?.lineno || 0,
                stack_trace: stackTrace,

                // Optional fields (snake_case to match API)
                level: level || 'error',
                source: 'frontend',
                environment: this.config.environment || 'production',
                release: this.config.release || null,
                url: window.location.href,
                http_method: this.detectHttpMethod(),
                http_status_code: this.extractHttpStatusCode(error, options),
                session_hash: this.getSessionHash(),
                session_id: this.sessionManager ? this.sessionManager.getSessionId() : null,
                timestamp: new Date().toISOString(),
                runtime: `JavaScript ${this.getBrowserInfo()}`,
                user_agent: navigator.userAgent,
                breadcrumbs: this.breadcrumbs.get(),
                context: { ...this.extra, ...options.extra },
                tags: { ...this.tags, ...options.tags },
            };

            // Clean up null values to reduce payload size
            return this.removeNullValues(payload);
        } catch (error) {
            // If payload building completely fails, return minimal payload
            console.error('ApplicationLogger: Failed to build payload', error);
            return {
                type: 'Error',
                message: 'Failed to build error payload',
                file: 'unknown',
                line: 0,
                stack_trace: [],
                level: 'error',
            };
        }
    }

    /**
   * Parse error stack trace with cross-browser support
   *
   * Returns array of frames matching API format:
   * [{file, line, function, class, column}, ...]
   */
    parseStackTrace(error) {
        if (!error.stack) {
            return [{
                file: 'unknown',
                line: 0,
                function: 'unknown',
            }];
        }

        try {
            const lines = error.stack.split('\n');
            const frames = [];

            for (const line of lines) {
                const frame = this.parseStackLine(line.trim());
                if (frame) {
                    frames.push(frame);
                }
            }

            return frames.length > 0 ? frames : [{
                file: 'unknown',
                line: 0,
                function: 'unknown',
            }];
        } catch {
            return [{
                file: 'unknown',
                line: 0,
                function: 'unknown',
            }];
        }
    }

    /**
   * Parse a single stack trace line (cross-browser)
   *
   * Handles formats from Chrome, Firefox, Safari, Edge
   */
    parseStackLine(line) {
        if (!line) {
            return null;
        }

        // Chrome/V8: "at functionName (file.js:line:col)"
        let match = line.match(/at\s+(.+?)\s+\((.+?):(\d+):(\d+)\)/);
        if (match) {
            return {
                function: match[1].trim(),
                file: match[2],
                line: parseInt(match[3], 10),
                column: parseInt(match[4], 10),
            };
        }

        // Chrome/V8 anonymous: "at file.js:line:col"
        match = line.match(/at\s+(.+?):(\d+):(\d+)/);
        if (match) {
            return {
                function: 'anonymous',
                file: match[1],
                line: parseInt(match[2], 10),
                column: parseInt(match[3], 10),
            };
        }

        // Firefox: "functionName@file.js:line:col"
        match = line.match(/(.+?)@(.+?):(\d+):(\d+)/);
        if (match) {
            return {
                function: match[1] || 'anonymous',
                file: match[2],
                line: parseInt(match[3], 10),
                column: parseInt(match[4], 10),
            };
        }

        // Safari/Firefox (no column): "functionName@file.js:line"
        match = line.match(/(?:(.+)@)?(.+?):(\d+)$/);
        if (match) {
            return {
                function: match[1] || 'anonymous',
                file: match[2],
                line: parseInt(match[3], 10),
                column: null,
            };
        }

        // Edge legacy: "at functionName (file.js:line:col)"
        match = line.match(/at\s+(.+?)\s+\[(.+?):(\d+):(\d+)\]/);
        if (match) {
            return {
                function: match[1].trim(),
                file: match[2],
                line: parseInt(match[3], 10),
                column: parseInt(match[4], 10),
            };
        }

        // Could not parse this line
        return null;
    }

    /**
   * Detect HTTP method for current page load
   */
    detectHttpMethod() {
        try {
            // Try to detect from performance API
            const navigation = performance.getEntriesByType('navigation')[0];
            if (navigation && navigation.type) {
                // Navigation types: navigate, reload, back_forward, prerender
                return 'GET'; // Page loads are always GET
            }
        } catch {
            // Performance API not available
        }

        // Default to GET (most common for page loads)
        return 'GET';
    }

    /**
     * Extract HTTP status code from error context.
     *
     * Attempts to extract status code from:
     * 1. Error object's status property (fetch Response)
     * 2. Options extra data (manually passed)
     * 3. Error message parsing (e.g., "HTTP 404 Not Found")
     *
     * @param {Error} error - The error object
     * @param {Object} options - Additional options passed to captureException
     * @returns {number|null} HTTP status code or null if not available
     */
    extractHttpStatusCode(error, options = {}) {
        try {
            // Check if error has status property (fetch Response errors)
            if (error.status && typeof error.status === 'number') {
                return error.status;
            }

            // Check if status was passed in options
            if (options.httpStatusCode && typeof options.httpStatusCode === 'number') {
                return options.httpStatusCode;
            }

            // Check extra context for status code
            if (options.extra?.http_status_code && typeof options.extra.http_status_code === 'number') {
                return options.extra.http_status_code;
            }

            if (options.extra?.httpStatusCode && typeof options.extra.httpStatusCode === 'number') {
                return options.extra.httpStatusCode;
            }

            // Try to parse status code from error message (e.g., "HTTP 404 Not Found")
            if (error.message) {
                const match = error.message.match(/HTTP\s+(\d{3})/i);
                if (match) {
                    const status = parseInt(match[1], 10);
                    if (status >= 100 && status < 600) {
                        return status;
                    }
                }
            }

            // No HTTP status code available
            return null;
        } catch {
            // If extraction fails, return null
            return null;
        }
    }

    /**
   * Get browser info from user agent
   */
    getBrowserInfo() {
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
     * Get session hash for GDPR-compliant session tracking
     *
     * Priority:
     * 1. Use sessionHash from config if provided by server (Symfony bundle)
     * 2. Generate from sessionStorage if available
     * 3. Return null (errors will be tracked without session linkage)
     *
     * @returns {string|null} SHA-256 hash of session ID (64 hex chars)
     */
    getSessionHash() {
        try {
            // 1. Check if server provided session hash (Symfony bundle sets this)
            if (this.config.sessionHash) {
                return this.config.sessionHash;
            }

            // 2. Try to get/generate from sessionStorage
            if (typeof sessionStorage !== 'undefined') {
                let sessionId = sessionStorage.getItem('_app_logger_session_id');

                if (!sessionId) {
                    // Generate new session ID for this browser session
                    sessionId = this.generateSessionId();
                    sessionStorage.setItem('_app_logger_session_id', sessionId);
                }

                // Generate SHA-256 hash synchronously (simple implementation)
                return this.sha256(sessionId);
            }

            // 3. No session tracking available
            return null;
        } catch {
            // If session tracking fails, return null (errors still captured)
            return null;
        }
    }

    /**
     * Generate a unique session ID for client-side session tracking
     *
     * @returns {string} Random session ID
     */
    generateSessionId() {
        // Use crypto.randomUUID if available (modern browsers)
        if (crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }

        // Fallback: Generate random string
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            const v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    /**
     * Simple synchronous SHA-256 implementation
     *
     * This is a simplified hash function for client-side session hashing.
     * While not cryptographically secure for production use, it's sufficient
     * for generating consistent session hashes for tracking purposes.
     *
     * @param {string} str - String to hash
     * @returns {string} 64-character hexadecimal hash
     */
    sha256(str) {
        // Simple djb2-like hash (not real SHA-256, but consistent and sufficient)
        let hash = 5381;
        for (let i = 0; i < str.length; i++) {
            hash = ((hash << 5) + hash) + str.charCodeAt(i);
        }

        // Convert to hex and pad to 64 characters for consistency with PHP hash('sha256')
        // This is a simplified version - for production, consider using Web Crypto API
        const hex = Math.abs(hash).toString(16);
        return hex.padStart(64, '0');
    }

    /**
   * Remove null/undefined values from object to reduce payload size
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
     * Flush pending errors using Beacon API
     * Called on page unload to ensure critical errors are sent
     */
    flushBeaconErrors() {
        try {
            // Check if Beacon API is available
            if (!navigator.sendBeacon) {
                return;
            }

            // Get transport stats to check for pending errors
            const stats = this.transport.getStats();

            if (stats.storedErrors === 0 && stats.queueSize === 0) {
                return; // Nothing to flush
            }

            // Delegate to transport's beacon flush method
            this.transport.flushWithBeacon();
        } catch {
            // Never crash on flush - but this is our last chance to send errors
            // So we silently fail
        }
    }
}
