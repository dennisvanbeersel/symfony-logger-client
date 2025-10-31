import { ThrottledDOMSerializer } from './dom-serializer.js';

/**
 * Click Tracker - Captures user clicks and interactions for Session Replay
 *
 * ERROR-TRIGGERED RECORDING ONLY:
 * - Records clicks to buffer (not sent immediately)
 * - Buffer sent only when error detected (via ErrorDetector)
 * - Captures N seconds/clicks before and after error
 *
 * FEATURES:
 * - Click coordinate tracking with viewport dimensions
 * - Element selector generation (CSS selectors)
 * - DOM structure capture for session replay (privacy-safe)
 * - Page transition tracking (for cross-page sessions)
 * - Privacy-respecting (no PII in selectors or DOM snapshots)
 * - Click debouncing to prevent localStorage spam from rapid clicking
 */
export class ClickTracker {
    /**
     * @param {ReplayBuffer} replayBuffer - Replay buffer instance
     * @param {SessionManager} sessionManager - Session manager instance
     * @param {Object} config - Configuration options
     */
    constructor(replayBuffer, sessionManager, config) {
        this.replayBuffer = replayBuffer;
        this.sessionManager = sessionManager;
        this.config = config;
        this.isInstalled = false;

        // Initialize DOM serializer with configurable throttling
        const throttleMs = Math.max(config.snapshotThrottleMs || 1000, 500); // Min 500ms
        this.domSerializer = new ThrottledDOMSerializer({
            maxDepth: 10,           // Limit tree depth
            minSize: 5,             // Skip tiny elements
            skipInvisible: true,    // Skip hidden elements
            captureColors: true,    // Capture background colors
            throttleMs,             // Configurable throttle
            maxSize: config.maxSnapshotSize || 1048576, // Default 1MB
            debug: config.debug || false,
        });

        // Track DOM capture stats
        this.domCaptureStats = {
            total: 0,
            throttled: 0,
            captured: 0,
            errors: 0,
        };

        // Click debouncing to prevent localStorage spam
        this.lastClickTime = 0;
        this.clickDebounceMs = Math.max(config.clickDebounceMs || 1000, 100); // Min 100ms
        this.debounceStats = {
            totalClicks: 0,
            debouncedClicks: 0,
        };
    }

    /**
     * Install click tracking listeners
     */
    install() {
        if (this.isInstalled) {
            return;
        }

        try {
            // Track clicks
            document.addEventListener('click', (event) => {
                this.captureClick(event);
            }, true);

            this.isInstalled = true;

            if (this.config.debug) {
                console.warn('ClickTracker: Installed (buffer-based recording)');
            }
        } catch (error) {
            console.error('ClickTracker: Failed to install', error);
        }
    }

    /**
     * Capture click event with DOM snapshot
     *
     * Events are buffered (not sent immediately) and only transmitted
     * when an error is detected via ErrorDetector.
     *
     * Includes debouncing to prevent localStorage spam from rapid clicking.
     */
    captureClick(event) {
        try {
            // Debounce: Ignore clicks that are too close together
            const now = Date.now();
            this.debounceStats.totalClicks++;

            if (now - this.lastClickTime < this.clickDebounceMs) {
                this.debounceStats.debouncedClicks++;
                if (this.config.debug) {
                    console.warn('ClickTracker: Click debounced', {
                        timeSinceLastClick: now - this.lastClickTime,
                        debounceThreshold: this.clickDebounceMs,
                    });
                }
                return; // Skip this click
            }

            this.lastClickTime = now;

            // Create click event data
            const clickEvent = {
                type: 'click',
                url: window.location.href,
                timestamp: Date.now(),
                clickData: {
                    x: event.pageX,
                    y: event.pageY,
                    viewportWidth: window.innerWidth,
                    viewportHeight: window.innerHeight,
                    elementSelector: this.generateSelector(event.target),
                },
                sessionId: this.sessionManager.getSessionId(),
            };

            // Capture DOM snapshot (throttled based on config)
            this.domCaptureStats.total++;
            try {
                const domSnapshot = this.domSerializer.serialize();

                if (domSnapshot) {
                    // Snapshot captured successfully
                    clickEvent.domSnapshot = domSnapshot;
                    this.domCaptureStats.captured++;

                    if (this.config.debug) {
                        const size = this.domSerializer.serializer.estimateSize(domSnapshot);
                        console.warn('ClickTracker: DOM snapshot captured', {
                            elements: domSnapshot.stats?.totalElements || 0,
                            sizeBytes: size,
                            sizeKB: (size / 1024).toFixed(2),
                        });
                    }
                } else {
                    // Snapshot throttled
                    this.domCaptureStats.throttled++;

                    if (this.config.debug) {
                        console.warn('ClickTracker: DOM snapshot throttled');
                    }
                }
            } catch (domError) {
                // DOM serialization failed - don't block the click capture
                this.domCaptureStats.errors++;
                if (this.config.debug) {
                    console.error('ClickTracker: DOM serialization failed', domError);
                }
                // Continue without DOM snapshot
            }

            // Add event to replay buffer (not sent immediately)
            const added = this.replayBuffer.addEvent(clickEvent);

            if (!added && this.config.debug) {
                console.warn('ClickTracker: Failed to add click to buffer');
            }
        } catch (error) {
            // Never crash on tracking
            console.error('ClickTracker: Failed to capture click', error);
        }
    }

