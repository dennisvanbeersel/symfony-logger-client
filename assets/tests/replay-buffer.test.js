/**
 * Unit tests for ReplayBuffer
 *
 * Tests circular buffer, time/click-based limits, phase marking,
 * error-triggered recording, and serialization.
 */

import { ReplayBuffer } from '../src/replay-buffer.js';

describe('ReplayBuffer', () => {
    let buffer;

    beforeEach(() => {
        buffer = new ReplayBuffer({
            bufferBeforeErrorSeconds: 30,
            bufferBeforeErrorClicks: 10,
            bufferAfterErrorSeconds: 30,
            bufferAfterErrorClicks: 10,
            maxBufferSizeMB: 5,
            debug: false,
        });
    });

    describe('Constructor', () => {
        test('enforces hard caps on configuration', () => {
            const overLimit = new ReplayBuffer({
                bufferBeforeErrorSeconds: 100, // Max 60
                bufferBeforeErrorClicks: 20,   // Max 15
                bufferAfterErrorSeconds: 100,  // Max 60
                bufferAfterErrorClicks: 20,    // Max 15
                maxBufferSizeMB: 50,           // Max 20
            });

            expect(overLimit.config.bufferBeforeErrorSeconds).toBe(60);
            expect(overLimit.config.bufferBeforeErrorClicks).toBe(15);
            expect(overLimit.config.bufferAfterErrorSeconds).toBe(60);
            expect(overLimit.config.bufferAfterErrorClicks).toBe(15);
            expect(overLimit.config.maxBufferSizeMB).toBe(20);
        });

        test('uses default values when not provided', () => {
            const defaultBuffer = new ReplayBuffer({});

            expect(defaultBuffer.config.bufferBeforeErrorSeconds).toBe(30);
            expect(defaultBuffer.config.bufferBeforeErrorClicks).toBe(10);
            expect(defaultBuffer.config.bufferAfterErrorSeconds).toBe(30);
            expect(defaultBuffer.config.bufferAfterErrorClicks).toBe(10);
            expect(defaultBuffer.config.maxBufferSizeMB).toBe(5);
        });
    });

    describe('addEvent', () => {
        test('adds event with phase=before_error by default', () => {
            const event = {
                type: 'click',
                timestamp: Date.now(),
                url: 'https://example.com',
            };

            const added = buffer.addEvent(event);

            expect(added).toBe(true);
            const events = buffer.getEvents();
            expect(events).toHaveLength(1);
            expect(events[0].phase).toBe('before_error');
            expect(events[0].capturedAt).toBeDefined();
        });

        test('rejects invalid events without timestamp', () => {
            const invalidEvent = {
                type: 'click',
                url: 'https://example.com',
                // Missing timestamp
            };

            const added = buffer.addEvent(invalidEvent);

            expect(added).toBe(false);
            expect(buffer.getEvents()).toHaveLength(0);
        });

        test('marks events as after_error when recording after error', () => {
            buffer.startRecordingAfterError({
                timestamp: Date.now(),
                message: 'Test error',
            });

            const event = {
                type: 'click',
                timestamp: Date.now(),
                url: 'https://example.com',
            };

            buffer.addEvent(event);

            const events = buffer.getEvents();
            // Should have error marker + new event
            expect(events.length).toBeGreaterThanOrEqual(2);
            expect(events[events.length - 1].phase).toBe('after_error');
        });
    });

    describe('Circular buffer behavior (before error)', () => {
        test('prunes old events beyond time limit', () => {
            const now = Date.now();

            // Add old event (35 seconds ago, beyond 30s limit)
            const oldEvent = {
                type: 'click',
                timestamp: now - 35000,
                url: 'https://example.com',
                capturedAt: now - 35000,
            };
            buffer.buffer.push(oldEvent);

            // Add recent event
            const recentEvent = {
                type: 'click',
                timestamp: now,
                url: 'https://example.com',
            };
            buffer.addEvent(recentEvent);

            const events = buffer.getEvents();
            expect(events).toHaveLength(1);
            expect(events[0].timestamp).toBe(now);
        });

        test('prunes old clicks beyond click limit', () => {
            // Add 15 click events
            for (let i = 0; i < 15; i++) {
                buffer.addEvent({
                    type: 'click',
                    timestamp: Date.now() + i,
                    url: 'https://example.com',
                });
            }

            const events = buffer.getEvents();
            const clickEvents = events.filter(e => e.type === 'click');
            // Should keep only last 10 clicks (bufferBeforeErrorClicks)
            expect(clickEvents.length).toBeLessThanOrEqual(10);
        });

        test('keeps non-click events even when click limit exceeded', () => {
            // Add 15 click events
            for (let i = 0; i < 15; i++) {
                buffer.addEvent({
                    type: 'click',
                    timestamp: Date.now() + i,
                    url: 'https://example.com',
                });
            }

            // Add page transition
            buffer.addEvent({
                type: 'pageTransition',
                timestamp: Date.now(),
                url: 'https://example.com/page2',
            });

            const events = buffer.getEvents();
            const pageEvents = events.filter(e => e.type === 'pageTransition');
            expect(pageEvents).toHaveLength(1);
        });
    });

    describe('Error-triggered recording', () => {
        test('startRecordingAfterError adds error marker and sets flag', () => {
            const errorContext = {
                timestamp: Date.now(),
                message: 'TypeError: Cannot read property',
                errorId: 'test-123',
            };

            buffer.startRecordingAfterError(errorContext);

            expect(buffer.isRecording()).toBe(true);
            expect(buffer.errorOccurredAt).toBe(errorContext.timestamp);
            expect(buffer.postErrorEventCount).toBe(0);

            const events = buffer.getEvents();
            const errorEvent = events.find(e => e.phase === 'error');
            expect(errorEvent).toBeDefined();
            expect(errorEvent.errorContext).toEqual(errorContext);
        });

        test('stops recording after time limit', () => {
            const errorTime = Date.now();
            buffer.startRecordingAfterError({
                timestamp: errorTime,
                message: 'Test error',
            });

            // Simulate time passing beyond limit
            buffer.errorOccurredAt = errorTime - 35000; // 35 seconds ago

            expect(buffer.shouldStopRecording()).toBe(true);
        });

        test('stops recording after click limit', () => {
            buffer.startRecordingAfterError({
                timestamp: Date.now(),
                message: 'Test error',
            });

            // Add 10 clicks (at limit)
            for (let i = 0; i < 10; i++) {
                buffer.addEvent({
                    type: 'click',
                    timestamp: Date.now() + i,
                    url: 'https://example.com',
                });
            }

            // Recording should have stopped automatically when limit was reached
            expect(buffer.isRecording()).toBe(false);
            expect(buffer.postErrorEventCount).toBe(10);
        });

        test('stopRecording clears the flag', () => {
            buffer.startRecordingAfterError({
                timestamp: Date.now(),
                message: 'Test error',
            });

            expect(buffer.isRecording()).toBe(true);

            buffer.stopRecording();

            expect(buffer.isRecording()).toBe(false);
        });
    });

    describe('Serialization', () => {
        test('serialize returns complete buffer state', () => {
            buffer.addEvent({
                type: 'click',
                timestamp: Date.now(),
                url: 'https://example.com',
            });

            buffer.startRecordingAfterError({
                timestamp: Date.now(),
                message: 'Test error',
            });

            const serialized = buffer.serialize();

            expect(serialized).toHaveProperty('buffer');
            expect(serialized).toHaveProperty('isRecordingAfterError');
            expect(serialized).toHaveProperty('errorOccurredAt');
            expect(serialized).toHaveProperty('postErrorEventCount');
            expect(serialized).toHaveProperty('stats');
            expect(serialized.isRecordingAfterError).toBe(true);
        });

        test('deserialize restores buffer state', () => {
            const events = [
                {
                    type: 'click',
                    timestamp: Date.now(),
                    phase: 'before_error',
                },
            ];

            const data = {
                buffer: events,
                isRecordingAfterError: true,
                errorOccurredAt: Date.now(),
                postErrorEventCount: 5,
                stats: {
                    totalEvents: 10,
                    eventsDropped: 2,
                },
            };

            const success = buffer.deserialize(data);

            expect(success).toBe(true);
            expect(buffer.getEvents()).toHaveLength(1);
            expect(buffer.isRecording()).toBe(true);
            expect(buffer.postErrorEventCount).toBe(5);
        });

        test('deserialize handles invalid data gracefully', () => {
            const success = buffer.deserialize(null);

            expect(success).toBe(false);
            expect(buffer.getEvents()).toHaveLength(0);
        });
    });

    describe('Statistics', () => {
        test('tracks total events and buffer length', () => {
            buffer.addEvent({ type: 'click', timestamp: Date.now() });
            buffer.addEvent({ type: 'click', timestamp: Date.now() });

            const stats = buffer.getStats();

            expect(stats.totalEvents).toBe(2);
            expect(stats.bufferLength).toBe(2);
            expect(stats.isRecording).toBe(false);
        });

        test('tracks post-error event count when recording', () => {
            buffer.startRecordingAfterError({
                timestamp: Date.now(),
                message: 'Test error',
            });

            buffer.addEvent({ type: 'click', timestamp: Date.now() });
            buffer.addEvent({ type: 'click', timestamp: Date.now() });

            const stats = buffer.getStats();

            expect(stats.postErrorEventCount).toBe(2);
            expect(stats.isRecording).toBe(true);
        });
    });

    describe('Buffer size management', () => {
        test('aggressive pruning when buffer size exceeds limit', () => {
            // Create many large events
            for (let i = 0; i < 100; i++) {
                buffer.addEvent({
                    type: 'click',
                    timestamp: Date.now() + i,
                    url: 'https://example.com',
                    largeData: 'x'.repeat(1024), // 1KB each
                });
            }

            // updateStats() should trigger pruning if size exceeds limit
            buffer.updateStats();

            // Buffer should be pruned but not empty
            expect(buffer.getEvents().length).toBeGreaterThan(0);
            expect(buffer.getEvents().length).toBeLessThan(100);
        });
    });

    describe('Edge cases', () => {
        test('handles clear() correctly', () => {
            buffer.addEvent({ type: 'click', timestamp: Date.now() });
            buffer.startRecordingAfterError({ timestamp: Date.now(), message: 'Error' });

            buffer.clear();

            expect(buffer.getEvents()).toHaveLength(0);
            expect(buffer.isRecording()).toBe(false);
            expect(buffer.errorOccurredAt).toBeNull();
            expect(buffer.postErrorEventCount).toBe(0);
        });

        test('getEventsByPhase filters correctly', () => {
            buffer.addEvent({ type: 'click', timestamp: Date.now() });
            buffer.startRecordingAfterError({ timestamp: Date.now(), message: 'Error' });
            buffer.addEvent({ type: 'click', timestamp: Date.now() });

            const beforeError = buffer.getEventsByPhase('before_error');
            const error = buffer.getEventsByPhase('error');
            const afterError = buffer.getEventsByPhase('after_error');

            expect(beforeError.length).toBeGreaterThan(0);
            expect(error.length).toBeGreaterThan(0);
            expect(afterError.length).toBeGreaterThan(0);
        });
    });
});
