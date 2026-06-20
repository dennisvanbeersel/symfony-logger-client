/**
 * Supplementary unit tests for ErrorDetector covering the two-phase recovery
 * recording flow (startRecoveryRecording / sendRecoverySession) and the
 * enable/disable toggles. These paths are not exercised by error-detector.test.js.
 *
 * Uses lightweight mocks + Jest fake timers so no real time elapses.
 */
import { jest } from '@jest/globals';
import { ErrorDetector } from '../src/error-detector.js';

class MockReplayBuffer {
    constructor() {
        this.afterErrorStarted = [];
        this.afterErrorEvents = [];
        this.stopRecording = false;
    }
    startRecordingAfterError(ctx) {
        this.afterErrorStarted.push(ctx);
    }
    getEvents() {
        return [];
    }
    getEventsByPhase(phase) {
        return phase === 'after_error' ? this.afterErrorEvents : [];
    }
    getStats() {
        return { eventCount: 0 };
    }
    shouldStopRecording() {
        return this.stopRecording;
    }
}

class MockSessionManager {
    getSessionId() {
        return 'session-xyz';
    }
}

class MockTransport {
    constructor() {
        this.recoveryCalls = [];
    }
    sendRecoverySession(payload, useBeacon) {
        this.recoveryCalls.push({ payload, useBeacon });
        return Promise.resolve({ ok: true });
    }
}

