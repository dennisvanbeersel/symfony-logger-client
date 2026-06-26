/**
 * Spec 2026-06-25 publishable-key rotation §6.2–§6.5 (Phase 6 / JS SDK).
 *
 * The browser SDK must:
 *  - read config.publishableKey (the world-readable pk_ value), never config.apiKey;
 *  - POST browser errors to /api/v1/js-errors (not /api/v1/errors), with the
 *    X-Publishable-Key header AND a ?pk=<publishableKey> query param;
 *  - send session/replay requests the same way;
 *  - route the unload beacon flush to the recovery-session endpoint with the
 *    publishableKey carried in the BODY (sendBeacon cannot set headers);
 *  - never put a secret (X-Api-Key header or a bare /api/v1/errors ingest) on the wire.
 */
import { Transport } from '../src/transport.js';

const PK = 'pk_test_a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6';
const CONFIG = {
    dsn: 'https://localhost:8111/test-project-id',
    publishableKey: PK,
    debug: false,
};

describe('Transport publishable-key wiring (spec §6.2–§6.5)', () => {
    let fetchCalls;

    beforeEach(() => {
        fetchCalls = [];
        global.fetch = (url, opts) => {
            fetchCalls.push({ url, opts });
            return Promise.resolve({ ok: true, json: () => Promise.resolve({}) });
        };
    });

    test('constructor reads config.publishableKey, not apiKey', () => {
        const t = new Transport({ ...CONFIG });
        expect(t.publishableKey).toBe(PK);
        expect(t.apiKey).toBeUndefined();
    });

    test('browser error endpoint targets js-errors with ?pk=, never /api/v1/errors', () => {
        const t = new Transport({ ...CONFIG });
        expect(t.dsn.endpoint).toBe(
            `https://localhost:8111/api/v1/js-errors?pk=${PK}`,
        );
        expect(t.dsn.endpoint).not.toContain('/api/v1/errors?');
        expect(t.dsn.endpoint).not.toMatch(/\/api\/v1\/errors$/);
    });

    test('recoveryEndpoint targets recovery-session with ?pk=', () => {
        const t = new Transport({ ...CONFIG });
        expect(t.dsn.recoveryEndpoint).toBe(
            `https://localhost:8111/api/v1/errors/recovery-session?pk=${PK}`,
        );
    });

    test('sendToApi sends X-Publishable-Key, never X-Api-Key', async () => {
        const t = new Transport({ ...CONFIG });
        await t.sendToApi({ type: 'Error', message: 'boom' });
        expect(fetchCalls).toHaveLength(1);
        const { url, opts } = fetchCalls[0];
        expect(url).toBe(`https://localhost:8111/api/v1/js-errors?pk=${PK}`);
        expect(opts.headers['X-Publishable-Key']).toBe(PK);
        expect(opts.headers['X-Api-Key']).toBeUndefined();
    });

    test('session + replay requests carry X-Publishable-Key + ?pk=', async () => {
        const t = new Transport({ ...CONFIG });
        await t.sendSessionEvent('sess-1', { type: 'click' });
        await t.sendReplayClicks('sess-1', [{ x: 1, y: 2 }]);
        for (const { url, opts } of fetchCalls) {
            expect(url).toContain(`?pk=${PK}`);
            expect(opts.headers['X-Publishable-Key']).toBe(PK);
            expect(opts.headers['X-Api-Key']).toBeUndefined();
        }
    });

    test('no request anywhere carries an X-Api-Key header', async () => {
        const t = new Transport({ ...CONFIG });
        await t.sendToApi({ message: 'a' });
        await t.sendSessionEvent('s', { type: 'c' });
        await t.sendReplayClicks('s', [{ x: 0 }]);
        await t.sendRecoverySession({ sessionId: 's', events: [] }, false);
        for (const { opts } of fetchCalls) {
            expect(Object.keys(opts.headers)).not.toContain('X-Api-Key');
        }
    });

    /**
     * JS-RUNTIME-01: flushWithBeacon() flushes QUEUED ERRORS (flat payloads), so it
     * must target the js-errors INGEST endpoint and authenticate via the ?pk= query
     * param (sendBeacon cannot set the X-Publishable-Key header). It must NOT target
     * recovery-session (which 400-drops flat error payloads) and must carry no secret.
     */
    test('flushWithBeacon targets js-errors ingest with ?pk=, no secret in body', (done) => {
        const t = new Transport({ ...CONFIG });

        const beaconCalls = [];
        global.navigator = global.navigator || {};
        global.navigator.sendBeacon = (url, blob) => {
            beaconCalls.push({ url, blob });
            return true;
        };

        // Queue an error so there is something to flush.
        t.queue.push({ type: 'Error', message: 'unload error' });

        t.flushWithBeacon();

        expect(beaconCalls).toHaveLength(1);

        // JS-RUNTIME-01: target must be the js-errors ingest endpoint, NOT recovery-session.
        expect(beaconCalls[0].url).toContain('/api/v1/js-errors?pk=');
        expect(beaconCalls[0].url).not.toContain('/recovery-session');
        expect(beaconCalls[0].url).toBe(
            `https://localhost:8111/api/v1/js-errors?pk=${PK}`,
        );

        // Verify body: the flat error payload, no auth/secret injected.
        const reader = new FileReader();
        reader.onload = () => {
            try {
                const payload = JSON.parse(reader.result);
                expect(payload.message).toBe('unload error');
                expect(payload.publishableKey).toBeUndefined();
                expect(payload.apiKey).toBeUndefined();
                expect(Object.keys(payload)).not.toContain('apiKey');
                done();
            } catch (e) {
                done(e);
            }
        };
        reader.onerror = () => done(new Error('Failed to read beacon blob'));
        reader.readAsText(beaconCalls[0].blob);
    });
});