    /**
     * Generate CSS selector for element
     *
     * Creates a unique but privacy-respecting selector:
     * - Uses tag name, ID, classes
     * - Limits depth to 5 levels
     * - Removes sensitive attributes (data-*, ng-*, etc.)
     */
    generateSelector(element) {
        if (!element || element === document) {
            return '';
        }

        try {
            const parts = [];
            let current = element;
            let depth = 0;
            const maxDepth = 5;

            while (current && current !== document && depth < maxDepth) {
                let selector = current.tagName.toLowerCase();

                // Add ID if available (most specific)
                if (current.id && !this.containsSensitiveData(current.id)) {
                    selector += `#${CSS.escape(current.id)}`;
                    parts.unshift(selector);
                    break; // ID is unique, stop here
                }

                // Add classes (filter out utility/dynamic classes)
                const classes = this.getCleanClasses(current);
                if (classes.length > 0) {
                    selector += `.${classes.join('.')}`;
                }

                // Add nth-child if needed for uniqueness
                const siblings = current.parentElement ?
                    Array.from(current.parentElement.children).filter(
                        child => child.tagName === current.tagName,
                    ) : [];

                if (siblings.length > 1) {
                    const index = siblings.indexOf(current) + 1;
                    selector += `:nth-child(${index})`;
                }

                parts.unshift(selector);
                current = current.parentElement;
                depth++;
            }

            return parts.join(' > ');
        } catch {
            // If selector generation fails, return basic info
            return element.tagName ? element.tagName.toLowerCase() : 'unknown';
        }
    }

    /**
     * Get cleaned class list (remove utility and sensitive classes)
     */
    getCleanClasses(element) {
        if (!element.classList || element.classList.length === 0) {
            return [];
        }

        const classes = Array.from(element.classList);
        return classes
            .filter(cls => {
                // Filter out utility classes (Tailwind, Bootstrap, etc.)
                if (cls.match(/^(active|hover|focus|disabled|hidden|show)$/)) {
                    return false;
                }

                // Filter out generated classes
                if (cls.match(/^(ng-|v-|data-|_)/)) {
                    return false;
                }

                // Filter out classes that look like they contain sensitive data
                if (this.containsSensitiveData(cls)) {
                    return false;
                }

                return true;
            })
            .map(cls => CSS.escape(cls))
            .slice(0, 3); // Limit to 3 classes
    }

    /**
     * Check if string contains potentially sensitive data
     */
    containsSensitiveData(str) {
        const sensitivePatterns = [
            /user[-_]?id/i,
            /email/i,
            /token/i,
            /session/i,
            /auth/i,
            /key/i,
            /\d{10,}/,  // Long numbers (could be IDs)
        ];

        return sensitivePatterns.some(pattern => pattern.test(str));
    }


    /**
     * Get DOM capture statistics for monitoring
     *
     * @returns {Object} Statistics about DOM snapshot captures
     */
    getDOMCaptureStats() {
        return {
            ...this.domCaptureStats,
            serializerStats: this.domSerializer.getStats(),
        };
    }

    /**
     * Get click debounce statistics
     *
     * @returns {Object} Statistics about debounced clicks
     */
    getDebounceStats() {
        return {
            ...this.debounceStats,
            debounceRate: this.debounceStats.totalClicks > 0
                ? (this.debounceStats.debouncedClicks / this.debounceStats.totalClicks * 100).toFixed(2) + '%'
                : '0%',
            clickDebounceMs: this.clickDebounceMs,
        };
    }

    /**
     * Clean up resources
     */
    cleanup() {
        try {
            // Clear DOM serializer throttle timer
            if (this.domSerializer && this.domSerializer.clearThrottle) {
                this.domSerializer.clearThrottle();
            }

            if (this.config.debug) {
                console.warn('ClickTracker: Cleanup complete');
            }
        } catch (error) {
            console.error('ClickTracker: Cleanup failed', error);
        }
    }
}
