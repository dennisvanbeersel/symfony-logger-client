/**
 * Error Detector - Triggers Session Replay on Errors
 *
 * Hooks into global error handlers and triggers replay buffer capture
 * when errors are detected. Coordinates with ReplayBuffer to send
 * buffered events along with error context.
 *
 * Features:
 * - Hooks window.onerror and unhandledrejection
 * - Triggers replay capture on error
 * - Links replay data to error ID
 * - Prevents duplicate captures
 * - Configurable error filtering
 */
export class ErrorDetector {
    /**
     * @param {ReplayBuffer} replayBuffer - Replay buffer instance
     * @param {SessionManager} sessionManager - Session manager instance
     * @param {Function} onErrorDetected - Callback when error detected (receives errorContext)
     * @param {Object} [config] - Configuration options
     * @param {boolean} [config.debug=false] - Enable debug logging
     * @param {Array<string>} [config.ignoreErrors=[]] - Error messages to ignore
     */
    constructor(replayBuffer, sessionManager, onErrorDetected, config = {}) {
        this.replayBuffer = replayBuffer;
        this.sessionManager = sessionManager;
        this.onErrorDetected = onErrorDetected;
        this.config = {
            debug: config.debug || false,
            ignoreErrors: config.ignoreErrors || [],
        };

        // State
        this.isInstalled = false;
        this.recentErrors = new Set(); // Prevent duplicate captures
        this.recentErrorsCleanupInterval = null;

        // Statistics
        this.stats = {
            errorsDetected: 0,
            errorsIgnored: 0,
            replaysCaptured: 0,
            duplicatesPrevented: 0,
        };

        if (this.config.debug) {
            console.warn('ErrorDetector initialized');
        }
    }

    /**
     * Install error detection handlers
     */
    install() {
        if (this.isInstalled) {
            console.warn('ErrorDetector: Already installed');
            return;
        }

        try {
            // Note: We intentionally do NOT add new handlers here
            // The Client class already handles window.onerror and unhandledrejection
            // This detector will be called FROM the Client class when errors occur
            // This design prevents double-handling of errors

            // Set up cleanup for recent errors (prevent duplicates)
            this.recentErrorsCleanupInterval = setInterval(() => {
                this.recentErrors.clear();
            }, 60000); // Clear every 60 seconds

            this.isInstalled = true;

            if (this.config.debug) {
                console.warn('ErrorDetector: Installed');
            }
        } catch (error) {
            console.error('ErrorDetector: Failed to install:', error);
        }
    }

    /**
     * Uninstall error detection handlers
     */
    uninstall() {
        try {
            if (this.recentErrorsCleanupInterval) {
                clearInterval(this.recentErrorsCleanupInterval);
                this.recentErrorsCleanupInterval = null;
            }

            this.isInstalled = false;

            if (this.config.debug) {
                console.warn('ErrorDetector: Uninstalled');
            }
        } catch (error) {
            console.error('ErrorDetector: Failed to uninstall:', error);
        }
    }

    /**
     * Handle detected error (called by Client)
     *
     * This is the main entry point called by the Client class when an error occurs.
     *
     * @param {Error} error - The error object
     * @param {Object} errorPayload - The error payload being sent to backend
     * @returns {Promise<Object|null>} Error context with replay data, or null
     */
    async handleError(error, errorPayload) {
        try {
            this.stats.errorsDetected++;

            // Check if error should be ignored
            if (this.shouldIgnoreError(error)) {
                this.stats.errorsIgnored++;
                if (this.config.debug) {
                    console.warn('ErrorDetector: Error ignored:', error.message);
                }
                return null;
            }

            // Generate error fingerprint for deduplication
            const errorFingerprint = this.generateErrorFingerprint(error);

            // Check if we recently captured this error
            if (this.recentErrors.has(errorFingerprint)) {
                this.stats.duplicatesPrevented++;
                if (this.config.debug) {
                    console.warn('ErrorDetector: Duplicate error prevented');
                }
                return null;
            }

            // Mark error as recently seen
            this.recentErrors.add(errorFingerprint);

            // Create error context
            const errorContext = {
                errorId: null, // Will be set by backend response
                message: error.message || 'Unknown error',
                type: error.name || 'Error',
                timestamp: Date.now(),
                stack: error.stack || '',
                url: window.location.href,
            };

            // Start recording after error (continue for N seconds/clicks)
            this.replayBuffer.startRecordingAfterError(errorContext);

            // Get buffered events
            const events = this.replayBuffer.getEvents();

            if (this.config.debug) {
                console.warn('ErrorDetector: Replay captured', {
                    errorMessage: errorContext.message,
                    eventCount: events.length,
                    beforeError: this.replayBuffer.getEventsByPhase('before_error').length,
                    afterError: this.replayBuffer.getEventsByPhase('after_error').length,
                });
            }

            this.stats.replaysCaptured++;

            // Call the callback with error context and replay data
            if (this.onErrorDetected) {
                await this.onErrorDetected(errorContext, events, errorPayload);
            }

            return {
                errorContext,
                events,
                sessionId: this.sessionManager.getSessionId(),
                stats: this.replayBuffer.getStats(),
            };
        } catch (handlingError) {
            console.error('ErrorDetector: Failed to handle error:', handlingError);
            return null;
        }
    }

    /**
     * Check if error should be ignored
     *
     * @param {Error} error
     * @returns {boolean}
     */
    shouldIgnoreError(error) {
        try {
            if (!error || !error.message) {
                return false;
            }

            const message = error.message.toLowerCase();

            // Check configured ignore patterns
            for (const pattern of this.config.ignoreErrors) {
                if (message.includes(pattern.toLowerCase())) {
                    return true;
                }
            }

            // Ignore common non-actionable errors
            const commonIgnorePatterns = [
                'script error', // Cross-origin script errors
                'network error', // Network failures (not code bugs)
                'loading chunk', // Webpack/bundler chunk loading issues
                'dynamically imported module', // Dynamic import failures
            ];

            for (const pattern of commonIgnorePatterns) {
                if (message.includes(pattern)) {
                    return true;
                }
            }

            return false;
        } catch {
            return false;
        }
    }

    /**
     * Generate error fingerprint for deduplication
     *
     * @param {Error} error
     * @returns {string}
     */
    generateErrorFingerprint(error) {
        try {
            const message = error.message || '';
            const stack = error.stack || '';

            // Extract first line of stack (most specific)
            const stackFirstLine = stack.split('\n')[1] || '';

            // Combine message + first stack line for fingerprint
            return `${message}:${stackFirstLine}`;
        } catch {
            return `${Date.now()}:${Math.random()}`;
        }
    }

    /**
     * Get error detection statistics
     *
     * @returns {Object}
     */
    getStats() {
        return {
            ...this.stats,
            isInstalled: this.isInstalled,
            recentErrorsCount: this.recentErrors.size,
        };
    }

    /**
     * Enable/disable error detection
     *
     * @param {boolean} enabled
     */
    setEnabled(enabled) {
        if (enabled && !this.isInstalled) {
            this.install();
        } else if (!enabled && this.isInstalled) {
            this.uninstall();
        }
    }

    /**
     * Check if error detection is enabled
     *
     * @returns {boolean}
     */
    isEnabled() {
        return this.isInstalled;
    }
}
