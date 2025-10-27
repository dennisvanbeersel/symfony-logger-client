/**
 * Local Storage Queue
 *
 * Buffers failed error submissions in localStorage for later retry.
 * Used when the API is unreachable or circuit breaker is open.
 *
 * Features:
 * - FIFO queue with size limits
 * - Automatic expiration of old errors
 * - Safe storage operations (never crash on quota exceeded)
 */
export class StorageQueue {
    constructor(config = {}) {
        this.storageKey = 'app_logger_queue';
        this.maxSize = config.maxSize || 50; // Max errors to store
        this.maxAge = config.maxAge || 86400000; // 24 hours in milliseconds
    }

    /**
     * Add an error to the queue
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
     * Get next error from queue (FIFO)
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
     * Get all queued errors
     */
    getAll() {
        const queue = this.getQueue();
        return queue.map(item => item.payload);
    }

    /**
     * Get queue size
     */
    size() {
        const queue = this.getQueue();
        return queue.length;
    }

    /**
     * Clear the queue
     */
    clear() {
        try {
            localStorage.removeItem(this.storageKey);
        } catch {
            // Ignore
        }
    }

    /**
     * Get queue from localStorage with expiration cleanup
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
     * Save queue to localStorage
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
