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
import { hasUserInteraction } from './util/interaction.js';
import { createLogger } from './util/logger.js';

export class ErrorDetector {
    /**
     * @param {ReplayBuffer} replayBuffer - Replay buffer instance
     * @param {SessionManager} sessionManager - Session manager instance
     * @param {Function} onErrorDetected - Callback when error detected (receives errorContext)
     * @param {Transport|null} transport - Transport layer for sending recovery sessions
     * @param {Object} [config] - Configuration options
     * @param {boolean} [config.debug=false] - Enable debug logging
     * @param {Array<string>} [config.ignoreErrors=[]] - Error messages to ignore
     */
    constructor(replayBuffer, sessionManager, onErrorDetected, transport = null, config = {}) {
        this.replayBuffer = replayBuffer;
        this.sessionManager = sessionManager;
        this.onErrorDetected = onErrorDetected;
        this.transport = transport;
        this.config = {
            debug: config.debug || false,
            ignoreErrors: config.ignoreErrors || [],
        };
        // Debug-gated internal logger (no-op in production) - JSSDK-03/04.
        this.logger = createLogger(this.config);

        // State
        this.isInstalled = false;
        // Set once uninstall() runs (replay opt-out). Distinct from `isInstalled`
        // so a never-installed detector is NOT treated as torn down
        // (BUNDLE-REPLAY-OPTOUT).
        this.uninstalled = false;
        this.recentErrors = new Set(); // Prevent duplicate captures
        this.recentErrorsCleanupInterval = null;
        this.isRecordingRecovery = false; // Prevent concurrent recovery recordings
        this.recoveryRecordingCleanup = null; // Store cleanup function

        // Statistics
        this.stats = {
            errorsDetected: 0,
            errorsIgnored: 0,
            replaysCaptured: 0,
            duplicatesPrevented: 0,
            recoveryRecordingsStarted: 0,
            recoveryRecordingsCancelled: 0,
        };

        if (this.config.debug) {
            this.logger.warn('ErrorDetector initialized');
        }
    }

