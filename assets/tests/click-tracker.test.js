/**
 * Unit tests for ClickTracker
 *
 * Tests the click tracking implementation:
 * - Click event capture
 * - CSS selector generation
 * - Click debouncing
 * - Privacy filtering
 * - DOM snapshot integration
 */
import { ClickTracker } from '../src/click-tracker.js';

// Polyfill CSS.escape for jsdom (not available by default)
if (typeof CSS === 'undefined') {
    global.CSS = {
        escape: (str) => str.replace(/([^\w-])/g, '\\$1'),
    };
}

// Manual mock function factory (ESM-compatible)
function createMockFunction(returnValue = undefined) {
    const calls = [];

    const mockFn = function(...args) {
        calls.push(args);
        return typeof returnValue === 'function' ? returnValue(...args) : returnValue;
    };

    mockFn.mock = { calls };
    mockFn.mockClear = () => { calls.length = 0; };

    return mockFn;
}

// Mock ReplayBuffer
class MockReplayBuffer {
    constructor() {
        this.events = [];
        this.addEventMock = createMockFunction(true);
    }
    addEvent(event) {
        this.events.push(event);
        return this.addEventMock(event);
    }
}

// Mock SessionManager
class MockSessionManager {
    constructor(sessionId = 'test-session-123') {
        this._sessionId = sessionId;
    }
    getSessionId() {
        return this._sessionId;
    }
}