describe('ErrorDetector - recovery recording', () => {
    let buffer;
    let session;
    let transport;
    let callbackCalls;
    let detector;

    beforeEach(() => {
        buffer = new MockReplayBuffer();
        session = new MockSessionManager();
        transport = new MockTransport();
        callbackCalls = [];
        detector = new ErrorDetector(
            buffer,
            session,
            (ctx, events, payload) => { callbackCalls.push({ ctx, events, payload }); },
            transport,
            { debug: false },
        );
    });

    describe('setEnabled / isEnabled', () => {
        test('enables and disables detection', () => {
            detector.setEnabled(true);
            expect(detector.isEnabled()).toBe(true);
            detector.setEnabled(false);
            expect(detector.isEnabled()).toBe(false);
        });
    });

    describe('sendRecoverySession', () => {
        test('does nothing when there are no events', () => {
            detector.sendRecoverySession({}, [], false);
            expect(transport.recoveryCalls).toHaveLength(0);
        });

        test('skips sending when there are no click events', () => {
            detector.sendRecoverySession({}, [{ type: 'scroll' }], false);
            expect(transport.recoveryCalls).toHaveLength(0);
        });

        test('sends via transport when click events exist (beacon flag passed through)', () => {
            detector.sendRecoverySession({ message: 'e' }, [{ type: 'click' }], true);
            expect(transport.recoveryCalls).toHaveLength(1);
            expect(transport.recoveryCalls[0].useBeacon).toBe(true);
            expect(transport.recoveryCalls[0].payload.sessionId).toBe('session-xyz');
            expect(transport.recoveryCalls[0].payload.url).toBeDefined();
        });

        test('falls back to the callback when no transport is configured', () => {
            const noTransport = new ErrorDetector(
                buffer, session,
                (ctx, events, payload) => { callbackCalls.push({ ctx, events, payload }); },
                null, {},
            );
            noTransport.sendRecoverySession({ message: 'e' }, [{ type: 'click' }], false);
            expect(callbackCalls).toHaveLength(1);
            expect(callbackCalls[0].payload).toEqual({ recovery: true });
        });

        test('never throws when transport.sendRecoverySession rejects', () => {
            transport.sendRecoverySession = () => Promise.reject(new Error('net'));
            expect(() =>
                detector.sendRecoverySession({ message: 'e' }, [{ type: 'click' }], false),
            ).not.toThrow();
        });
    });

    describe('startRecoveryRecording', () => {
        beforeEach(() => {
            jest.useFakeTimers();
        });

        afterEach(() => {
            jest.runOnlyPendingTimers();
            jest.useRealTimers();
        });

        test('returns early for an invalid error object', async () => {
            await detector.startRecoveryRecording(null);
            expect(detector.isRecordingRecovery).toBe(false);
            expect(buffer.afterErrorStarted).toHaveLength(0);
        });

        test('returns early when dependencies are missing', async () => {
            const broken = new ErrorDetector(null, null, () => {}, transport, {});
            await broken.startRecoveryRecording(new Error('x'));
            expect(broken.isRecordingRecovery).toBe(false);
        });

        test('records and finishes on limit-reached, sending recovery session', async () => {
            buffer.afterErrorEvents = [{ type: 'click' }];

            const promise = detector.startRecoveryRecording(new Error('recover me'));

            expect(detector.isRecordingRecovery).toBe(true);
            expect(buffer.afterErrorStarted).toHaveLength(1);
            expect(detector.stats.recoveryRecordingsStarted).toBe(1);

            // Tell the buffer to stop, then run the 1s completion check
            buffer.stopRecording = true;
            jest.advanceTimersByTime(1000);

            await promise;

            expect(detector.isRecordingRecovery).toBe(false);
            expect(transport.recoveryCalls).toHaveLength(1);
        });

        test('finishes with no send when there are no recovery events', async () => {
            buffer.afterErrorEvents = [];

            const promise = detector.startRecoveryRecording(new Error('empty'));
            buffer.stopRecording = true;
            jest.advanceTimersByTime(1000);
            await promise;

            expect(detector.isRecordingRecovery).toBe(false);
            expect(transport.recoveryCalls).toHaveLength(0);
        });

        test('cancels a previous in-flight recording when a new one starts', async () => {
            buffer.afterErrorEvents = [{ type: 'click' }];

            // First recording stays in flight
            detector.startRecoveryRecording(new Error('first'));
            expect(detector.isRecordingRecovery).toBe(true);

            // Second recording cancels the first
            const second = detector.startRecoveryRecording(new Error('second'));
            expect(detector.stats.recoveryRecordingsCancelled).toBe(1);

            buffer.stopRecording = true;
            jest.advanceTimersByTime(1000);
            await second;

            expect(detector.isRecordingRecovery).toBe(false);
        });

        test('force-finishes via the 2-minute safety timeout', async () => {
            buffer.afterErrorEvents = [];

            const promise = detector.startRecoveryRecording(new Error('stuck'));
            jest.advanceTimersByTime(120000);
            await promise;

            expect(detector.isRecordingRecovery).toBe(false);
        });

        test('finishes when the buffer loses its shouldStopRecording method', async () => {
            const promise = detector.startRecoveryRecording(new Error('degraded'));
            // Simulate buffer becoming unavailable mid-recording
            buffer.shouldStopRecording = undefined;
            jest.advanceTimersByTime(1000);
            await promise;

            expect(detector.isRecordingRecovery).toBe(false);
        });

        // BUNDLE-REPLAY-OPTOUT: opting out (uninstall()/disable()) mid-recording
        // must fully stop replay — the in-flight recovery must NEVER send.
        describe('opt-out mid-flight aborts the recovery send', () => {
            // NOTE: opting out mid-flight runs cleanup() (clearing every timer) but
            // intentionally never resolves the in-flight startRecoveryRecording promise
            // — the recording is aborted, not "finished". So we must NOT await that
            // promise (it would hang). We assert on the observable contract instead:
            // after opt-out + advancing all timers, NO recovery session is ever sent.
            test('uninstall() before the finish timer cancels the in-flight send', () => {
                buffer.afterErrorEvents = [{ type: 'click' }];

                // Recording is in flight (finish timer has NOT fired yet).
                detector.startRecoveryRecording(new Error('opt out me'));
                expect(detector.isRecordingRecovery).toBe(true);

                // Opt out mid-flight, BEFORE the 1s completion check runs.
                detector.uninstall();

                // uninstall() aborts the in-flight recording immediately: it runs the
                // stored cleanup (clearing the timer/listeners) and resets state, so the
                // finish path can never fire a send afterwards.
                expect(detector.isRecordingRecovery).toBe(false);
                expect(detector.uninstalled).toBe(true);

                // Advance well past the completion check AND the safety timeout to prove
                // no deferred timer revives the send.
                buffer.stopRecording = true;
                jest.advanceTimersByTime(120000);

                expect(transport.recoveryCalls).toHaveLength(0);
                expect(callbackCalls).toHaveLength(0);
            });

            test('disable() before the finish timer cancels the in-flight send', () => {
                buffer.afterErrorEvents = [{ type: 'click' }];

                // install() so setEnabled(false) actually triggers uninstall().
                detector.install();

                detector.startRecoveryRecording(new Error('disable me'));
                expect(detector.isRecordingRecovery).toBe(true);

                // Opt out via the public enable/disable toggle, mid-flight.
                detector.setEnabled(false);

                expect(detector.isRecordingRecovery).toBe(false);
                expect(detector.uninstalled).toBe(true);

                buffer.stopRecording = true;
                jest.advanceTimersByTime(120000);

                expect(transport.recoveryCalls).toHaveLength(0);
                expect(callbackCalls).toHaveLength(0);
            });

            test('opt-out after the finish timer fires still blocks the send (belt guard)', async () => {
                buffer.afterErrorEvents = [{ type: 'click' }];

                // Detach the stored cleanup so uninstall() cannot abort via cleanup();
                // this isolates the finishRecording() `uninstalled` guard (the "belt"
                // covering a recording already past its timer at opt-out time).
                const promise = detector.startRecoveryRecording(new Error('belt'));
                expect(detector.isRecordingRecovery).toBe(true);

                detector.recoveryRecordingCleanup = null;
                detector.uninstalled = true;

                // Now let the completion check fire; finishRecording() must abort BEFORE
                // collecting events or sending.
                buffer.stopRecording = true;
                jest.advanceTimersByTime(1000);
                await promise;

                expect(transport.recoveryCalls).toHaveLength(0);
                expect(callbackCalls).toHaveLength(0);
            });
        });
    });
});
