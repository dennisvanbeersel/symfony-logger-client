/**
 * Application Logger JavaScript SDK
 *
 * ERROR-TRIGGERED SESSION REPLAY:
 * - Only captures replay when errors occur (not continuously)
 * - Buffers N seconds/clicks before and after error
 * - Cross-page session continuity via localStorage
 * - Privacy-first (no PII in DOM snapshots)
 *
 * FEATURES:
 * - JavaScript error capture and reporting
 * - Session replay on error (configurable buffer size)
 * - Breadcrumb tracking for debugging context
 * - Click heatmap for user behavior analysis
 *
 * @module ApplicationLogger
 */

import { Client } from './client.js';
import { BreadcrumbCollector } from './breadcrumbs.js';
import { Transport } from './transport.js';
import { ClickTracker } from './click-tracker.js';
import { ReplayBuffer } from './replay-buffer.js';
import { ErrorDetector } from './error-detector.js';
import { SessionManager } from './session-manager.js';
import { StorageManager } from './storage-manager.js';

/**
 * Main ApplicationLogger class
 */
class ApplicationLogger {
    /**
     * @param {Object} config Configuration options
     * @param {string} config.dsn Data Source Name (project endpoint URL)
     * @param {string} config.apiKey API Key for authentication
     * @param {string} [config.release] Application version/release
     * @param {string} [config.environment] Environment (production, staging, etc.)
     * @param {boolean} [config.debug=false] Enable debug logging
     * @param {string[]} [config.scrubFields] Additional fields to scrub
     *
     * Session Replay Configuration (error-triggered only):
     * @param {boolean} [config.sessionReplayEnabled=true] Enable session replay on errors
     * @param {number} [config.bufferBeforeErrorSeconds=30] Seconds to buffer before error (max 60)
     * @param {number} [config.bufferBeforeErrorClicks=10] Clicks to buffer before error (max 15)
     * @param {number} [config.bufferAfterErrorSeconds=30] Seconds to buffer after error (max 60)
     * @param {number} [config.bufferAfterErrorClicks=10] Clicks to buffer after error (max 15)
     * @param {number} [config.snapshotThrottleMs=1000] DOM snapshot throttle (min 500ms)
     * @param {number} [config.maxSnapshotSize=1048576] Max snapshot size (default 1MB)
     * @param {number} [config.sessionTimeoutMinutes=30] Session timeout (max 120 min)
     * @param {number} [config.maxBufferSizeMB=5] Max localStorage buffer size (max 20MB)
     * @param {boolean} [config.exposeApi=true] Expose control API for developers
     */
    constructor(config) {
        // Validate required configuration
        if (!config || !config.dsn) {
            throw new Error('ApplicationLogger: DSN is required. Expected format: https://host/project-id');
        }

        if (!config.apiKey) {
            throw new Error('ApplicationLogger: API Key is required for authentication');
        }

        this.config = {
            // Core config
            debug: false,
            scrubFields: ['password', 'token', 'api_key', 'secret'],

            // Session replay config (error-triggered only)
            sessionReplayEnabled: true,
            bufferBeforeErrorSeconds: 30,
            bufferBeforeErrorClicks: 10,
            bufferAfterErrorSeconds: 30,
            bufferAfterErrorClicks: 10,
            snapshotThrottleMs: 1000,
            maxSnapshotSize: 1048576, // 1MB
            sessionTimeoutMinutes: 30,
            maxBufferSizeMB: 5,
            exposeApi: true,

            // Merge user config
            ...config,
        };

        // Initialize core components
        this.transport = new Transport(this.config);
        this.breadcrumbs = new BreadcrumbCollector();

        // Initialize session replay components (if enabled)
        this.sessionManager = null;
        this.replayBuffer = null;
        this.storageManager = null;
        this.errorDetector = null;
        this.heatmap = null;

        if (this.config.sessionReplayEnabled) {
            this.initializeSessionReplay();
        }

        // Initialize client (with optional errorDetector)
        this.client = new Client(
            this.config,
            this.transport,
            this.breadcrumbs,
            this.errorDetector,
        );

        this.initialized = false;
    }

