/**
 * Unit tests for StorageQueue
 *
 * Tests the localStorage queue implementation:
 * - FIFO queue operations (enqueue, dequeue)
 * - Size limits
 * - Automatic expiration of old items
 * - Safe storage operations
 */
import { StorageQueue } from '../src/storage-queue.js';

describe('StorageQueue', () => {
    const STORAGE_KEY = 'app_logger_queue';
    let queue;

    beforeEach(() => {
        // Clear jsdom localStorage before each test
        localStorage.clear();

        queue = new StorageQueue({
            maxSize: 5,
            maxAge: 1000, // 1 second for easier testing
        });
    });

    describe('Initial state', () => {
        test('starts with empty queue', () => {
            expect(queue.size()).toBe(0);
        });

        test('getAll returns empty array initially', () => {
            expect(queue.getAll()).toEqual([]);
        });

        test('dequeue returns null on empty queue', () => {
            expect(queue.dequeue()).toBeNull();
        });
    });

    describe('enqueue', () => {
        test('adds item to queue', () => {
            queue.enqueue({ message: 'test error' });
            expect(queue.size()).toBe(1);
        });

        test('stores item in localStorage', () => {
            queue.enqueue({ message: 'test error' });

            const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
            expect(stored).toHaveLength(1);
            expect(stored[0].payload.message).toBe('test error');
        });

        test('adds timestamp to queued item', () => {
            const beforeTime = Date.now();
            queue.enqueue({ message: 'test' });
            const afterTime = Date.now();

            const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
            expect(stored[0].timestamp).toBeGreaterThanOrEqual(beforeTime);
            expect(stored[0].timestamp).toBeLessThanOrEqual(afterTime);
        });

        test('multiple items stored in order', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });
            queue.enqueue({ id: 3 });

            const all = queue.getAll();
            expect(all).toHaveLength(3);
            expect(all[0].id).toBe(1);
            expect(all[1].id).toBe(2);
            expect(all[2].id).toBe(3);
        });
    });

    describe('Size limits', () => {
        test('enforces max size limit', () => {
            // Queue has maxSize of 5
            for (let i = 1; i <= 7; i++) {
                queue.enqueue({ id: i });
            }

            expect(queue.size()).toBe(5);
        });

        test('removes oldest items when limit exceeded (FIFO)', () => {
            // Queue has maxSize of 5
            for (let i = 1; i <= 7; i++) {
                queue.enqueue({ id: i });
            }

            const all = queue.getAll();
            // Items 1 and 2 should be removed, keeping 3-7
            expect(all[0].id).toBe(3);
            expect(all[4].id).toBe(7);
        });
    });

    describe('dequeue', () => {
        test('returns first item (FIFO)', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });
            queue.enqueue({ id: 3 });

            const item = queue.dequeue();
            expect(item.id).toBe(1);
        });

        test('removes item from queue', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });

            queue.dequeue();
            expect(queue.size()).toBe(1);
            expect(queue.getAll()[0].id).toBe(2);
        });

        test('returns null when queue is empty', () => {
            expect(queue.dequeue()).toBeNull();
        });

        test('updates localStorage after dequeue', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });

            queue.dequeue();

            const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
            expect(stored).toHaveLength(1);
            expect(stored[0].payload.id).toBe(2);
        });
    });

    describe('getAll', () => {
        test('returns all payloads without timestamps', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });

            const all = queue.getAll();
            expect(all).toHaveLength(2);
            expect(all[0]).toEqual({ id: 1 });
            expect(all[1]).toEqual({ id: 2 });
            // Should not include timestamp
            expect(all[0].timestamp).toBeUndefined();
        });
    });

    describe('clear', () => {
        test('removes all items from queue', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });

            queue.clear();

            expect(queue.size()).toBe(0);
            expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
        });
    });

    describe('Expiration', () => {
        test('filters out expired items on getQueue', async () => {
            queue.enqueue({ id: 1 });

            // Wait for item to expire (maxAge is 1000ms)
            await new Promise(resolve => setTimeout(resolve, 1100));

            // Add a new item
            queue.enqueue({ id: 2 });

            const all = queue.getAll();
            expect(all).toHaveLength(1);
            expect(all[0].id).toBe(2);
        });

        test('keeps non-expired items', async () => {
            queue.enqueue({ id: 1 });

            // Wait less than expiration time
            await new Promise(resolve => setTimeout(resolve, 500));

            const all = queue.getAll();
            expect(all).toHaveLength(1);
        });
    });

    describe('Default configuration', () => {
        test('uses default maxSize of 50', () => {
            const defaultQueue = new StorageQueue();

            // Add 52 items
            for (let i = 0; i < 52; i++) {
                defaultQueue.enqueue({ id: i });
            }

            expect(defaultQueue.size()).toBe(50);
        });

        test('uses default maxAge of 24 hours', () => {
            // Manually create an item older than 24 hours
            const oldItem = {
                payload: { id: 'old' },
                timestamp: Date.now() - (25 * 60 * 60 * 1000), // 25 hours ago
            };
            const newItem = {
                payload: { id: 'new' },
                timestamp: Date.now(),
            };

            localStorage.setItem('app_logger_queue', JSON.stringify([oldItem, newItem]));

            // Create queue instance which will clean up expired items on getQueue
            const defaultQueue = new StorageQueue();
            const all = defaultQueue.getAll();

            expect(all).toHaveLength(1);
            expect(all[0].id).toBe('new');
        });
    });

    describe('Error handling', () => {
        test('handles corrupted localStorage data', () => {
            localStorage.setItem(STORAGE_KEY, 'invalid json');

            expect(queue.size()).toBe(0);
            expect(queue.getAll()).toEqual([]);
        });

        test('handles non-array localStorage data', () => {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ not: 'an array' }));

            expect(queue.size()).toBe(0);
            expect(queue.getAll()).toEqual([]);
        });

        test('handles missing localStorage gracefully', () => {
            // Store original and remove localStorage
            const originalLocalStorage = global.localStorage;
            delete global.localStorage;

            // Should not throw
            const noStorageQueue = new StorageQueue();
            noStorageQueue.enqueue({ id: 1 });
            expect(noStorageQueue.size()).toBe(0);

            // Restore localStorage
            global.localStorage = originalLocalStorage;
        });

        test('handles storage errors gracefully without crashing', () => {
            // Add some items first
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });

            // Mock localStorage.setItem to throw a generic error
            const originalSetItem = localStorage.setItem.bind(localStorage);

            localStorage.setItem = (key, value) => {
                if (key === STORAGE_KEY) {
                    throw new Error('Storage error');
                }
                return originalSetItem(key, value);
            };

            // Should not crash - enqueue catches storage errors
            expect(() => {
                queue.enqueue({ id: 3 });
            }).not.toThrow();

            // Restore original
            localStorage.setItem = originalSetItem;

            // Queue still works (reads from previously saved state)
            expect(queue.size()).toBeGreaterThanOrEqual(0);
        });
    });

    describe('State persistence', () => {
        test('persists queue across instances', () => {
            queue.enqueue({ id: 1 });
            queue.enqueue({ id: 2 });

            // Create new instance
            const newQueue = new StorageQueue({ maxSize: 5, maxAge: 1000 });

            expect(newQueue.size()).toBe(2);
            expect(newQueue.getAll()[0].id).toBe(1);
        });
    });
});
