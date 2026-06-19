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
        // Running approximate byte size of the buffer, maintained incrementally so
        // addEvent() never has to JSON.stringify() the WHOLE buffer on every click
        // (JSPERF-01). Per-event sizes are memoized in a WeakMap keyed by the event
        // object, so heavy domSnapshots are serialized at most once each (not
        // re-serialized for every subsequent click). estimateBufferSize() remains
        // available for explicit debug/getStats callers.
        this.approxBytes = 0;
        this.eventSizes = new WeakMap();
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

            // Add to buffer (and account for its approximate byte size once)
            this.buffer.push(event);
            this.approxBytes += this.measureEvent(event);
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
            const errorEvent = {
                type: 'error',
                phase: 'error',
                timestamp: this.errorOccurredAt,
                capturedAt: Date.now(),
                url: window.location.href,
                errorContext,
            };
            this.buffer.push(errorEvent);
            this.approxBytes += this.measureEvent(errorEvent);
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
            const maxClicks = this.config.bufferBeforeErrorClicks;

            // Single pass: apply the time/error-phase window and count the surviving
            // clicks so we can trim to the last N. Events are appended in
            // capturedAt order, so the kept list is already chronological - no
            // re-sort and no extra filter passes are needed (JSPERF-02). The output
            // is identical to the previous 3-filter + sort implementation.
            const timeFiltered = [];
            let clickCount = 0;
            for (const event of this.buffer) {
                if (event.capturedAt >= cutoffTime || event.phase === 'error') {
                    timeFiltered.push(event);
                    if (event.type === 'click') {
                        clickCount++;
                    }
                }
            }

            // Enforce the click limit: drop the oldest clicks beyond the last N,
            // keeping all non-click events (page transitions, errors) in place.
            let pruned;
            if (clickCount > maxClicks) {
                let clicksToDrop = clickCount - maxClicks;
                pruned = [];
                for (const event of timeFiltered) {
                    if (event.type === 'click' && clicksToDrop > 0) {
                        clicksToDrop--;
                        continue;
                    }
                    pruned.push(event);
                }
            } else {
                pruned = timeFiltered;
            }

            this.buffer = pruned;
            this.recomputeApproxBytes();

            // Update stats if buffer was pruned (relative to the time-filtered set,
            // matching the original accounting).
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
            this.approxBytes = 0;
            this.eventSizes = new WeakMap();
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
            // Use the incrementally-maintained running estimate instead of
            // re-stringifying the whole buffer on every event (JSPERF-01).
            const approximateSize = this.approxBytes;
            this.stats.currentBufferSize = approximateSize;

            // Check if buffer is getting too large
            const maxSizeBytes = this.config.maxBufferSizeMB * 1024 * 1024;
            if (approximateSize > maxSizeBytes) {
                this.stats.bufferFullCount++;
                // Aggressive pruning
                this.buffer = this.buffer.slice(-Math.floor(this.buffer.length / 2));
                this.recomputeApproxBytes();
            }
        } catch (error) {
            console.error('ReplayBuffer: Failed to update stats:', error);
        }
    }

    /**
     * Measure and memoize the approximate byte size of a single event.
     *
     * The result is cached per event object (WeakMap) so a heavy domSnapshot is
     * serialized at most once, even though the event survives many prune passes.
     *
     * @private
     * @param {Object} event - Event to measure
     * @returns {number} Approximate size in bytes
     */
    measureEvent(event) {
        const cached = this.eventSizes.get(event);
        if (cached !== undefined) {
            return cached;
        }
        let size = 0;
        try {
            size = JSON.stringify(event).length;
        } catch {
            size = 0;
        }
        this.eventSizes.set(event, size);
        return size;
    }

    /**
     * Recompute the running byte estimate from the current buffer.
     *
     * Called after operations that rebuild/trim the buffer (prune, aggressive
     * trim, deserialize). Per-event sizes are served from the WeakMap cache, so
     * already-measured events are not re-serialized.
     *
     * @private
     */
    recomputeApproxBytes() {
        let total = 0;
        for (const event of this.buffer) {
            total += this.measureEvent(event);
        }
        this.approxBytes = total;
    }

    /**
     * Estimate buffer size in bytes (full serialization).
     *
     * Retained for explicit debug/diagnostic callers; the hot path uses the
     * incremental this.approxBytes counter instead.
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
                if (this.config.debug) {
                    console.warn('ReplayBuffer: No data to deserialize');
                }
                return false;
            }

            this.buffer = Array.isArray(data.buffer) ? data.buffer : [];
            this.isRecordingAfterError = !!data.isRecordingAfterError;
            this.errorOccurredAt = data.errorOccurredAt || null;
            this.postErrorEventCount = data.postErrorEventCount || 0;

            if (data.stats && typeof data.stats === 'object') {
                this.stats = { ...this.stats, ...data.stats };
            }

            // MIGRATION: Ensure all events have phase property
            // Old localStorage data might not have phase property
            let migratedCount = 0;
            this.buffer = this.buffer.map(event => {
                if (!event.phase) {
                    migratedCount++;
                    // Default to 'before_error' for old events
                    return { ...event, phase: 'before_error' };
                }
                return event;
            });

            // Rebuild the running byte estimate for the restored buffer (the .map
            // above may have produced new event objects during migration).
            this.eventSizes = new WeakMap();
            this.recomputeApproxBytes();

            if (this.config.debug) {
                const phaseBreakdown = this.buffer.reduce((acc, event) => {
                    acc[event.phase] = (acc[event.phase] || 0) + 1;
                    return acc;
                }, {});

                console.warn('ReplayBuffer: Deserialized from localStorage', {
                    totalEvents: this.buffer.length,
                    migratedEvents: migratedCount,
                    phaseBreakdown,
                    isRecording: this.isRecordingAfterError,
                    byteSize: this.estimateBufferSize(),
                });
            }

            return true;
        } catch (error) {
            console.error('ReplayBuffer: Failed to deserialize:', error);
            return false;
        }
    }
}