    /**
     * Initialize session replay components
     */
    initializeSessionReplay() {
        try {
            // Session manager (cross-page sessions)
            this.sessionManager = new SessionManager({
                sessionTimeoutMinutes: this.config.sessionTimeoutMinutes,
                debug: this.config.debug,
            });

            // Replay buffer (circular buffer for events)
            this.replayBuffer = new ReplayBuffer({
                bufferBeforeErrorSeconds: this.config.bufferBeforeErrorSeconds,
                bufferBeforeErrorClicks: this.config.bufferBeforeErrorClicks,
                bufferAfterErrorSeconds: this.config.bufferAfterErrorSeconds,
                bufferAfterErrorClicks: this.config.bufferAfterErrorClicks,
                maxBufferSizeMB: this.config.maxBufferSizeMB,
                debug: this.config.debug,
            });

            // Storage manager (localStorage persistence)
            this.storageManager = new StorageManager({
                maxBufferSizeMB: this.config.maxBufferSizeMB,
                debug: this.config.debug,
            });

            // Error detector (triggers replay on error)
            this.errorDetector = new ErrorDetector(
                this.replayBuffer,
                this.sessionManager,
                this.handleReplayCapture.bind(this),
                {
                    debug: this.config.debug,
                    ignoreErrors: [],
                },
            );

            // Click tracker (click recording to buffer for session replay)
            this.heatmap = new ClickTracker(
                this.replayBuffer,
                this.sessionManager,
                this.config,
            );

            // Load existing buffer from localStorage (cross-page continuity)
            const savedBuffer = this.storageManager.load();
            if (savedBuffer) {
                this.replayBuffer.deserialize(savedBuffer);
                if (this.config.debug) {
                    console.warn('ApplicationLogger: Loaded replay buffer from localStorage', {
                        events: savedBuffer.buffer?.length || 0,
                    });
                }
            }

            if (this.config.debug) {
                console.warn('ApplicationLogger: Session replay initialized');
            }
        } catch (error) {
            console.error('ApplicationLogger: Failed to initialize session replay', error);
            // Disable session replay on initialization failure
            this.config.sessionReplayEnabled = false;
        }
    }

    /**
     * Handle replay capture when error is detected
     *
     * Called by ErrorDetector after buffering is complete.
     * Saves buffer to localStorage for cross-page continuity.
     *
     * Note: Replay data is sent WITH the error payload in client.captureException(),
     * not as a separate request. This callback is just for localStorage persistence.
     *
     * @param {Object} errorContext - Error context
     * @param {Array} events - Buffered events (before + after error)
     * @param {Object} errorPayload - Original error payload sent to backend (unused here)
     */
    // eslint-disable-next-line no-unused-vars
    async handleReplayCapture(errorContext, events, errorPayload) {
        try {
            if (this.config.debug) {
                console.warn('ApplicationLogger: Replay captured for error', {
                    errorMessage: errorContext.message,
                    eventCount: events.length,
                    sessionId: this.sessionManager.getSessionId(),
                });
            }

            // Save buffer to localStorage for cross-page continuity
            const serialized = this.replayBuffer.serialize();
            this.storageManager.save(serialized);
        } catch (error) {
            console.error('ApplicationLogger: Failed to save replay buffer', error);
        }
    }

