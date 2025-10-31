/**
 * Replay Buffer - Circular Buffer for Session Replay Data
 *
 * Implements a circular buffer that stores clicks and DOM snapshots
 * before an error occurs. Only sends data when an error is detected.
 *
 * Features:
 * - Time-based buffering (e.g., last 30 seconds)
 * - Click-based buffering (e.g., last 10 clicks)
 * - Memory-efficient circular buffer (FIFO)
 * - Configurable hard caps
 * - Serialization for localStorage
 * - Automatic pruning of old data
 */
export class ReplayBuffer {
    /**
     * @param {Object} config Configuration options
     * @param {number} [config.bufferBeforeErrorSeconds=30] - Seconds of activity before error
     * @param {number} [config.bufferBeforeErrorClicks=10] - Number of clicks before error
     * @param {number} [config.bufferAfterErrorSeconds=30] - Seconds to continue after error
     * @param {number} [config.bufferAfterErrorClicks=10] - Clicks to continue after error
     * @param {number} [config.maxBufferSizeMB=5] - Maximum localStorage buffer size
     * @param {boolean} [config.debug=false] - Enable debug logging
     */
    constructor(config = {}) {
        // Configuration with hard caps enforced
        this.config = {
            bufferBeforeErrorSeconds: Math.min(config.bufferBeforeErrorSeconds || 30, 60),
            bufferBeforeErrorClicks: Math.min(config.bufferBeforeErrorClicks || 10, 15),
            bufferAfterErrorSeconds: Math.min(config.bufferAfterErrorSeconds || 30, 60),
            bufferAfterErrorClicks: Math.min(config.bufferAfterErrorClicks || 10, 15),
            maxBufferSizeMB: Math.min(config.maxBufferSizeMB || 5, 20),
            debug: config.debug || false,
        };

        // Buffer state
        this.buffer = []; // Circular buffer of events
        this.isRecordingAfterError = false;
        this.recordingStartedAt = null;
        this.errorOccurredAt = null;
        this.postErrorEventCount = 0;

        // Statistics
        this.stats = {
            totalEvents: 0,
            eventsDropped: 0,
            bufferFullCount: 0,
            currentBufferSize: 0,
        };

        if (this.config.debug) {
            console.warn('ReplayBuffer initialized with config:', this.config);
        }
    }

    /**
     * Add an event to the buffer
     *
     * @param {Object} event - Event data (click, page transition, etc.)
     * @param {string} event.type - Event type (click, pageTransition)
     * @param {string} event.url - Current URL
     * @param {number} event.timestamp - Event timestamp (milliseconds)
     * @param {Object} [event.clickData] - Click-specific data
     * @param {Object} [event.domSnapshot] - DOM snapshot (optional)
     * @returns {boolean} True if event was added, false if dropped
     */
    addEvent(event) {
        try {
            if (!event || !event.timestamp) {
                console.warn('ReplayBuffer: Invalid event (missing timestamp)');
                return false;
            }

            // Mark event phase (before_error, error, or after_error)
            event.phase = this.isRecordingAfterError ? 'after_error' : 'before_error';
            event.capturedAt = Date.now();

            // Add to buffer
            this.buffer.push(event);
            this.stats.totalEvents++;

            // If recording after error, track count
            if (this.isRecordingAfterError) {
                this.postErrorEventCount++;

                // Check if we should stop recording
                if (this.shouldStopRecording()) {
                    this.stopRecording();
                }
            } else {
                // Prune old events from buffer (before error)
                this.pruneOldEvents();
            }

            // Update stats
            this.updateStats();

            return true;
        } catch (error) {
            console.error('ReplayBuffer: Failed to add event:', error);
            this.stats.eventsDropped++;
            return false;
        }
    }

    /**
     * Mark the start of error-triggered recording
     *
     * Call this when an error is detected. It will:
     * 1. Mark the buffer as "recording after error"
     * 2. Reset post-error counters
     * 3. Prepare to stop after configured buffer is filled
     *
     * @param {Object} errorContext - Error context information
     * @param {string} errorContext.errorId - Error ID from backend
     * @param {string} errorContext.message - Error message
     * @param {number} errorContext.timestamp - Error timestamp
     */
    startRecordingAfterError(errorContext) {
        try {
            this.errorOccurredAt = errorContext.timestamp || Date.now();
            this.postErrorEventCount = 0;

            // Add error marker event to buffer (manually, before setting isRecordingAfterError)
            // This ensures the error marker itself is not counted in postErrorEventCount
            this.buffer.push({
                type: 'error',
                phase: 'error',
                timestamp: this.errorOccurredAt,
                capturedAt: Date.now(),
                url: window.location.href,
                errorContext,
            });
            this.stats.totalEvents++;

            // Now mark as recording after error (subsequent events will be counted)
            this.isRecordingAfterError = true;

            if (this.config.debug) {
                console.warn('ReplayBuffer: Started recording after error', {
                    errorId: errorContext.errorId,
                    bufferSize: this.buffer.length,
                    willRecordFor: `${this.config.bufferAfterErrorSeconds}s or ${this.config.bufferAfterErrorClicks} clicks`,
                });
            }
        } catch (error) {
            console.error('ReplayBuffer: Failed to start post-error recording:', error);
        }
    }

    /**
     * Stop recording after error buffer is full
     */
    stopRecording() {
        try {
            if (!this.isRecordingAfterError) {
                return;
            }

            this.isRecordingAfterError = false;

            if (this.config.debug) {
                console.warn('ReplayBuffer: Stopped recording after error', {
                    totalEvents: this.buffer.length,
                    postErrorEvents: this.postErrorEventCount,
                });
            }
        } catch (error) {
            console.error('ReplayBuffer: Failed to stop recording:', error);
        }
    }

