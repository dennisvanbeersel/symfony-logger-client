/**
 * Regression tests for JS-4 (beacon flush silently dropped queued errors).
 *
 * flushWithBeacon() used to send only the single most-recent error and then
 * clear BOTH the in-memory queue and the localStorage queue, discarding every
 * other pending error even though its docstring claimed they were kept. It also
 * posted to an endpoint that rejects arrays. These tests prove that all queued
 * errors are now either transmitted (one beacon each, bounded) or left queued
 * for next-session retry - never silently lost.
 */
import { Transport } from '../src/transport.js';

class MockStorageQueue {
    constructor(items = []) {
        // Each item is a payload (matches StorageQueue.getAll() return shape).
        this.items = [...items];
        this.cleared = false;
    }
    enqueue(item) {
        this.items.push(item);
    }
    dequeue() {
        return this.items.shift();
    }
    size() {
        return this.items.length;
    }
    getAll() {
        return [...this.items];
    }
    clear() {
        this.cleared = true;
        this.items = [];
    }
}

describe('Transport.flushWithBeacon() no-data-loss (JS-4)', () => {
    let transport;
    let beaconCalls;

    beforeEach(() => {
        global.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) });

        transport = new Transport({
            dsn: 'https://localhost:8111/test-project-id',
            apiKey: 'test-api-key',
            debug: false,
        });

        beaconCalls = [];
        navigator.sendBeacon = (url, blob) => {
            beaconCalls.push({ url, blob });
            return true; // browser accepts
        };
    });

    test('3 queued errors are all transmitted, none silently lost', () => {
        transport.queue = [{ message: 'err-1' }, { message: 'err-2' }];
        transport.storageQueue = new MockStorageQueue([{ message: 'err-3' }]);

        transport.flushWithBeacon();

        // One beacon PER queued error (endpoint rejects arrays).
        expect(beaconCalls).toHaveLength(3);

        // All transmitted items removed from both queues - the old code dropped
        // err-1 and err-2 entirely; now every error is accounted for.
        expect(transport.queue).toHaveLength(0);
        expect(transport.storageQueue.size()).toBe(0);
    });

    test('on partial beacon refusal, untransmitted errors stay queued', () => {
        transport.queue = [{ message: 'a' }, { message: 'b' }];
        transport.storageQueue = new MockStorageQueue([{ message: 'c' }]);

        let n = 0;
        navigator.sendBeacon = () => {
            n++;
            return n === 1; // accept only the first beacon
        };

        transport.flushWithBeacon();

        // Assert WHICH items survive (not just the count): in-memory queue is consumed
        // first, so only 'a' was transmitted; 'b' (in-memory) and 'c' (stored) remain in
        // their original queues, in order — proving no wrong-item drop / no reordering.
        expect(transport.queue).toEqual([{ message: 'b' }]);
        expect(transport.storageQueue.getAll()).toEqual([{ message: 'c' }]);
    });

    test('if the browser refuses the very first beacon, queues are untouched', () => {
        transport.queue = [{ message: 'x' }];
        const sq = new MockStorageQueue([{ message: 'y' }]);
        transport.storageQueue = sq;

        navigator.sendBeacon = () => false;

        transport.flushWithBeacon();

        expect(transport.queue).toHaveLength(1);
        expect(transport.storageQueue.size()).toBe(1);
        expect(sq.cleared).toBe(false); // never blanket-cleared
    });

    test('empty queues are a no-op (no beacon, no throw)', () => {
        transport.queue = [];
        transport.storageQueue = new MockStorageQueue([]);

        expect(() => transport.flushWithBeacon()).not.toThrow();
        expect(beaconCalls).toHaveLength(0);
    });
});
