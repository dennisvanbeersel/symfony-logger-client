/**
 * Session Manager - Cross-Page Session Management
 *
 * Manages session ID persistence across page navigations using localStorage.
 * Tracks page transitions and session metadata for replay continuity.
 *
 * Features:
 * - Persistent session ID (UUID) in localStorage
 * - Cross-page session continuity
 * - Page transition tracking
 * - Session expiration (idle timeout)
 * - Session metadata (start time, page count)
 */
export class SessionManager {
    /**
     * @param {Object} [config] - Configuration options
     * @param {number} [config.sessionTimeoutMinutes=30] - Session timeout in minutes
     * @param {boolean} [config.debug=false] - Enable debug logging
     */
    constructor(config = {}) {
        this.config = {
            sessionTimeoutMinutes: Math.min(config.sessionTimeoutMinutes || 30, 120),
            debug: config.debug || false,
        };

        // localStorage keys
        this.STORAGE_KEY_SESSION_ID = '_app_logger_session_id';
        this.STORAGE_KEY_SESSION_METADATA = '_app_logger_session_metadata';

        // Session state
        this.sessionId = null;
        this.metadata = {
            startedAt: null,
            lastActivityAt: null,
            pageCount: 0,
            pages: [],
        };

        // Initialize
        this.initialize();

        if (this.config.debug) {
            console.warn('SessionManager initialized', {
                sessionId: this.sessionId,
                metadata: this.metadata,
            });
        }
    }

    /**
     * Initialize session (load or create)
     */
    initialize() {
        try {
            // Try to load existing session
            const loaded = this.loadSession();

            if (!loaded || this.isSessionExpired()) {
                // Create new session
                this.createNewSession();
            } else {
                // Update last activity
                this.updateActivity();
            }

            // Track current page
            this.trackPageView(window.location.href);

            // Set up page transition tracking
            this.setupPageTransitionTracking();
        } catch (error) {
            console.error('SessionManager: Failed to initialize:', error);
            // Fallback: create new session
            this.createNewSession();
        }
    }

    /**
     * Create a new session
     */
    createNewSession() {
        try {
            this.sessionId = this.generateSessionId();
            this.metadata = {
                startedAt: Date.now(),
                lastActivityAt: Date.now(),
                pageCount: 0,
                pages: [],
            };

            this.saveSession();

            if (this.config.debug) {
                console.warn('SessionManager: Created new session', this.sessionId);
            }
        } catch (error) {
            console.error('SessionManager: Failed to create new session:', error);
        }
    }

    /**
     * Load session from localStorage
     *
     * @returns {boolean} True if session loaded successfully
     */
    loadSession() {
        try {
            const sessionId = localStorage.getItem(this.STORAGE_KEY_SESSION_ID);
            const metadataJson = localStorage.getItem(this.STORAGE_KEY_SESSION_METADATA);

            if (!sessionId || !metadataJson) {
                return false;
            }

            const metadata = JSON.parse(metadataJson);

            if (!metadata || !metadata.startedAt) {
                return false;
            }

            this.sessionId = sessionId;
            this.metadata = metadata;

            if (this.config.debug) {
                console.warn('SessionManager: Loaded session', {
                    sessionId,
                    age: this.getSessionAge(),
                });
            }

            return true;
        } catch (error) {
            console.error('SessionManager: Failed to load session:', error);
            return false;
        }
    }

    /**
     * Save session to localStorage
     */
    saveSession() {
        try {
            localStorage.setItem(this.STORAGE_KEY_SESSION_ID, this.sessionId);
            localStorage.setItem(
                this.STORAGE_KEY_SESSION_METADATA,
                JSON.stringify(this.metadata),
            );
        } catch (error) {
            console.error('SessionManager: Failed to save session:', error);
        }
    }

    /**
     * Check if session is expired
     *
     * @returns {boolean}
     */
    isSessionExpired() {
        try {
            if (!this.metadata.lastActivityAt) {
                return true;
            }

            const now = Date.now();
            const lastActivity = this.metadata.lastActivityAt;
            const timeoutMs = this.config.sessionTimeoutMinutes * 60 * 1000;

            return (now - lastActivity) > timeoutMs;
        } catch {
            return true;
        }
    }

    /**
     * Update last activity timestamp
     */
    updateActivity() {
        try {
            this.metadata.lastActivityAt = Date.now();
            this.saveSession();
        } catch (error) {
            console.error('SessionManager: Failed to update activity:', error);
        }
    }