    /**
     * Check if we should stop recording after error
     *
     * Stops when either condition is met:
     * - Time limit reached (bufferAfterErrorSeconds)
     * - Click limit reached (bufferAfterErrorClicks)
     *
     * @returns {boolean}
     */
    shouldStopRecording() {
        if (!this.isRecordingAfterError || !this.errorOccurredAt) {
            return false;
        }

        const now = Date.now();
        const elapsedSeconds = (now - this.errorOccurredAt) / 1000;

        // Check time limit
        if (elapsedSeconds >= this.config.bufferAfterErrorSeconds) {
            if (this.config.debug) {
                console.warn(`ReplayBuffer: Time limit reached (${elapsedSeconds.toFixed(1)}s)`);
            }
            return true;
        }

        // Check click limit
        if (this.postErrorEventCount >= this.config.bufferAfterErrorClicks) {
            if (this.config.debug) {
                console.warn(`ReplayBuffer: Click limit reached (${this.postErrorEventCount} clicks)`);
            }
            return true;
        }

        return false;
    }

    /**
     * Prune old events from buffer (keep only recent N seconds/clicks)
     */
    pruneOldEvents() {
        try {
            const now = Date.now();
            const cutoffTime = now - (this.config.bufferBeforeErrorSeconds * 1000);

            // Filter events: keep events within time window
            const timeFiltered = this.buffer.filter(event =>
                event.capturedAt >= cutoffTime || event.phase === 'error',
            );

            // Also enforce click limit: keep last N clicks
            const clickEvents = timeFiltered.filter(e => e.type === 'click');
            const otherEvents = timeFiltered.filter(e => e.type !== 'click');

            // Keep last N clicks + all other events (page transitions, errors)
            const recentClicks = clickEvents.slice(-this.config.bufferBeforeErrorClicks);

            this.buffer = [...otherEvents, ...recentClicks]
                .sort((a, b) => a.capturedAt - b.capturedAt);

            // Update stats if buffer was pruned
            if (this.buffer.length < timeFiltered.length) {
                const dropped = timeFiltered.length - this.buffer.length;
                this.stats.eventsDropped += dropped;
            }
        } catch (error) {
            console.error('ReplayBuffer: Failed to prune old events:', error);
        }
    }

    /**
     * Get all events in the buffer
     *
     * @returns {Array<Object>} Array of events
     */
    getEvents() {
        return [...this.buffer]; // Return copy
    }

    /**
     * Get events by phase
     *
     * @param {string} phase - Phase to filter by (before_error, error, after_error)
     * @returns {Array<Object>}
     */
    getEventsByPhase(phase) {
        return this.buffer.filter(event => event.phase === phase);
    }

    /**
     * Clear the buffer
     */
    clear() {
        try {
            this.buffer = [];
            this.isRecordingAfterError = false;
            this.recordingStartedAt = null;
            this.errorOccurredAt = null;
            this.postErrorEventCount = 0;

            if (this.config.debug) {
                console.warn('ReplayBuffer: Cleared');
            }
        } catch (error) {
            console.error('ReplayBuffer: Failed to clear buffer:', error);
        }
    }

    /**
     * Check if buffer is currently recording after an error
     *
     * @returns {boolean}
     */
    isRecording() {
        return this.isRecordingAfterError;
    }

    /**
     * Get buffer statistics
     *
     * @returns {Object} Statistics
     */
    getStats() {
        return {
            ...this.stats,
            bufferLength: this.buffer.length,
            isRecording: this.isRecordingAfterError,
            postErrorEventCount: this.postErrorEventCount,
        };
    }

    /**
     * Update buffer statistics
     */
    updateStats() {
        try {
            // Calculate approximate buffer size
            const approximateSize = this.estimateBufferSize();
            this.stats.currentBufferSize = approximateSize;

            // Check if buffer is getting too large
            const maxSizeBytes = this.config.maxBufferSizeMB * 1024 * 1024;
            if (approximateSize > maxSizeBytes) {
                this.stats.bufferFullCount++;
                // Aggressive pruning
                this.buffer = this.buffer.slice(-Math.floor(this.buffer.length / 2));
            }
        } catch (error) {
            console.error('ReplayBuffer: Failed to update stats:', error);
        }
    }

    /**
     * Estimate buffer size in bytes
     *
     * @returns {number} Approximate size in bytes
     */
    estimateBufferSize() {
        try {
            const json = JSON.stringify(this.buffer);
            return json.length;
        } catch {
            return 0;
        }
    }

    /**
     * Serialize buffer for storage
     *
     * @returns {Object} Serialized data
     */
    serialize() {
        return {
            buffer: this.buffer,
            isRecordingAfterError: this.isRecordingAfterError,
            errorOccurredAt: this.errorOccurredAt,
            postErrorEventCount: this.postErrorEventCount,
            stats: this.stats,
        };
    }

    /**
     * Deserialize buffer from storage
     *
     * @param {Object} data - Serialized data
     * @returns {boolean} Success
     */
    deserialize(data) {
        try {
            if (!data || typeof data !== 'object') {
                return false;
            }

            this.buffer = Array.isArray(data.buffer) ? data.buffer : [];
            this.isRecordingAfterError = !!data.isRecordingAfterError;
            this.errorOccurredAt = data.errorOccurredAt || null;
            this.postErrorEventCount = data.postErrorEventCount || 0;

            if (data.stats && typeof data.stats === 'object') {
                this.stats = { ...this.stats, ...data.stats };
            }

            if (this.config.debug) {
                console.warn('ReplayBuffer: Deserialized', {
                    events: this.buffer.length,
                    isRecording: this.isRecordingAfterError,
                });
            }

            return true;
        } catch (error) {
            console.error('ReplayBuffer: Failed to deserialize:', error);
            return false;
        }
    }
}
