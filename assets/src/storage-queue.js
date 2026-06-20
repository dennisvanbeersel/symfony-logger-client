/**
 * Local Storage Queue for JavaScript
 *
 * Buffers failed error submissions in localStorage for later retry.
 * Used when the API is unreachable or circuit breaker is open.
 *
 * Features:
 * - FIFO queue with size limits
 * - Automatic expiration of old errors
 * - Safe storage operations (never crash on quota exceeded)
 *
 * @example
 * const queue = new StorageQueue({ maxSize: 50, maxAge: 86400000 });
 * queue.enqueue({ message: 'Error', stack: '...' });
 * const error = queue.dequeue(); // Returns oldest error or null
 */
export class StorageQueue {
    /**
     * Create a new StorageQueue instance
     *
     * @param {Object} [config={}] - Configuration options
     * @param {number} [config.maxSize=50] - Maximum items to store
     * @param {number} [config.maxAge=86400000] - Maximum age in ms (default 24h)
     */
    constructor(config = {}) {
        /** @type {string} localStorage key for the queue */
        this.storageKey = 'app_logger_queue';
        /** @type {number} Maximum items to store */
        this.maxSize = config.maxSize || 50;
        /** @type {number} Maximum age in milliseconds */
        this.maxAge = config.maxAge || 86400000;
    }

    /**
     * Add an error to the queue
     *
     * Adds the payload with a timestamp for expiration tracking.
     * Automatically removes oldest items if maxSize is exceeded.
     *
     * @param {Object} payload - Error data to queue
     * @returns {void}
     */
    enqueue(payload) {
        try {
            const queue = this.getQueue();

            // Add timestamp for expiration
            const item = {
                payload,
                timestamp: Date.now(),
            };

            queue.push(item);

            // Limit queue size (FIFO - remove oldest)
            if (queue.length > this.maxSize) {
                queue.shift();
            }

            this.saveQueue(queue);
        } catch (error) {
            // Storage failures should never crash the app
            // Common causes: quota exceeded, private browsing mode
            console.warn('ApplicationLogger: Failed to queue error', error);
        }
    }

    /**
     * Get and remove next error from queue (FIFO)
     *
     * @returns {Object|null} Oldest queued payload, or null if empty
     */
    dequeue() {
        try {
            const queue = this.getQueue();

            if (queue.length === 0) {
                return null;
            }

            const item = queue.shift();
            this.saveQueue(queue);

            return item.payload;
        } catch {
            return null;
        }
    }

    /**
     * Get all queued errors without removing them
     *
     * @returns {Object[]} Array of queued payloads
     */
    getAll() {
        const queue = this.getQueue();
        return queue.map(item => item.payload);
    }

    /**
     * Get current queue size
     *
     * @returns {number} Number of items in queue
     */
    size() {
        const queue = this.getQueue();
        return queue.length;
    }

    /**
     * Clear all items from the queue
     *
     * @returns {void}
     */
    clear() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch {
            // Ignore
        }
    }

    /**
     * Get queue from localStorage with expiration cleanup (internal)
     *
     * Automatically removes expired items based on maxAge.
     *
     * @private
     * @returns {Array<{payload: Object, timestamp: number}>} Queue items with metadata
     */
    getQueue() {
        try {
            const stored = localStorage.getItem(this.storageKey);

            if (!stored) {
                return [];
            }

            const queue = JSON.parse(stored);

            if (!Array.isArray(queue)) {
                return [];
            }

            // Remove expired items
            const now = Date.now();
            const filtered = queue.filter(item => {
                return item.timestamp && (now - item.timestamp) < this.maxAge;
            });

            // If we removed expired items, save the cleaned queue
            if (filtered.length !== queue.length) {
                this.saveQueue(filtered);
            }

            return filtered;
        } catch {
            return [];
        }
    }

    /**
     * Save queue to localStorage (internal)
     *
     * Handles QuotaExceededError by trimming oldest items.
     *
     * @private
     * @param {Array<{payload: Object, timestamp: number}>} queue - Queue items to save
     * @returns {void}
     */
    saveQueue(queue) {
        try {
            localStorage.setItem(this.storageKey, JSON.stringify(queue));
        } catch (error) {
            // Handle quota exceeded or other storage errors
            if (error.name === 'QuotaExceededError') {
                // Try to make space by removing oldest items
                const halfSize = Math.floor(queue.length / 2);
                const trimmed = queue.slice(-halfSize);

                try {
                    localStorage.setItem(this.storageKey, JSON.stringify(trimmed));
                } catch {
                    // If still failing, clear the queue
                    this.clear();
                }
            }
        }
    }
}