    /**
     * Track page view
     *
     * @param {string} url - Page URL
     * @returns {Object} Page transition event
     */
    trackPageView(url) {
        try {
            // Increment page count
            this.metadata.pageCount++;

            // Add to pages array (keep last 50)
            this.metadata.pages.push({
                url,
                timestamp: Date.now(),
            });

            if (this.metadata.pages.length > 50) {
                this.metadata.pages = this.metadata.pages.slice(-50);
            }

            // Update activity
            this.updateActivity();

            // Create page transition event
            const pageEvent = {
                type: 'pageTransition',
                url,
                timestamp: Date.now(),
                phase: 'before_error',
                sessionId: this.sessionId,
                pageCount: this.metadata.pageCount,
            };

            if (this.config.debug) {
                console.warn('SessionManager: Page view tracked', {
                    url,
                    pageCount: this.metadata.pageCount,
                });
            }

            return pageEvent;
        } catch (error) {
            console.error('SessionManager: Failed to track page view:', error);
            return null;
        }
    }

    /**
     * Set up page transition tracking
     */
    setupPageTransitionTracking() {
        try {
            // Track history API navigation (pushState, replaceState)
            const originalPushState = history.pushState;
            const originalReplaceState = history.replaceState;

            history.pushState = (...args) => {
                originalPushState.apply(history, args);
                this.handleNavigationChange();
            };

            history.replaceState = (...args) => {
                originalReplaceState.apply(history, args);
                this.handleNavigationChange();
            };

            // Track popstate (back/forward buttons)
            window.addEventListener('popstate', () => {
                this.handleNavigationChange();
            });

            // Track hash changes
            window.addEventListener('hashchange', () => {
                this.handleNavigationChange();
            });
        } catch (error) {
            console.error('SessionManager: Failed to setup page transition tracking:', error);
        }
    }

    /**
     * Handle navigation change (for SPA routing)
     */
    handleNavigationChange() {
        try {
            const url = window.location.href;

            if (this.config.debug) {
                console.warn('SessionManager: Navigation detected', url);
            }

            // Track the navigation as a page transition
            this.trackPageView(url);
        } catch (error) {
            console.error('SessionManager: Failed to handle navigation change:', error);
        }
    }

    /**
     * Get current session ID
     *
     * @returns {string}
     */
    getSessionId() {
        return this.sessionId;
    }

    /**
     * Get session metadata
     *
     * @returns {Object}
     */
    getMetadata() {
        return { ...this.metadata };
    }

    /**
     * Get session age in milliseconds
     *
     * @returns {number}
     */
    getSessionAge() {
        if (!this.metadata.startedAt) {
            return 0;
        }

        return Date.now() - this.metadata.startedAt;
    }

    /**
     * Generate a new session ID (UUID v4)
     *
     * @returns {string}
     */
    generateSessionId() {
        try {
            // Use crypto.randomUUID if available (modern browsers)
            if (crypto && crypto.randomUUID) {
                return crypto.randomUUID();
            }

            // Fallback: Generate UUID v4 manually
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                const r = Math.random() * 16 | 0;
                const v = c === 'x' ? r : (r & 0x3 | 0x8);
                return v.toString(16);
            });
        } catch {
            // Last resort: timestamp + random
            return `${Date.now()}-${Math.random().toString(36).substring(2, 11)}`;
        }
    }

    /**
     * Clear session (force new session on next page load)
     */
    clearSession() {
        try {
            localStorage.removeItem(this.STORAGE_KEY_SESSION_ID);
            localStorage.removeItem(this.STORAGE_KEY_SESSION_METADATA);

            this.sessionId = null;
            this.metadata = {
                startedAt: null,
                lastActivityAt: null,
                pageCount: 0,
                pages: [],
            };

            if (this.config.debug) {
                console.warn('SessionManager: Session cleared');
            }
        } catch (error) {
            console.error('SessionManager: Failed to clear session:', error);
        }
    }

    /**
     * Extend session (reset idle timeout)
     */
    extendSession() {
        try {
            this.updateActivity();

            if (this.config.debug) {
                console.warn('SessionManager: Session extended');
            }
        } catch (error) {
            console.error('SessionManager: Failed to extend session:', error);
        }
    }

    /**
     * Get session info for debugging
     *
     * @returns {Object}
     */
    getSessionInfo() {
        return {
            sessionId: this.sessionId,
            age: this.getSessionAge(),
            ageMinutes: Math.floor(this.getSessionAge() / 1000 / 60),
            isExpired: this.isSessionExpired(),
            pageCount: this.metadata.pageCount,
            recentPages: this.metadata.pages.slice(-5),
            timeoutMinutes: this.config.sessionTimeoutMinutes,
        };
    }
}
