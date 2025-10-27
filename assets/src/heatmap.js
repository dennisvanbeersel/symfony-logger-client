/**
 * Heatmap Tracker - Captures user clicks and interactions
 *
 * FEATURES:
 * - Click coordinate tracking with viewport dimensions
 * - Element selector generation (CSS selectors)
 * - Debouncing to prevent API flooding
 * - Batch sending for efficiency
 * - Privacy-respecting (no PII in selectors)
 */
export class HeatmapTracker {
    constructor(transport, config) {
        this.transport = transport;
        this.config = config;
        this.clickQueue = [];
        this.batchSize = config.heatmapBatchSize || 10;
        this.batchTimeout = config.heatmapBatchTimeout || 5000; // 5 seconds
        this.batchTimer = null;
        this.isInstalled = false;
        this.sessionId = null;
    }

    /**
     * Install click tracking listeners
     */
    install(sessionId) {
        if (this.isInstalled) {
            return;
        }

        this.sessionId = sessionId;

        try {
            // Track clicks
            document.addEventListener('click', (event) => {
                this.captureClick(event);
            }, true);

            // Flush on page unload
            window.addEventListener('beforeunload', () => {
                this.flush();
            });

            // Flush on visibility change (mobile)
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.flush();
                }
            });

            this.isInstalled = true;
        } catch (error) {
            console.error('ApplicationLogger: Failed to install heatmap tracker', error);
        }
    }

    /**
     * Capture click event
     */
    captureClick(event) {
        try {
            const clickData = {
                type: 'click',
                url: window.location.href,
                x: event.pageX,
                y: event.pageY,
                viewport_width: window.innerWidth,
                viewport_height: window.innerHeight,
                element_selector: this.generateSelector(event.target),
                timestamp: new Date().toISOString(),
                session_id: this.sessionId,
            };

            this.clickQueue.push(clickData);

            // Send batch if queue is full
            if (this.clickQueue.length >= this.batchSize) {
                this.flush();
            } else {
                // Schedule batch send
                this.scheduleBatchSend();
            }
        } catch (error) {
            // Never crash on tracking
            console.error('ApplicationLogger: Failed to capture click', error);
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
     * Schedule batch send with timeout
     */
    scheduleBatchSend() {
        // Clear existing timer
        if (this.batchTimer) {
            clearTimeout(this.batchTimer);
        }

        // Schedule new timer
        this.batchTimer = setTimeout(() => {
            this.flush();
        }, this.batchTimeout);
    }

    /**
     * Flush click queue (send all pending clicks)
     */
    flush() {
        if (this.clickQueue.length === 0) {
            return;
        }

        try {
            // Clear timer
            if (this.batchTimer) {
                clearTimeout(this.batchTimer);
                this.batchTimer = null;
            }

            // Get clicks to send
            const clicksToSend = [...this.clickQueue];
            this.clickQueue = [];

            // Send to heatmap API
            this.sendHeatmapData(clicksToSend);
        } catch (error) {
            console.error('ApplicationLogger: Failed to flush heatmap data', error);
        }
    }

    /**
     * Send heatmap data to API
     */
    async sendHeatmapData(clicks) {
        if (!this.sessionId || clicks.length === 0) {
            return;
        }

        try {
            // Use transport's sendHeatmap method if available, otherwise use regular send
            if (this.transport.sendHeatmap) {
                await this.transport.sendHeatmap(this.sessionId, clicks);
            } else {
                // Fallback: send as regular events
                const events = clicks.map(click => ({
                    type: 'HEATMAP_CLICK',
                    url: click.url,
                    timestamp: click.timestamp,
                    data: click,
                }));

                for (const event of events) {
                    await this.transport.sendSessionEvent(this.sessionId, event);
                }
            }
        } catch (error) {
            console.error('ApplicationLogger: Failed to send heatmap data', error);
        }
    }

    /**
     * Get current queue size for monitoring
     */
    getQueueSize() {
        return this.clickQueue.length;
    }
}
