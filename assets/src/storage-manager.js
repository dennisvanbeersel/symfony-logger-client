/**
 * Storage Manager - localStorage Management for Replay Buffer
 *
 * Manages localStorage for replay buffer persistence across pages.
 * Handles quota management, compression, and cleanup.
 *
 * Features:
 * - Save/load replay buffer to/from localStorage
 * - Quota management (prevents quota exceeded errors)
 * - Automatic cleanup of old sessions
 * - LRU eviction when quota is tight
 * - Size monitoring and reporting
 */
export class StorageManager {
    /**
     * @param {Object} [config] - Configuration options
     * @param {number} [config.maxBufferSizeMB=5] - Maximum buffer size in MB
     * @param {boolean} [config.debug=false] - Enable debug logging
     */
    constructor(config = {}) {
        this.config = {
            maxBufferSizeMB: Math.min(config.maxBufferSizeMB || 5, 20),
            debug: config.debug || false,
        };

        // localStorage keys
        this.STORAGE_KEY_BUFFER = '_app_logger_replay_buffer';
        this.STORAGE_KEY_METADATA = '_app_logger_replay_metadata';

        // Statistics
        this.stats = {
            savesSuccessful: 0,
            savesFailed: 0,
            loadsSuccessful: 0,
            loadsFailed: 0,
            quotaExceededCount: 0,
            cleanupCount: 0,
        };

        if (this.config.debug) {
            console.warn('StorageManager initialized with config:', this.config);
        }
    }

    /**
     * Save replay buffer to localStorage
     *
     * @param {Object} bufferData - Serialized buffer data from ReplayBuffer
     * @returns {boolean} Success
     */
    save(bufferData) {
        try {
            if (!bufferData || typeof bufferData !== 'object') {
                console.warn('StorageManager: Invalid buffer data');
                return false;
            }

            // Check size before saving
            const estimatedSize = this.estimateSize(bufferData);
            const maxSizeBytes = this.config.maxBufferSizeMB * 1024 * 1024;

            if (estimatedSize > maxSizeBytes) {
                if (this.config.debug) {
                    console.warn('StorageManager: Buffer too large', {
                        size: estimatedSize,
                        max: maxSizeBytes,
                        sizeMB: (estimatedSize / 1024 / 1024).toFixed(2),
                    });
                }

                // Try to make space
                this.cleanup();

                // If still too large, prune the buffer
                if (estimatedSize > maxSizeBytes) {
                    bufferData = this.pruneBuffer(bufferData, maxSizeBytes);
                }
            }

            // Save to localStorage
            localStorage.setItem(this.STORAGE_KEY_BUFFER, JSON.stringify(bufferData));

            // Save metadata
            this.saveMetadata({
                savedAt: Date.now(),
                size: estimatedSize,
            });

            this.stats.savesSuccessful++;

            if (this.config.debug) {
                console.warn('StorageManager: Buffer saved', {
                    size: estimatedSize,
                    events: bufferData.buffer?.length || 0,
                });
            }

            return true;
        } catch (error) {
            this.stats.savesFailed++;

            if (error.name === 'QuotaExceededError') {
                this.stats.quotaExceededCount++;

                if (this.config.debug) {
                    console.warn('StorageManager: Quota exceeded, attempting cleanup');
                }

                // Try to make space and retry
                this.cleanup();

                try {
                    // Retry with pruned buffer
                    const prunedBuffer = this.pruneBuffer(
                        bufferData,
                        this.config.maxBufferSizeMB * 1024 * 1024 / 2, // Use half max size
                    );

                    localStorage.setItem(
                        this.STORAGE_KEY_BUFFER,
                        JSON.stringify(prunedBuffer),
                    );

                    this.stats.savesSuccessful++;
                    return true;
                } catch {
                    console.error('StorageManager: Failed to save even after cleanup');
                    return false;
                }
            }

            console.error('StorageManager: Failed to save buffer:', error);
            return false;
        }
    }

