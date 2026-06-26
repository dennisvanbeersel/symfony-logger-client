/**
 * Spec 2026-06-25 §6.5 (MF-5): a cross-origin browser ingest can fail with an
 * opaque "Failed to fetch" TypeError that masks a CORS rejection. Retrying it is
 * pure busy-wait and re-enqueuing it lets flushStoredErrors re-feed an
 * un-retryable payload forever. Fast-fail: one circuit-breaker failure, no
 * retry, no enqueue, honest copy.
 */
import { jest } from '@jest/globals';
import { Transport } from '../src/transport.js';

const PK = 'pk_test_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6';

describe('Transport CORS/network fast-fail (spec §6.5 MF-5)', () => {
    let transport;

    beforeEach(() => {
        transport = new Transport({
            dsn: 'https://localhost:8111/test-project-id',
            publishableKey: PK,
            debug: false,
        });
    });

    test('an opaque fetch TypeError is not retried and not enqueued', async () => {
        const fetchSpy = jest.fn(() => Promise.reject(new TypeError('Failed to fetch')));
        global.fetch = fetchSpy;
        const enqueueSpy = jest.spyOn(transport.storageQueue, 'enqueue');
        const cbFailSpy = jest.spyOn(transport.circuitBreaker, 'recordFailure');

        await transport.sendToApi({ type: 'Error', message: 'boom' });

        // No backoff retry loop: exactly ONE fetch attempt.
        expect(fetchSpy).toHaveBeenCalledTimes(1);
        // Un-retryable: never stored (so flushStoredErrors can't re-feed it).
        expect(enqueueSpy).not.toHaveBeenCalled();
        // Still counts as one breaker failure so a persistent CORS misconfig opens it.
        expect(cbFailSpy).toHaveBeenCalledTimes(1);
    });

    test('honest CORS/network copy is logged', async () => {
        global.fetch = () => Promise.reject(new TypeError('Failed to fetch'));
        const errSpy = jest.spyOn(transport.logger, 'error');

        await transport.sendToApi({ message: 'x' });

        const logged = errSpy.mock.calls.map(c => String(c[0])).join(' | ');
        expect(logged).toMatch(/possible CORS or network failure/i);
    });

    test('a normal network error still retries (regression guard)', async () => {
        let n = 0;
        global.fetch = jest.fn(() => { n++; return Promise.reject(new Error('ECONNRESET')); });
        jest.spyOn(transport, 'delay').mockResolvedValue(undefined);

        await transport.sendToApi({ message: 'retry-me' });

        // attempt 0,1,2 → 3 fetch calls (existing exponential-backoff behaviour).
        expect(n).toBe(3);
    });
});
