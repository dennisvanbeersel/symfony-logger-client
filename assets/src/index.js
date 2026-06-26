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
import { DEFAULT_SCRUB_FIELDS } from './scrub-fields.js';
import { createLogger, setSharedLoggerConfig } from './util/logger.js';

/**
 * Main ApplicationLogger class
 */
class ApplicationLogger {
    /**
     * @param {Object} config Configuration options
     * @param {string} config.dsn Data Source Name (project endpoint URL)
     * @param {string} config.publishableKey Publishable key (pk_…) for browser ingest authentication
     * @param {string} [config.release] Application version/release
     * @param {string} [config.environment] Environment (production, staging, etc.)
     * @param {boolean} [config.debug=false] Enable debug logging
     * @param {string[]} [config.scrubFields] Additional fields to scrub
     *
     * Session Replay Configuration (error-triggered only):
     * @param {boolean} [config.sessionReplayEnabled=false] Enable session replay on
     *   errors. OPT-IN as of JSSDK-02: disabled by default so the default install
     *   stays lean (no replay buffer/DOM-serializer/click-tracker code paths run,
     *   and NO replay listeners/timers are added to the host page). Set to `true`
     *   to enable error-triggered session replay, or call
     *   `window.ApplicationLogger.sessionReplay.enable()` at runtime.
     * @param {number} [config.bufferBeforeErrorSeconds=30] Seconds to buffer before error (max 60)
     * @param {number} [config.bufferBeforeErrorClicks=10] Clicks to buffer before error (max 15)
     * @param {number} [config.bufferAfterErrorSeconds=30] Seconds to buffer after error (max 60)
     * @param {number} [config.bufferAfterErrorClicks=10] Clicks to buffer after error (max 15)
     * @param {number} [config.snapshotThrottleMs=1000] DOM snapshot throttle (min 500ms)
     * @param {number} [config.maxSnapshotSize=1048576] Max snapshot size (default 1MB)
     * @param {number} [config.sessionTimeoutMinutes=30] Session timeout (max 120 min)
     * @param {number} [config.maxBufferSizeMB=5] Max localStorage buffer size (max 20MB)
     * @param {boolean} [config.exposeApi=true] Expose control API for developers
     *
     * Resilience Configuration (transport layer):
     * @param {number} [config.circuitBreakerFailureThreshold=5] Failures before circuit opens.
     *   5 is retained for the publishable-key/js-errors route: each opaque CORS/network
     *   fast-fail (transport.js MF-5) records exactly one breaker failure, so a
     *   persistently mis-scoped key opens the circuit after 5 attempts and the host
     *   page stops hammering a route that will never accept it — while a handful of
     *   transient blips never trip it.
     * @param {number} [config.circuitBreakerTimeoutMs=60000] Circuit breaker timeout (ms)
     * @param {number} [config.storageQueueMaxSize=50] Max errors in offline queue
     * @param {number} [config.storageQueueMaxAgeMs=86400000] Max age for queued errors (24h)
     * @param {number} [config.rateLimiterMaxTokens=10] Max errors per minute
     * @param {number} [config.rateLimiterRefillRate=0.167] Token refill rate (~10/min)
     * @param {number} [config.deduplicationWindowMs=5000] Duplicate detection window (ms)
     */
    constructor(config) {
        // Validate required configuration
        if (!config || !config.dsn) {
            throw new Error('ApplicationLogger: DSN is required. Expected format: https://host/project-id');
        }

        // Spec 2026-06-25 §6.2: the browser SDK authenticates with the world-readable
        // publishable key (pk_…), never the server secret. The secret no longer
        // reaches buildConfig()/ScriptRenderer, so config.apiKey is gone entirely.
        if (!config.publishableKey) {
            throw new Error('ApplicationLogger: Publishable Key is required for authentication');
        }

        this.config = {
            // Core config
            debug: false,
            // Canonical scrub list (single source of truth, see scrub-fields.js).
            // Spread into a fresh array so callers cannot mutate the frozen export.
            scrubFields: [...DEFAULT_SCRUB_FIELDS],

            // Session replay config (error-triggered only).
            // JSSDK-02: OPT-IN. Default off so the lean error-tracking install adds
            // no replay listeners/timers and never activates the heavier replay
            // code paths. Enable via config or the runtime sessionReplay.enable() API.
            sessionReplayEnabled: false,
            bufferBeforeErrorSeconds: 30,
            bufferBeforeErrorClicks: 10,
            bufferAfterErrorSeconds: 30,
            bufferAfterErrorClicks: 10,
            snapshotThrottleMs: 1000,
            maxSnapshotSize: 1048576, // 1MB
            sessionTimeoutMinutes: 30,
            maxBufferSizeMB: 5,
            exposeApi: true,

            // Resilience config (transport layer)
            circuitBreakerFailureThreshold: 5, // see ctor JSDoc: tuned for the MF-5 CORS fast-fail
            circuitBreakerTimeoutMs: 60000, // 60 seconds
            storageQueueMaxSize: 50,
            storageQueueMaxAgeMs: 86400000, // 24 hours
            rateLimiterMaxTokens: 10,
            rateLimiterRefillRate: 0.167, // ~10 tokens per minute
            deduplicationWindowMs: 5000, // 5 seconds

            // Merge user config
            ...config,
        };

        // Debug-gated internal logger (no-op in production) - JSSDK-03/04.
        this.logger = createLogger(this.config);
        // Point the shared logger (used by config-less modules such as
        // StorageQueue) at this instance's debug flag, so their diagnostics are
        // gated by the same setting (JSSDK-03).
        setSharedLoggerConfig(this.config);

        // Initialize core components
        this.transport = new Transport(this.config);

        // Initialize breadcrumbs with error capture callback
        // This enables zero-config error tracking: console.error(err) automatically captures
        this.breadcrumbs = new BreadcrumbCollector(
            50, // maxBreadcrumbs
            (error, options) => {
                this.logger.warn('Auto-capturing error from console.error()', error);
                this.captureException(error, options);
            }, // errorCaptureCallback
        );

        // Initialize session replay components (if enabled)
        this.sessionManager = null;
        this.replayBuffer = null;
        this.storageManager = null;
        this.errorDetector = null;
        this.heatmap = null;

        if (this.config.sessionReplayEnabled) {
            this.initializeSessionReplay();
        }

        // Initialize client (with optional errorDetector and sessionManager)
        this.client = new Client(
            this.config,
            this.transport,
            this.breadcrumbs,
            this.errorDetector,
            this.sessionManager,
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
                this.transport, // Pass transport for recovery session sending
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
                this.logger.warn('Loaded replay buffer from localStorage', {
                    events: savedBuffer.buffer?.length || 0,
                });
            }

            this.logger.warn('Session replay initialized');
        } catch (error) {
            this.logger.error('Failed to initialize session replay', error);
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
            this.logger.warn('Replay captured for error', {
                errorMessage: errorContext.message,
                eventCount: events.length,
                sessionId: this.sessionManager.getSessionId(),
            });

            // Save buffer to localStorage for cross-page continuity
            const serialized = this.replayBuffer.serialize();
            this.storageManager.save(serialized);
        } catch (error) {
            this.logger.error('Failed to save replay buffer', error);
        }
    }

    /**
     * Initialize the SDK and start capturing errors
     *
     * IMPORTANT: Initialization order matters for reliability:
     * 1. Breadcrumbs install first (wraps console/fetch before errors occur)
     * 2. Session replay components (heatmap, error detector)
     * 3. Error handlers install last (processes buffered errors, then goes live)
     */
    init() {
        if (this.initialized) {
            this.logger.warn('Already initialized');
            return;
        }

        // 1. Install breadcrumbs FIRST (wraps console/fetch immediately)
        // This ensures we capture breadcrumbs for any errors that occur during init
        this.breadcrumbs.install();

        // 2. Install session replay tracking (if enabled)
        if (this.config.sessionReplayEnabled && this.heatmap) {
            this.installReplayLifecycle();
        }

        // 3. Install error capture LAST (processes buffered errors, then starts live capture)
        // Note: breadcrumbs.install() is called again in client.install() but it's idempotent
        this.client.install();

        this.initialized = true;

        if (this.logger.isEnabled()) {
            const sdkLoadTime = window._appLoggerBuffer?.startTime
                ? Date.now() - window._appLoggerBuffer.startTime
                : 'unknown';

            this.logger.warn('Initialized', {
                environment: this.config.environment,
                release: this.config.release,
                sessionReplayEnabled: this.config.sessionReplayEnabled,
                sessionId: this.sessionManager?.getSessionId(),
                sdkLoadTime: sdkLoadTime + 'ms',
                bufferedErrors: window._appLoggerBuffer?.errors?.length || 0,
            });
        }
    }

    /**
     * Install session-replay tracking and its unload/visibility lifecycle hooks.
     *
     * The unload/visibility handlers are stored as bound references so they can
     * be removed again in {@link teardownReplayLifecycle} (and thus on
     * sessionReplay.disable()), so repeated enable/disable cycles in SPAs don't
     * leak listeners or the periodic-save interval.
     */
    installReplayLifecycle() {
        this.heatmap.install();
        this.errorDetector.install();

        // Periodic save so the buffer survives an unexpected page close.
        this.bufferSaveInterval = setInterval(() => {
            this.saveBufferToStorage();
        }, 5000);

        this.boundSaveBuffer = () => this.saveBufferToStorage();
        this.boundVisibilitySave = () => {
            if (document.visibilityState === 'hidden') {
                this.saveBufferToStorage();
            }
        };

        window.addEventListener('beforeunload', this.boundSaveBuffer);
        document.addEventListener('visibilitychange', this.boundVisibilitySave);

        this.logger.warn('Session replay enabled (error-triggered, periodic saves every 5s)');
    }

    /**
     * Remove the session-replay lifecycle hooks and stop periodic saves.
     */
    teardownReplayLifecycle() {
        if (this.bufferSaveInterval) {
            clearInterval(this.bufferSaveInterval);
            this.bufferSaveInterval = null;
        }
        if (this.boundSaveBuffer) {
            window.removeEventListener('beforeunload', this.boundSaveBuffer);
            this.boundSaveBuffer = null;
        }
        if (this.boundVisibilitySave) {
            document.removeEventListener('visibilitychange', this.boundVisibilitySave);
            this.boundVisibilitySave = null;
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

                this.logger.warn('Buffer saved to localStorage');
            }
        } catch (error) {
            this.logger.error('Failed to save buffer', error);
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

                        // Re-point the client at the freshly created replay
                        // components so captureException's replay path works after
                        // a disable()/enable() cycle (RML-04).
                        if (this.client) {
                            this.client.errorDetector = this.errorDetector;
                            this.client.sessionManager = this.sessionManager;
                        }

                        if (this.initialized && this.heatmap) {
                            this.installReplayLifecycle();
                        }
                    }

                    this.logger.warn('Session replay enabled');
                }
            },

            /**
             * Disable session replay recording
             */
            disable: () => {
                if (this.config.sessionReplayEnabled) {
                    this.config.sessionReplayEnabled = false;

                    // Stop the periodic save + remove unload/visibility hooks
                    // (prevents listener/interval leaks in SPAs).
                    this.teardownReplayLifecycle();

                    if (this.heatmap) {
                        this.heatmap.cleanup();
                    }
                    if (this.errorDetector) {
                        this.errorDetector.uninstall();
                    }
                    if (this.sessionManager && typeof this.sessionManager.teardown === 'function') {
                        this.sessionManager.teardown();
                    }
                    this.saveBufferToStorage();

                    // Null the components so a later enable() takes the `!this.heatmap`
                    // re-init path and re-arms the lifecycle (interval + listeners +
                    // click capture). Leaving them set made enable() a silent no-op
                    // because heatmap.cleanup()/teardown already disarmed them (RML-04).
                    this.heatmap = null;
                    this.errorDetector = null;
                    this.replayBuffer = null;
                    this.sessionManager = null;

                    // Also drop the CLIENT's own pointers. The client retains its
                    // references independently of these INDEX-level fields, so
                    // nulling them above is not enough: a later captureException()
                    // would still see a live errorDetector/sessionManager and
                    // attach replay data after opt-out (BUNDLE-REPLAY-OPTOUT).
                    if (this.client) {
                        this.client.errorDetector = null;
                        this.client.sessionManager = null;
                    }

                    this.logger.warn('Session replay disabled');
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