describe('ClickTracker', () => {
    let clickTracker;
    let mockReplayBuffer;
    let mockSessionManager;
    let config;

    beforeEach(() => {
        // Mock dependencies
        mockReplayBuffer = new MockReplayBuffer();
        mockSessionManager = new MockSessionManager();

        config = {
            snapshotThrottleMs: 500,
            maxSnapshotSize: 1048576,
            clickDebounceMs: 100, // Short for testing
            debug: false,
        };

        clickTracker = new ClickTracker(
            mockReplayBuffer,
            mockSessionManager,
            config,
        );
    });

    afterEach(() => {
        if (clickTracker) {
            clickTracker.cleanup();
        }
    });

    describe('Constructor', () => {
        test('initializes with correct config values', () => {
            expect(clickTracker.clickDebounceMs).toBe(100);
            expect(clickTracker.isInstalled).toBe(false);
        });

        test('enforces minimum snapshot throttle', () => {
            const tracker = new ClickTracker(
                mockReplayBuffer,
                mockSessionManager,
                { snapshotThrottleMs: 100 }, // Below minimum of 500
            );

            // Should use minimum of 500ms (internal to domSerializer)
            expect(tracker.domSerializer).toBeDefined();
            tracker.cleanup();
        });

        test('enforces minimum click debounce', () => {
            const tracker = new ClickTracker(
                mockReplayBuffer,
                mockSessionManager,
                { clickDebounceMs: 50 }, // Below minimum of 100
            );

            expect(tracker.clickDebounceMs).toBe(100);
            tracker.cleanup();
        });
    });

    describe('install', () => {
        test('sets isInstalled to true', () => {
            clickTracker.install();
            expect(clickTracker.isInstalled).toBe(true);
        });

        test('does not install twice', () => {
            clickTracker.install();
            clickTracker.install();
            expect(clickTracker.isInstalled).toBe(true);
        });
    });

    describe('generateSelector', () => {
        test('generates selector for element with ID', () => {
            const element = document.createElement('button');
            element.id = 'submit-btn';
            document.body.appendChild(element);

            const selector = clickTracker.generateSelector(element);
            expect(selector).toContain('#submit-btn');

            document.body.removeChild(element);
        });

        test('generates selector for element with classes', () => {
            const element = document.createElement('button');
            element.className = 'btn primary';
            document.body.appendChild(element);

            const selector = clickTracker.generateSelector(element);
            expect(selector).toContain('button');
            expect(selector).toContain('.btn');

            document.body.removeChild(element);
        });

        test('generates nested selector for child elements', () => {
            const parent = document.createElement('div');
            parent.id = 'container';
            const child = document.createElement('button');
            parent.appendChild(child);
            document.body.appendChild(parent);

            const selector = clickTracker.generateSelector(child);
            // Should stop at parent ID
            expect(selector).toContain('#container');
            expect(selector).toContain('button');

            document.body.removeChild(parent);
        });

        test('handles document element', () => {
            const selector = clickTracker.generateSelector(document);
            expect(selector).toBe('');
        });

        test('handles null element', () => {
            const selector = clickTracker.generateSelector(null);
            expect(selector).toBe('');
        });

        test('limits selector depth to 5 levels', () => {
            // Create deeply nested structure
            const levels = [];
            let current = document.body;

            for (let i = 0; i < 8; i++) {
                const el = document.createElement('div');
                el.className = `level-${i}`;
                current.appendChild(el);
                levels.push(el);
                current = el;
            }

            const deepest = levels[levels.length - 1];
            const selector = clickTracker.generateSelector(deepest);

            // Should have max 5 levels (not 8)
            const selectorParts = selector.split(' > ');
            expect(selectorParts.length).toBeLessThanOrEqual(5);

            // Cleanup
            document.body.removeChild(levels[0]);
        });
    });

    describe('containsSensitiveData', () => {
        test('detects user-id patterns', () => {
            expect(clickTracker.containsSensitiveData('user-id')).toBe(true);
            expect(clickTracker.containsSensitiveData('userId')).toBe(true);
            expect(clickTracker.containsSensitiveData('user_id')).toBe(true);
        });

        test('detects email patterns', () => {
            expect(clickTracker.containsSensitiveData('email-field')).toBe(true);
            expect(clickTracker.containsSensitiveData('userEmail')).toBe(true);
        });

        test('detects token patterns', () => {
            expect(clickTracker.containsSensitiveData('token-123')).toBe(true);
            expect(clickTracker.containsSensitiveData('authToken')).toBe(true);
        });

        test('detects session patterns', () => {
            expect(clickTracker.containsSensitiveData('session-id')).toBe(true);
            expect(clickTracker.containsSensitiveData('sessionData')).toBe(true);
        });

        test('detects auth patterns', () => {
            expect(clickTracker.containsSensitiveData('auth-form')).toBe(true);
            expect(clickTracker.containsSensitiveData('authenticated')).toBe(true);
        });

        test('detects key patterns', () => {
            expect(clickTracker.containsSensitiveData('api-key')).toBe(true);
            expect(clickTracker.containsSensitiveData('secretKey')).toBe(true);
        });

        test('detects long numbers (potential IDs)', () => {
            expect(clickTracker.containsSensitiveData('item-1234567890')).toBe(true);
            expect(clickTracker.containsSensitiveData('order_9876543210')).toBe(true);
        });

        test('allows safe strings', () => {
            expect(clickTracker.containsSensitiveData('btn-primary')).toBe(false);
            expect(clickTracker.containsSensitiveData('modal-dialog')).toBe(false);
            expect(clickTracker.containsSensitiveData('form-control')).toBe(false);
        });
    });

    describe('getCleanClasses', () => {
        test('filters out utility classes', () => {
            const element = document.createElement('button');
            element.className = 'btn active hover primary';

            const classes = clickTracker.getCleanClasses(element);
            expect(classes).toContain('btn');
            expect(classes).toContain('primary');
            expect(classes).not.toContain('active');
            expect(classes).not.toContain('hover');
        });

        test('filters out generated classes', () => {
            const element = document.createElement('div');
            element.className = 'ng-scope v-cloak data-tooltip _internal container';

            const classes = clickTracker.getCleanClasses(element);
            expect(classes).toContain('container');
            expect(classes).not.toContain('ng-scope');
            expect(classes).not.toContain('v-cloak');
            expect(classes).not.toContain('data-tooltip');
            expect(classes).not.toContain('_internal');
        });

        test('filters out sensitive classes', () => {
            const element = document.createElement('div');
            element.className = 'user-id-field container email-input';

            const classes = clickTracker.getCleanClasses(element);
            expect(classes).toContain('container');
            expect(classes).not.toContain('user-id-field');
            expect(classes).not.toContain('email-input');
        });

        test('limits to 3 classes', () => {
            const element = document.createElement('div');
            element.className = 'one two three four five';

            const classes = clickTracker.getCleanClasses(element);
            expect(classes.length).toBeLessThanOrEqual(3);
        });

        test('handles element without classes', () => {
            const element = document.createElement('div');
            const classes = clickTracker.getCleanClasses(element);
            expect(classes).toEqual([]);
        });
    });

    describe('Click debouncing', () => {
        test('records first click immediately', () => {
            clickTracker.install();

            const event = new MouseEvent('click', {
                bubbles: true,
                clientX: 100,
                clientY: 200,
            });

            document.body.dispatchEvent(event);

            expect(mockReplayBuffer.events.length).toBe(1);
            expect(clickTracker.debounceStats.totalClicks).toBe(1);
            expect(clickTracker.debounceStats.debouncedClicks).toBe(0);
        });

        test('debounces rapid clicks', async () => {
            clickTracker.install();

            // Fire 3 rapid clicks
            for (let i = 0; i < 3; i++) {
                const event = new MouseEvent('click', {
                    bubbles: true,
                    clientX: 100 + i,
                    clientY: 200,
                });
                document.body.dispatchEvent(event);
            }

            // Only first should be captured (others debounced)
            expect(mockReplayBuffer.events.length).toBe(1);
            expect(clickTracker.debounceStats.totalClicks).toBe(3);
            expect(clickTracker.debounceStats.debouncedClicks).toBe(2);
        });

        test('allows clicks after debounce period', async () => {
            clickTracker.install();

            // First click
            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));

            // Wait for debounce period (100ms + buffer)
            await new Promise(resolve => setTimeout(resolve, 150));

            // Second click should be captured
            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));

            expect(mockReplayBuffer.events.length).toBe(2);
        });
    });

    describe('captureClick', () => {
        test('captures click with correct data structure', () => {
            clickTracker.install();

            const button = document.createElement('button');
            button.id = 'test-button';
            document.body.appendChild(button);

            const event = new MouseEvent('click', {
                bubbles: true,
                clientX: 150,
                clientY: 250,
            });
            button.dispatchEvent(event);

            expect(mockReplayBuffer.events.length).toBe(1);

            const captured = mockReplayBuffer.events[0];
            expect(captured.type).toBe('click');
            expect(captured.url).toBeDefined();
            expect(captured.timestamp).toBeDefined();
            expect(captured.clickData).toBeDefined();
            expect(captured.clickData.viewportWidth).toBeDefined();
            expect(captured.clickData.viewportHeight).toBeDefined();
            expect(captured.clickData.elementSelector).toContain('#test-button');
            expect(captured.sessionId).toBe('test-session-123');

            document.body.removeChild(button);
        });

        test('includes session ID from session manager', () => {
            clickTracker.install();

            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));

            const captured = mockReplayBuffer.events[0];
            expect(captured.sessionId).toBe('test-session-123');
        });
    });

    describe('getDOMCaptureStats', () => {
        test('returns initial stats', () => {
            const stats = clickTracker.getDOMCaptureStats();

            expect(stats.total).toBe(0);
            expect(stats.throttled).toBe(0);
            expect(stats.captured).toBe(0);
            expect(stats.errors).toBe(0);
        });

        test('tracks capture attempts after clicks', () => {
            clickTracker.install();
            document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));

            const stats = clickTracker.getDOMCaptureStats();
            expect(stats.total).toBe(1);
        });
    });

    describe('getDebounceStats', () => {
        test('returns initial stats', () => {
            const stats = clickTracker.getDebounceStats();

            expect(stats.totalClicks).toBe(0);
            expect(stats.debouncedClicks).toBe(0);
            expect(stats.debounceRate).toBe('0%');
            expect(stats.clickDebounceMs).toBe(100);
        });

        test('calculates debounce rate correctly', () => {
            clickTracker.install();

            // Fire 4 rapid clicks
            for (let i = 0; i < 4; i++) {
                document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            }

            const stats = clickTracker.getDebounceStats();
            expect(stats.totalClicks).toBe(4);
            expect(stats.debouncedClicks).toBe(3);
            expect(stats.debounceRate).toBe('75.00%');
        });
    });

    describe('cleanup', () => {
        test('does not throw', () => {
            expect(() => {
                clickTracker.cleanup();
            }).not.toThrow();
        });

        test('can be called multiple times', () => {
            expect(() => {
                clickTracker.cleanup();
                clickTracker.cleanup();
            }).not.toThrow();
        });
    });
});