    /**
     * Install error detection handlers
     */
    install() {
        if (this.isInstalled) {
            this.logger.warn('ErrorDetector: Already installed');
            return;
        }

        try {
            // Note: We intentionally do NOT add new handlers here
            // The Client class already handles window.onerror and unhandledrejection
            // This detector will be called FROM the Client class when errors occur
            // This design prevents double-handling of errors

            // Replay-capture dedup window. Deliberately LONGER (60s) than the
            // Transport network dedup window (5s): re-capturing a full session
            // replay for the same error within a page view adds little value and
            // is expensive, whereas Transport only needs to collapse tight bursts
            // of an identical payload. See transport.js deduplicationWindow.
            this.recentErrorsCleanupInterval = setInterval(() => {
                this.recentErrors.clear();
            }, 60000);

            this.isInstalled = true;
            this.uninstalled = false;

            if (this.config.debug) {
                this.logger.warn('ErrorDetector: Installed');
            }
        } catch (error) {
            this.logger.error('ErrorDetector: Failed to install:', error);
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

            // Opt-out must fully stop replay (BUNDLE-REPLAY-OPTOUT): abort any
            // in-flight recovery recording so its pending timer/listeners are
            // cleared and it can never complete and send AFTER opt-out. The
            // `uninstalled` guard below is the belt to this braces, covering any
            // recovery that was already past its timer when uninstall() ran.
            if (this.recoveryRecordingCleanup) {
                this.recoveryRecordingCleanup();
            }
            this.isRecordingRecovery = false;

            this.isInstalled = false;
            this.uninstalled = true;

            if (this.config.debug) {
                this.logger.warn('ErrorDetector: Uninstalled');
            }
        } catch (error) {
            this.logger.error('ErrorDetector: Failed to uninstall:', error);
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
            // Opt-out guard (BUNDLE-REPLAY-OPTOUT): once the detector has been
            // torn down via uninstall() (replay disabled), it must NOT capture
            // or attach any replay, even if a stale pointer still routes an
            // error here. `uninstalled` is set only by uninstall(), so a
            // never-installed detector (e.g. unit tests calling handleError
            // directly) is unaffected.
            if (this.uninstalled) {
                return null;
            }

            this.stats.errorsDetected++;

            // Check if error should be ignored
            if (this.shouldIgnoreError(error)) {
                this.stats.errorsIgnored++;
                if (this.config.debug) {
                    this.logger.warn('ErrorDetector: Error ignored:', error.message);
                }
                return null;
            }

            // Generate error fingerprint for deduplication
            const errorFingerprint = this.generateErrorFingerprint(error);

            // Check if we recently captured this error
            if (this.recentErrors.has(errorFingerprint)) {
                this.stats.duplicatesPrevented++;
                if (this.config.debug) {
                    this.logger.warn('ErrorDetector: Duplicate error prevented');
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
                this.logger.warn('ErrorDetector: Replay captured', {
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
            this.logger.error('ErrorDetector: Failed to handle error:', handlingError);
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
     * Start recording recovery session after error (Phase 2 of two-phase replay)
     *
     * This method is called AFTER the error + pre-error replay has been sent.
     * It continues recording user actions for the configured duration/clicks,
     * then sends the recovery session as a separate request. It is defensive
     * about concurrent recordings, missing dependencies and page unload, and
     * cleans up all intervals/timers/listeners on completion.
     *
     * @param {Error} error - The error object
     * @returns {Promise<void>}
     */
    async startRecoveryRecording(error) {
        try {
            // Opt-out guard (BUNDLE-REPLAY-OPTOUT): once uninstall() has run
            // (replay disabled), never begin a recovery recording.
            if (this.uninstalled) {
                return;
            }

            // A new error supersedes any in-flight recovery recording.
            if (this.isRecordingRecovery) {
                if (this.config.debug) {
                    this.logger.warn('ErrorDetector: Already recording recovery, cleaning up previous recording');
                }

                if (this.recoveryRecordingCleanup) {
                    this.recoveryRecordingCleanup();
                }

                this.stats.recoveryRecordingsCancelled++;
            }

            if (!this.replayBuffer || !this.sessionManager) {
                this.logger.error('ErrorDetector: Cannot start recovery recording - missing dependencies');
                return;
            }

            if (!error || typeof error !== 'object') {
                this.logger.error('ErrorDetector: Invalid error object for recovery recording');
                return;
            }

            this.isRecordingRecovery = true;
            this.stats.recoveryRecordingsStarted++;

            if (this.config.debug) {
                this.logger.warn('ErrorDetector: Starting recovery recording (phase 2)');
            }

            const errorContext = {
                errorId: null, // Could be set from backend response if needed
                message: error.message || 'Unknown error',
                type: error.name || 'Error',
                timestamp: Date.now(),
                url: window.location.href,
            };

            if (typeof this.replayBuffer.startRecordingAfterError === 'function') {
                this.replayBuffer.startRecordingAfterError(errorContext);
            } else {
                this.logger.error('ErrorDetector: Buffer missing startRecordingAfterError method');
                this.isRecordingRecovery = false;
                return;
            }

            // Wait for recording to complete (time limit or click limit)
            return new Promise((resolve) => {
                let checkCompleteInterval = null;
                let safetyTimeout = null;
                let unloadHandler = null;
                let visibilityHandler = null;

                // Single cleanup path: clears the completion interval, the
                // absolute safety timeout (previously leaked - M16) and the
                // unload/visibility listeners, then resets recovery state.
                const cleanup = () => {
                    if (checkCompleteInterval) {
                        clearInterval(checkCompleteInterval);
                        checkCompleteInterval = null;
                    }

                    if (safetyTimeout) {
                        clearTimeout(safetyTimeout);
                        safetyTimeout = null;
                    }

                    if (unloadHandler) {
                        window.removeEventListener('beforeunload', unloadHandler);
                        unloadHandler = null;
                    }

                    if (visibilityHandler) {
                        document.removeEventListener('visibilitychange', visibilityHandler);
                        visibilityHandler = null;
                    }

                    this.isRecordingRecovery = false;
                    this.recoveryRecordingCleanup = null;
                };

                // Store cleanup function for external cancellation
                this.recoveryRecordingCleanup = cleanup;

                const finishRecording = (reason = 'unknown') => {
                    if (this.config.debug) {
                        this.logger.warn(`ErrorDetector: Finishing recovery recording (reason: ${reason})`);
                    }

                    // Clean up listeners/timers FIRST.
                    cleanup();

                    // Opt-out guard (BUNDLE-REPLAY-OPTOUT): if replay was
                    // disabled while this recording was in flight, abort here
                    // BEFORE collecting or sending — opt-out must fully stop
                    // replay, even for a recording that already passed its timer.
                    if (this.uninstalled) {
                        if (this.config.debug) {
                            this.logger.warn('ErrorDetector: Recovery recording aborted (replay opted out)');
                        }
                        resolve();
                        return;
                    }

                    // Collect recovery events if the buffer still exposes them.
                    let recoveryEvents = [];
                    if (this.replayBuffer && typeof this.replayBuffer.getEventsByPhase === 'function') {
                        recoveryEvents = this.replayBuffer.getEventsByPhase('after_error') || [];
                    }

                    if (recoveryEvents.length > 0) {
                        // Use sendBeacon for page unload (more reliable)
                        const useBeacon = reason === 'page-unload' || reason === 'page-hidden';

                        // Send recovery session separately
                        this.sendRecoverySession(errorContext, recoveryEvents, useBeacon);
                    } else if (this.config.debug) {
                        this.logger.warn('ErrorDetector: No recovery events captured');
                    }

                    resolve();
                };

                // Poll once per second for the buffer's stop condition.
                checkCompleteInterval = setInterval(() => {
                    try {
                        if (!this.replayBuffer || typeof this.replayBuffer.shouldStopRecording !== 'function') {
                            finishRecording('buffer-unavailable');
                            return;
                        }

                        if (this.replayBuffer.shouldStopRecording()) {
                            finishRecording('limit-reached');
                        }
                    } catch (error) {
                        this.logger.error('ErrorDetector: Error in recovery check interval', error);
                        finishRecording('check-error');
                    }
                }, 1000);

                // Finish (with beacon) when the page goes away (desktop + mobile).
                unloadHandler = () => {
                    finishRecording('page-unload');
                };
                window.addEventListener('beforeunload', unloadHandler, { once: true });

                visibilityHandler = () => {
                    if (document.visibilityState === 'hidden') {
                        finishRecording('page-hidden');
                    }
                };
                document.addEventListener('visibilitychange', visibilityHandler, { once: true });

                // Absolute-maximum safety timeout. Its id is now captured so
                // cleanup() clears it (previously leaked - M16).
                safetyTimeout = setTimeout(() => {
                    if (this.isRecordingRecovery) {
                        if (this.config.debug) {
                            this.logger.warn('ErrorDetector: Recovery recording safety timeout (2 minutes)');
                        }
                        finishRecording('safety-timeout');
                    }
                }, 120000);
            });
        } catch (error) {
            this.logger.error('ErrorDetector: Failed to start recovery recording', error);
            this.isRecordingRecovery = false;
            this.recoveryRecordingCleanup = null;
        }
    }

    /**
     * Send recovery session as separate request (Phase 2)
     *
     * @param {Object} errorContext - Error context information
     * @param {Array} events - Recovery events (after error)
     * @param {boolean} [useBeacon=false] - Use sendBeacon API for reliable unload transmission
     */
    sendRecoverySession(errorContext, events, useBeacon = false) {
        try {
            // Opt-out guard (BUNDLE-REPLAY-OPTOUT): never send/persist a recovery
            // session once replay has been disabled via uninstall().
            if (this.uninstalled) {
                return;
            }

            if (events.length === 0) {
                return; // No recovery data
            }

            // Only send when the recovery window contains a user interaction.
            if (!hasUserInteraction(events)) {
                if (this.config.debug) {
                    this.logger.warn('ErrorDetector: No click events in recovery session, skipping send', {
                        totalEvents: events.length,
                    });
                }
                return;
            }

            // Format payload to match backend API expectations
            const recoveryPayload = {
                sessionId: this.sessionManager.getSessionId(),
                events: events,
                capturedAt: new Date().toISOString(),
                url: window.location.href,
            };

            // Send via transport if available
            if (this.transport && typeof this.transport.sendRecoverySession === 'function') {
                this.transport.sendRecoverySession(recoveryPayload, useBeacon).catch(error => {
                    this.logger.error('ErrorDetector: Failed to send recovery session via transport', error);
                });

                if (this.config.debug) {
                    this.logger.warn('ErrorDetector: Recovery session sent via transport', {
                        eventCount: events.length,
                        sessionId: recoveryPayload.sessionId,
                        method: useBeacon ? 'sendBeacon' : 'fetch',
                    });
                }
            } else {
                // Fallback: call onErrorDetected callback
                if (this.onErrorDetected) {
                    this.onErrorDetected(errorContext, events, { recovery: true });
                }

                if (this.config.debug) {
                    this.logger.warn('ErrorDetector: Recovery session sent via callback', {
                        eventCount: events.length,
                    });
                }
            }
        } catch (error) {
            this.logger.error('ErrorDetector: Failed to send recovery session', error);
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