    /**
     * Initialize the SDK and start capturing errors
     */
    init() {
        if (this.initialized) {
            console.warn('ApplicationLogger already initialized');
            return;
        }

        // Install error capture
        this.client.install();

        // Install session replay (if enabled)
        if (this.config.sessionReplayEnabled && this.heatmap) {
            this.heatmap.install();
            this.errorDetector.install();

            // Save buffer to localStorage on page unload
            window.addEventListener('beforeunload', () => {
                this.saveBufferToStorage();
            });

            // Also save on visibility change (mobile)
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.saveBufferToStorage();
                }
            });

            if (this.config.debug) {
                console.warn('ApplicationLogger: Session replay enabled (error-triggered)');
            }
        }

        this.initialized = true;

        if (this.config.debug) {
            console.warn('ApplicationLogger initialized', {
                environment: this.config.environment,
                release: this.config.release,
                sessionReplayEnabled: this.config.sessionReplayEnabled,
                sessionId: this.sessionManager?.getSessionId(),
            });
        }
    }

    /**
     * Save buffer to localStorage for cross-page continuity
     */
    saveBufferToStorage() {
        try {
            if (this.replayBuffer && this.storageManager) {
                const serialized = this.replayBuffer.serialize();
                this.storageManager.save(serialized);

                if (this.config.debug) {
                    console.warn('ApplicationLogger: Buffer saved to localStorage');
                }
            }
        } catch (error) {
            console.error('ApplicationLogger: Failed to save buffer', error);
        }
    }

    /**
   * Manually capture an exception
   *
   * @param {Error} error The error to capture
   * @param {Object} [options] Additional options
   * @param {Object} [options.tags] Key-value tags
   * @param {Object} [options.extra] Additional context data
   */
    captureException(error, options = {}) {
        this.client.captureException(error, options);
    }

    /**
   * Manually capture a message
   *
   * @param {string} message The message to capture
   * @param {string} [level='info'] Log level
   * @param {Object} [options] Additional options
   */
    captureMessage(message, level = 'info', options = {}) {
        this.client.captureMessage(message, level, options);
    }

    /**
   * Add a breadcrumb
   *
   * @param {Object} breadcrumb Breadcrumb data
   * @param {string} breadcrumb.type Breadcrumb type (navigation, http, user, etc.)
   * @param {string} breadcrumb.category Category
   * @param {string} breadcrumb.message Message
   * @param {Object} [breadcrumb.data] Additional data
   * @param {string} [breadcrumb.level='info'] Log level
   */
    addBreadcrumb(breadcrumb) {
        this.breadcrumbs.add(breadcrumb);
    }

    /**
   * Set user context
   *
   * @param {Object} user User data
   * @param {string} [user.id] User ID
   * @param {string} [user.email] User email
   * @param {string} [user.username] Username
   */
    setUser(user) {
        this.client.setUser(user);
    }

    /**
   * Set tags
   *
   * @param {Object} tags Key-value tags
   */
    setTags(tags) {
        this.client.setTags(tags);
    }

    /**
     * Set extra context
     *
     * @param {Object} extra Key-value extra data
     */
    setExtra(extra) {
        this.client.setExtra(extra);
    }

    /**
     * Session Replay API - exposed for developer control
     *
     * Allows developers to let users control session replay:
     * - window.ApplicationLogger.sessionReplay.enable()
     * - window.ApplicationLogger.sessionReplay.disable()
     * - window.ApplicationLogger.sessionReplay.isEnabled()
     */
    get sessionReplay() {
        if (!this.config.exposeApi) {
            return null;
        }

        return {
            /**
             * Enable session replay recording
             */
            enable: () => {
                if (!this.config.sessionReplayEnabled) {
                    this.config.sessionReplayEnabled = true;

                    // Re-initialize if not already initialized
                    if (!this.heatmap) {
                        this.initializeSessionReplay();
                        if (this.initialized && this.heatmap) {
                            this.heatmap.install();
                            this.errorDetector.install();
                        }
                    }

                    if (this.config.debug) {
                        console.warn('ApplicationLogger: Session replay enabled');
                    }
                }
            },

            /**
             * Disable session replay recording
             */
            disable: () => {
                if (this.config.sessionReplayEnabled) {
                    this.config.sessionReplayEnabled = false;

                    // Clean up and save buffer
                    if (this.heatmap) {
                        this.heatmap.cleanup();
                    }
                    if (this.errorDetector) {
                        this.errorDetector.uninstall();
                    }
                    this.saveBufferToStorage();

                    if (this.config.debug) {
                        console.warn('ApplicationLogger: Session replay disabled');
                    }
                }
            },

            /**
             * Check if session replay is enabled
             * @returns {boolean}
             */
            isEnabled: () => {
                return this.config.sessionReplayEnabled;
            },

            /**
             * Get session replay statistics for debugging
             * @returns {Object}
             */
            getStats: () => {
                if (!this.config.sessionReplayEnabled) {
                    return { enabled: false };
                }

                return {
                    enabled: true,
                    sessionId: this.sessionManager?.getSessionId(),
                    sessionAge: this.sessionManager?.getSessionAge(),
                    bufferStats: this.replayBuffer?.getStats(),
                    storageStats: this.storageManager?.getStats(),
                    domCaptureStats: this.heatmap?.getDOMCaptureStats(),
                    debounceStats: this.heatmap?.getDebounceStats(),
                    errorDetectorStats: this.errorDetector?.getStats(),
                };
            },
        };
    }
}

// Export for ES modules
export default ApplicationLogger;

// Export for UMD (window.ApplicationLogger)
if (typeof window !== 'undefined') {
    window.ApplicationLogger = ApplicationLogger;
}
