/**
 * JS-RUNTIME-01: the unload beacon flush of QUEUED ERRORS must target the
 * js-errors INGEST endpoint (this.dsn.endpoint), NOT the recovery-session
 * endpoint. Queued items are flat error payloads ({type, message, …});
 * recovery-session validates {sessionId, events[]} and 400-drops anything else,
 * so beaconing flat errors there silently lost them on page unload.
 *
 * The ingest endpoint carries ?pk=<publishableKey> in the query, which resolves
 * the project without the X-Publishable-Key header sendBeacon cannot set — so no
 * secret is ever sent, and no auth field needs to be injected into the body.
 */
import { Transport } from '../src/transport.js';

const PK = 'pk_test_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6';

class MockStorageQueue {
    constructor(items = []) { this.items = [...items]; }
    enqueue(i) { this.items.push(i); }
    dequeue() { return this.items.shift(); }
    size() { return this.items.length; }
    getAll() { return [...this.items]; }
}

describe('flushWithBeacon targets js-errors ingest with ?pk= (JS-RUNTIME-01)', () => {
    let beaconCalls;
    let transport;

    beforeEach(() => {
        global.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
        transport = new Transport({
            dsn: 'https://localhost:8111/test-project-id',
            publishableKey: PK,
            debug: false,
        });
        beaconCalls = [];
        navigator.sendBeacon = (url, blob) => {
            beaconCalls.push({ url, blob });
            return true;
        };
    });

    // Jest's jsdom environment does not implement blob.text() — use FileReader
    // (the same pattern as transport-publishable-key.test.js line 116).
    function readBlob(blob) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(JSON.parse(reader.result));
            reader.onerror = () => reject(new Error('Failed to read beacon blob'));
            reader.readAsText(blob);
        });
    }

    test('beacon targets the js-errors ingest endpoint, NOT recovery-session', () => {
        transport.queue = [{ message: 'err-1' }];
        transport.storageQueue = new MockStorageQueue([]);

        transport.flushWithBeacon();

        expect(beaconCalls).toHaveLength(1);
        expect(beaconCalls[0].url).toBe(transport.dsn.endpoint);
        expect(beaconCalls[0].url).toContain('/api/v1/js-errors');
        // Must never go to recovery-session (which 400-drops flat error payloads).
        expect(beaconCalls[0].url).not.toContain('/recovery-session');
    });

    test('beacon URL carries the publishable key as ?pk= (sendBeacon cannot set headers)', () => {
        transport.queue = [{ message: 'err-1' }];
        transport.storageQueue = new MockStorageQueue([]);

        transport.flushWithBeacon();

        expect(beaconCalls[0].url).toContain(`pk=${encodeURIComponent(PK)}`);
    });

    test('beacon body is the flat error payload, with no secret injected', async () => {
        transport.queue = [{ type: 'Error', message: 'err-1', stack_trace: ['frame'] }];
        transport.storageQueue = new MockStorageQueue([]);

        transport.flushWithBeacon();

        const body = await readBlob(beaconCalls[0].blob);
        // Flat error payload is transmitted as-is (js-errors ingest accepts it).
        expect(body.message).toBe('err-1');
        expect(body.type).toBe('Error');
        // No secret of any kind in the body.
        expect(body.apiKey).toBeUndefined();
        const serialized = JSON.stringify(body);
        expect(serialized).not.toContain('X-Api-Key');
    });
});