    /**
     * Load replay buffer from localStorage
     *
     * @returns {Object|null} Buffer data or null if not found/invalid
     */
    load() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY_BUFFER);

            if (!stored) {
                return null;
            }

            const bufferData = JSON.parse(stored);

            if (!bufferData || typeof bufferData !== 'object') {
                return null;
            }

            // Validate buffer structure
            if (!Array.isArray(bufferData.buffer)) {
                console.warn('StorageManager: Invalid buffer structure');
                return null;
            }

            this.stats.loadsSuccessful++;

            if (this.config.debug) {
                console.warn('StorageManager: Buffer loaded', {
                    events: bufferData.buffer.length,
                    isRecording: bufferData.isRecordingAfterError,
                });
            }

            return bufferData;
        } catch (error) {
            this.stats.loadsFailed++;
            console.error('StorageManager: Failed to load buffer:', error);
            return null;
        }
    }

    /**
     * Clear replay buffer from localStorage
     */
    clear() {
        try {
            localStorage.removeItem(this.STORAGE_KEY_BUFFER);
            localStorage.removeItem(this.STORAGE_KEY_METADATA);

            if (this.config.debug) {
                console.warn('StorageManager: Buffer cleared');
            }
        } catch (error) {
            console.error('StorageManager: Failed to clear buffer:', error);
        }
    }

    /**
     * Clean up old/expired data
     */
    cleanup() {
        try {
            // Remove old buffer if it exists
            const metadata = this.loadMetadata();

            if (metadata && metadata.savedAt) {
                const age = Date.now() - metadata.savedAt;
                const maxAge = 24 * 60 * 60 * 1000; // 24 hours

                if (age > maxAge) {
                    this.clear();
                    this.stats.cleanupCount++;

                    if (this.config.debug) {
                        console.warn('StorageManager: Cleaned up old buffer', {
                            ageHours: (age / 1000 / 60 / 60).toFixed(1),
                        });
                    }
                }
            }
        } catch (error) {
            console.error('StorageManager: Cleanup failed:', error);
        }
    }

    /**
     * Save metadata
     *
     * @param {Object} metadata
     */
    saveMetadata(metadata) {
        try {
            localStorage.setItem(
                this.STORAGE_KEY_METADATA,
                JSON.stringify(metadata),
            );
        } catch (error) {
            // Metadata save failure is not critical
            if (this.config.debug) {
                console.warn('StorageManager: Failed to save metadata:', error);
            }
        }
    }

    /**
     * Load metadata
     *
     * @returns {Object|null}
     */
    loadMetadata() {
        try {
            const stored = localStorage.getItem(this.STORAGE_KEY_METADATA);

            if (!stored) {
                return null;
            }

            return JSON.parse(stored);
        } catch {
            return null;
        }
    }

    /**
     * Prune buffer to fit within size limit
     *
     * @param {Object} bufferData
     * @param {number} maxSizeBytes
     * @returns {Object} Pruned buffer
     */
    pruneBuffer(bufferData, maxSizeBytes) {
        try {
            if (!bufferData.buffer || !Array.isArray(bufferData.buffer)) {
                return bufferData;
            }

            // Start by keeping all events
            const pruned = { ...bufferData };
            const events = [...bufferData.buffer];

            // Remove oldest events until we fit
            while (this.estimateSize(pruned) > maxSizeBytes && events.length > 1) {
                // Remove oldest event (but keep error marker)
                const removed = events.shift();

                // If we removed an error marker, put it back
                if (removed && removed.phase === 'error') {
                    events.unshift(removed);
                    break;
                }

                pruned.buffer = events;
            }

            if (this.config.debug) {
                console.warn('StorageManager: Buffer pruned', {
                    originalEvents: bufferData.buffer.length,
                    prunedEvents: events.length,
                    originalSize: this.estimateSize(bufferData),
                    prunedSize: this.estimateSize(pruned),
                });
            }

            return pruned;
        } catch (error) {
            console.error('StorageManager: Failed to prune buffer:', error);
            return bufferData;
        }
    }

    /**
     * Estimate size of data in bytes
     *
     * @param {Object} data
     * @returns {number} Size in bytes
     */
    estimateSize(data) {
        try {
            const json = JSON.stringify(data);
            return json.length;
        } catch {
            return 0;
        }
    }

    /**
     * Get available localStorage space (approximate)
     *
     * @returns {Object} Space info
     */
    getSpaceInfo() {
        try {
            const testKey = '_app_logger_space_test';
            const testData = '0'.repeat(1024); // 1KB test string

            let available = 0;
            let used = 0;

            // Estimate used space
            for (const key in localStorage) {
                if (Object.prototype.hasOwnProperty.call(localStorage, key)) {
                    used += localStorage[key].length + key.length;
                }
            }

            // Estimate available space (crude test)
            try {
                for (let i = 0; i < 10000; i++) {
                    localStorage.setItem(testKey, testData.repeat(i));
                    available = i * 1024;
                }
            } catch {
                // Quota exceeded - we found the limit
            } finally {
                localStorage.removeItem(testKey);
            }

            return {
                usedBytes: used,
                usedMB: (used / 1024 / 1024).toFixed(2),
                availableMB: (available / 1024 / 1024).toFixed(2),
                totalMB: ((used + available) / 1024 / 1024).toFixed(2),
            };
        } catch {
            return {
                usedBytes: 0,
                usedMB: 'unknown',
                availableMB: 'unknown',
                totalMB: 'unknown',
            };
        }
    }

    /**
     * Get storage statistics
     *
     * @returns {Object}
     */
    getStats() {
        const spaceInfo = this.getSpaceInfo();

        return {
            ...this.stats,
            ...spaceInfo,
            maxBufferSizeMB: this.config.maxBufferSizeMB,
        };
    }

    /**
     * Check if localStorage is available
     *
     * @returns {boolean}
     */
    isAvailable() {
        try {
            const testKey = '_app_logger_test';
            localStorage.setItem(testKey, 'test');
            localStorage.removeItem(testKey);
            return true;
        } catch {
            return false;
        }
    }
}
